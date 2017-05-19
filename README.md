# Hubspot TYPO3 imports

Import blog entries from a HubSpot COS export into TYPO3 news items.

## Usage

### Analyze / prepare import
```
$> php bin/typo3 hubspot_import:blog:analyze --help

Analyze what will be processed

COMMAND:
  hubspot_import:blog:analyze

USAGE:
  /opt/phpfarm/inst/bin/php-7.0 bin/typo3 blog:analyze [<options>] <config>

ARGUMENTS:
  --config             Name of the configuration to use

OPTIONS:
  --export-path        The path to the directory of the blog sites (HubSport
                       COS export)
```

### Import
```
$> php bin/typo3 hubspot_import:blog:import --help
   
   Import a blog into news
   
   COMMAND:
     hubspot_import:blog:import
   
   USAGE:
     /opt/phpfarm/inst/bin/php-7.0 bin/typo3 blog:import [<options>] <config>
   
   ARGUMENTS:
     --config             Name of the configuration to use
   
   OPTIONS:
     --export-path        The path to the directory of the blog sites (HubSport
                          COS export)
```

### Import comments
```
$> php bin/typo3 hubspot_import:blog:comments --help
   
   Update comments
   
   COMMAND:
     hubspot_import:blog:comments
   
   USAGE:
     /opt/phpfarm/inst/bin/php-7.0 bin/typo3 blog:comments [<options>] <config>
   
   ARGUMENTS:
     --config             Name of the configuration to use
   
   OPTIONS:
     --dry-run            Whether to only show records to be imported
```


## Configuration

Place a file named HubSpotImport.php in typo3conf and add some configuration to it:

```php
<?php
# typo3conf/HubSpotImport.php
return [
    // Configuration name (used in command line)
    'example' => [
        // Domain name to filter blog entries by (required)
        'domain' => 'www.example.com',
        // Storage id for local files (optional, default to 1)
        'storage_id' => 1,
        // PID to import to (required)
        'pid' => 11,
        // In case you extracted the HubSpot file export in a subdirectory (optional)
        'file_identifier_prefix' => 'user_upload/',
        // Rewrite renamed paths
        'rewrite_paths' => [
            '/Mspelled(Path)/' => 'Correct$1'
        ],
        // API key - required to import comments
        'api_key' => '2342e3289wdhd39eu8wdehwd....',
        // Classes to be replaced
        'class_maps' => [
            '*' => [
                'alignleft' => 'pull-left',
                'alignright' => 'pull-right',
                'aligncenter' => 'center-block'
            ],
            'div' => [
                'source-php' => 'language-php'
            ]
        ],
        // Overrides for blog import (merged with above array)
        'blog' => [
            // Path to scan for HTML files
            'export_path' => '../hs-export-2017-05-11/com/example/www/blog',
            // Custom selectors (all of them optional, defaults as follows:)
            'selectors' => [
                'post' => '.blog-section',
                'title' => '#hs_cos_wrapper_name',
                'body' => '#hs_cos_wrapper_post_body',
                'tags' => '#hubspot-topic_data .topic-link',
            ],
            // Function to do additional processing
            'process' => function () {
                /** @var \Netresearch\HubspotImport\Command\BlogCommandController $this */
                /** @var \Netresearch\HubspotImport\Command\ProcessData $current */
                $current = $this->current;
                
                // Parse out datetime
                $header = $current->page->filter('#hubspot-author_data')->first();
                $months = ['jan', 'feb', 'mär', 'apr', 'mai', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dez'];
                $headerText = str_replace($months, array_keys($months), strtolower(trim($header->text())));
                if (!preg_match('/([0-9]{1,2}) ([0-9]{2}), ([0-9]{4})$/', $headerText, $match)) {
                    $this->outputLine('<error>Could not parse date</error>');
                }
                $current->entry['datetime'] = mktime(0, 0, 0, $match[1] + 1, $match[2], $match[3]);

                // Replace div.language-php with code.language-php
                foreach ($current->postBody->filter('div.language-php') as $node) {
                    /** @var \DOMElement $node */
                    $newNode = $node->ownerDocument->createElement('code');
                    $newNode->setAttribute('class', $node->getAttribute('class'));
                    foreach ($node->childNodes as $child){
                        $child = $node->ownerDocument->importNode($child, true);
                        $newNode->appendChild($child);
                    }
                    $node->parentNode->replaceChild($newNode, $node);
                }
            }
        ]
    ],
];
```

## Support

We just released this for whom it may concern - we don't actively use it. Feel free to fork this and send pull requests - we'll merge them in but won't do any QA.