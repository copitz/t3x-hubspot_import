<?php
/**
 * See class comment
 *
 * PHP version 7
 *
 * @category   Netresearch
 * @package    NrTemplate
 * @subpackage Error
 * @author     Christian Opitz <christian.opitz@netresearch.de>
 * @license    Netresearch http://www.netresearch.de/
 * @link       http://www.netresearch.de/
 */

namespace Netresearch\HubspotImport\Command;
use GeorgRinger\News\Domain\Model\News;
use GeorgRinger\News\Domain\Repository\NewsRepository;
use GuzzleHttp\Client;
use Helhum\Typo3Console\Log\Writer\ConsoleWriter;
use PwCommentsTeam\PwComments\Domain\Model\Comment;
use PwCommentsTeam\PwComments\Domain\Repository\CommentRepository;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\DomCrawler\Crawler;
use TYPO3\CMS\Core\Configuration\Richtext;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\Service\MagicImageService;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\CommandController;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

class ProcessData {
    /**
     * @var array
     */
    public $entry;

    /**
     * @var Crawler
     */
    public $page;

    /**
     * @var Crawler
     */
    public $post;

    /**
     * @var Crawler
     */
    public $postBody;
}

/**
 * Import a blog into tx_news tables
 *
 * @category   Netresearch
 * @package    HubspotImport
 * @subpackage Command
 * @author     Christian Opitz <christian.opitz@netresearch.de>
 * @license    Netresearch http://www.netresearch.de/
 * @link       http://www.netresearch.de/
 */
class BlogCommandController extends CommandController {
    const IMPORT_SOURCE = 'hubspot_import';

    /**
     * @var \Netresearch\HubspotImport\Domain\Service\NewsImportService
     * @inject
     */
    protected $newsImportService;

    /**
     * @var string
     */
    protected $exportPath;

    /**
     * @var ResourceStorage
     */
    protected $storage;

    protected $pid;

    protected $fileIdentifierPrefix = '';

    protected $domain;

    protected $selectors = [
        'post' => '.blog-section',
        'title' => '#hs_cos_wrapper_name',
        'body' => '#hs_cos_wrapper_post_body',
        'tags' => '#hubspot-topic_data .topic-link',
    ];

    /**
     * @var array
     */
    protected $rewritePaths = [];

    /**
     * @var callable
     */
    protected $process;

    protected $classMaps = [];

    /**
     * @var string
     */
    protected $apiKey;

    /**
     * @var ProcessData
     */
    protected $current;

    /**
     * Get config by name from extconf and set it to $this properties
     *
     * @param string $configName
     * @param null|string $exportPath Export path override
     */
    protected function setConfig($configName, $exportPath = null) {
        $configs = require __DIR__ . '/../../../../HubSpotImport.php';
        $config = $configs[$configName];
        if (!is_array($config)) {
            throw new \InvalidArgumentException("Config $configName doesn't exist");
        }
        $blogConfig = ['selectors' => $this->selectors];
        if ($config['blog']) {
            ArrayUtility::mergeRecursiveWithOverrule($blogConfig, $config['blog']);
            unset($config['blog']);
        }
        ArrayUtility::mergeRecursiveWithOverrule($config, $blogConfig);

        // Takeover Path
        if ($exportPath) {
            $config['export_path'] = $exportPath;
        }
        if (!$config['export_path']) {
            throw new \RuntimeException('No path provided');
        }
        $this->exportPath = realpath($config['export_path']);
        if (!$this->exportPath) {
            throw new \RuntimeException('Export path doesn\'t exist: ' . $config['export_path']);
        }

        // Takeover storage_id
        $storageId = 1;
        if (array_key_exists('storage_id', $config)) {
            if (!is_numeric($config['storage_id'])) {
                throw new \RuntimeException('storage_id must be integer');
            }
            $storageId = $config['storage_id'];
        }
        $this->storage = \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance()->getStorageObject($storageId);
        $this->storage->setEvaluatePermissions(false);

        // Takeover PID
        if (!is_numeric($config['pid'])) {
            throw new \RuntimeException('Invalid or missing pid');
        }
        $this->pid = (int) $config['pid'];

        $this->selectors = $config['selectors'];
        $this->domain = $config['domain'];
        $this->fileIdentifierPrefix = $config['file_identifier_prefix'];
        $this->rewritePaths = (array) $config['rewrite_paths'];
        $this->classMaps = (array) $config['class_maps'];
        $this->process = $config['process'];
        $this->apiKey = $config['api_key'];
    }

    /**
     * Import a blog into news
     *
     * @param string $config Name of the configuration to use
     * @param string $exportPath The path to the directory of the blog sites (HubSport COS export)
     */
    public function importCommand($config, $exportPath = null) {
        $this->setConfig($config, $exportPath);

        while (ob_end_flush());

        $newsEntries = [];
        foreach ($this->getFiles() as $file) {
            if ($this->setCurrent($file)) {
                $this->outputLine($file->getPathname());
                $this->processCurrent();
                $newsEntries[] = $this->current->entry;
            }
        }

        $this->newsImportService->getLogger()->addWriter(
            LogLevel::DEBUG,
            new ConsoleWriter(['output' => new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG)])
        );
        $GLOBALS['TYPO3_DB']->debugOutput = true;
        $this->newsImportService->import($newsEntries);
    }
    /**
     * Analyze what will be processed
     *
     * @param string $config Name of the configuration to use
     * @param string $exportPath The path to the directory of the blog sites (HubSport COS export)
     */
    public function analyzeCommand($config, $exportPath = null) {
        $this->setConfig($config, $exportPath);
        while (ob_end_flush());
        $classes = [];
        foreach ($this->getFiles() as $file) {
            if ($this->setCurrent($file)) {
                $this->outputLine($file->getPathname());
                $this->processCurrent();
                foreach ($this->current->postBody->filter('*') as $node) {
                    /* @var \DOMElement $node */
                    if (!$node->hasAttribute('class')) {
                        continue;
                    }
                    $tag = $node->nodeName;
                    $classes[$tag] = array_unique(array_merge(
                        (array) $classes[$tag],
                        [$node->getAttribute('class')]
                    ));
                }
                $this->outputLine('<info>Entry:</info>');
                var_dump($this->current->entry);
            } else {
                $this->outputLine('<comment>Not in domain:</comment> ' . $file->getPathname());
            }
        }
        $this->outputLine('<info>Classes:</info>');
        var_dump($classes);
    }

    /**
     * Update comments
     *
     * @param string $config Name of the configuration to use
     * @param bool $dryRun Whether to only show records to be imported
     */
    public function commentsCommand($config, $dryRun = false) {
        $this->setConfig($config);
        if (!$this->apiKey) {
            throw new \RuntimeException('Missing API key');
        }

        /** @var ObjectManager $objectManager */
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        /** @var NewsRepository $newsRepo */
        $newsRepo = $objectManager->get(NewsRepository::class);
        /** @var CommentRepository $commentsRepo */
        $commentsRepo = $objectManager->get(CommentRepository::class);

        $client = new Client();
        $baseUrl = 'https://api.hubapi.com/comments/v3/comments?hapikey=' . urlencode($this->apiKey) . '&contentId=';

        $query = $newsRepo->createQuery();
        $query->getQuerySettings()
            ->setStoragePageIds([$this->pid])
            ->setRespectStoragePage(true);
        $query->matching($query->equals('importSource', self::IMPORT_SOURCE));

        foreach ($query->execute() as $news) {
            /** @var News $news */
            $response = $client->get($baseUrl . $news->getImportId());
            $hsComments = json_decode($response->getBody());
            $addedComments = [];
            $this->outputLine($news->getUid() . ': ' . $news->getTitle());

            $presentCommentsQuery = $commentsRepo->createQuery();
            $presentComments = $presentCommentsQuery->matching(
                $presentCommentsQuery->logicalAnd(
                    [
                        $presentCommentsQuery->equals('pid', $this->pid),
                        $presentCommentsQuery->equals('entryUid', $news->getUid())
                    ]
                )
            )->execute();
            $this->outputLine('<comment>Removing ' . count($presentComments) . ' comments</comment>');
            foreach ($presentComments as $comment) {
                /** @var Comment $comment */
                $commentsRepo->remove($comment);
            }

            $this->outputLine('<info>Adding ' . count($hsComments->objects) . ' comments</info>');
            foreach ($hsComments->objects as $hsComment) {
                /** @var Comment $childComment */
                $childComment = null;
                do {
                    if (!array_key_exists($hsComment->id, $addedComments)) {
                        $comment = $objectManager->get(Comment::class);
                        $comment->setPid($this->pid);
                        $comment->setEntryUid($news->getUid());
                        $comment->setAuthorName($hsComment->userName);
                        $comment->setAuthorMail($hsComment->userEmail);
                        $comment->setMessage($hsComment->comment);
                        $comment->setHidden($hsComment->state !== 'APPROVED');
                        $comment->setCrdate($hsComment->createdAt / 1000);
                        $addedComments[$hsComment->id] = $comment;
                        $commentsRepo->add($comment);
                    }
                    if ($childComment) {
                        $childComment->setParentComment($addedComments[$hsComment->id]);
                    }
                    $childComment = $addedComments[$hsComment->id];
                } while (($hsComment = $hsComment->parent) && $hsComment->id);
            }
        }

        if (!$dryRun) {
            $this->outputLine('<info>Saving</info>');
            /** @var PersistenceManager $persistenceManager */
            $persistenceManager = $objectManager->get(PersistenceManager::class);
            $persistenceManager->persistAll();
        }
    }

    /**
     * Get the files to process (not filtered by domain yet)
     *
     * @return \SplFileInfo[]
     */
    protected function getFiles() {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->exportPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $files = [];
        foreach ($iter as $path => $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && substr($file->getBasename(), 0, 11) !== '-temporary-') {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * Parse a file and set it to current
     *
     * @param \SplFileInfo $file
     * @return boolean
     */
    protected function setCurrent($file) {
        $page = new Crawler(file_get_contents($file->getPathname()));
        if ($page->filter('meta[name="twitter:domain"]')->first()->attr('content') !== $this->domain) {
            return false;
        }

        $entry = [
            'import_source' => self::IMPORT_SOURCE,
            'pid' => $this->pid,
            'path_segment' => $file->getBasename('.html'),
            'type' => 0
        ];

        $bodyClass = $page->filter('body')->first()->attr('class');
        if (!preg_match('/hs-content-id-([0-9]+)/', $bodyClass, $contentIdMatches)) {
            throw new \ParseError('Could not detect content id');
        }
        $post = $page->filter($this->selectors['post'])->first();

        $entry['import_id'] = $contentIdMatches[1];
        $entry['title'] = $post->filter($this->selectors['title'])->text();

        $body = $post->filter($this->selectors['body']);

        $this->current = new ProcessData();
        $this->current->entry = &$entry;
        $this->current->page = $page;
        $this->current->post = $post;
        $this->current->postBody = $body;

        return true;
    }

    protected function processCurrent() {
        $current = $this->current;
        $this->replaceFileLinks();
        $this->replaceImages();
        $this->replaceClasses();

        $bodytext = str_replace(["\n", "\r"], '', $this->current->postBody->html());

        $current->entry['teaser'] = strpos($bodytext, '<!--more-->') ? trim(strip_tags(explode('<!--more-->', $bodytext, 2)[0])) : '';
        $current->entry['bodytext'] = preg_replace('/(<([a-z]+[0-9]?)>\s*)?<!--more-->(\s*<\/\2>)?/', '', $bodytext);
        $current->entry['media'] = $this->getMedia();
        $current->entry['author_data'] = $this->getAuthor();
        $current->entry['tags'] = $this->getTags();

        if ($this->process) {
            try {
                call_user_func($this->process, $this->current);
            } catch (\Exception $e) {
                $this->outputLine('<error>' . $e->getMessage() . '</error>');
            }
        }
    }

    /**
     * Get the tags
     *
     * @return array
     */
    protected function getTags() {
        $tags = [];
        foreach ($this->current->page->filter($this->selectors['tags']) as $tagNode) {
            /* @var \DOMElement $tagNode */
            $tags[] = $tagNode->textContent;
        }
        return $tags;
    }

    /**
     * Get author information if available
     *
     * @return array|null
     */
    protected function getAuthor() {
        static $authors = [];
        $info = $this->current->page->filter('.about-author-sec')->first();
        $link = $info->filter('.author-link')->first();
        if (!$link->count()) {
            return null;
        }
        $id = GeneralUtility::revExplode('/', $link->attr('href'), 2)[1];
        if ($authors[$id]) {
            return $authors[$id];
        }

        $author = [
            'username' => $id,
            'name' => $link->text(),
            'abstract' => $info->filter('p')->text()
        ];
        $src = $info->filter('img')->first()->attr('src');
        if ($src) {
            $path = $this->getLocalFilePath($src);
            if ($path) {
                $file = $this->getFile($path);
                if ($file) {
                    $author['avatar'] = $file->getUid();
                } else {
                    $this->outputLine('<error>Could not find local file for list image ' . $path . '</error>');
                }
            } else {
                $this->outputLine('<error>Author image for ' . $id . ' is no local file</error>');
            }
        }
        return $authors[$id] = $author;
    }

    /**
     * Get the media (twitter image as preview image)
     *
     * @return array
     */
    protected function getMedia() {
        $elements = $this->current->page->filter('meta[name="twitter:image"]');
        if (count($elements)) {
            $listImage = $elements->first()->attr('content');
            if ($listImage) {
                $path = $this->getLocalFilePath($listImage);
                if ($path) {
                    $file = $this->getFile($path);
                    if ($file) {
                        return [
                            [
                                'image' => $file->getCombinedIdentifier(),
                                'pid' => $this->pid,
                                'showinpreview' => true
                            ]
                        ];
                    } else {
                        $this->outputLine('<error>Could not find local file for list image ' . $path . '</error>');
                    }
                } else {
                    $this->outputLine('<error>List image is no local file</error>');
                }
            }
        } else {
            $this->outputLine('<error>Could not find list image</error>');
        }
        return [];
    }

    /**
     * Replace classes in classmap
     */
    protected function replaceClasses() {
        foreach ($this->classMaps as $selector => $classMap) {
            foreach ($this->current->postBody->filter($selector) as $node) {
                /* @var \DOMElement $node */
                if (!$node->hasAttribute('class')) {
                    continue;
                }
                $classes = GeneralUtility::trimExplode(' ', $node->getAttribute('class'), true);
                $newClasses = [];
                foreach ($classes as $class) {
                    if ($classMap[$class] && !in_array($classMap[$class], $newClasses)) {
                        $this->outputLine("<info>Mapped class $class to {$classMap[$class]}</info>");
                        $newClasses[] = $classMap[$class];
                    } else {
                        $this->outputLine("<comment>Removed class $class</comment>");
                    }
                }
                if (count($newClasses)) {
                    $node->setAttribute('class', implode(' ', $newClasses));
                } else {
                    $node->removeAttribute('class');
                }
            }
        }
    }

    /**
     * Replace links to local files with t3://file links
     */
    protected function replaceFileLinks() {
        foreach ($this->current->postBody->filter('a') as $linkNode) {
            /* @var \DOMElement $linkNode */
            if ($linkNode->hasAttribute('href')) {
                $path = $this->getLocalFilePath($linkNode->getAttribute('href'));
                if ($path) {
                    $file = $this->getFile($path);
                    if ($file) {
                        $this->outputLine('<info>Set link target to local file ' . $path . '</info>');
                        $linkNode->setAttribute('href', 't3://file?uid=' . $file->getUid());
                    } else {
                        $this->outputLine('<error>Could not find local file for ' . $path . '</error>');
                        $linkNode->parentNode->removeChild($linkNode);
                    }
                }
            }
        }
    }

    /**
     * Replace local images with magic images
     */
    protected function replaceImages() {
        foreach ($this->current->postBody->filter('img') as $imageNode) {
            /* @var \DOMElement $imageNode */
            $path = $this->getLocalFilePath($imageNode->getAttribute('src'));
            if ($path) {
                $file = $this->getFile($path);
                if ($file) {
                    $processedFile = $this->processImage($file, $imageNode->getAttribute('width'), $imageNode->getAttribute('height'));
                    $replaceNode = $imageNode->ownerDocument->createElement('img');
                    $replaceNode->setAttribute('src', $processedFile->getPublicUrl());
                    $replaceNode->setAttribute('data-htmlarea-file-uid', $file->getUid());
                    $replaceNode->setAttribute('data-htmlarea-file-table', 'sys_file');
                    foreach (['width', 'height'] as $attribute) {
                        $replaceNode->setAttribute($attribute, $processedFile->getProperty($attribute));
                    }
                    foreach (['alt', 'title', 'class'] as $attribute) {
                        if ($imageNode->hasAttribute($attribute)) {
                            $replaceNode->setAttribute($attribute, $imageNode->getAttribute($attribute));
                        }
                    }
                    $this->outputLine('<info>Set image source to local file ' . $path . '</info>');
                    $imageNode->parentNode->insertBefore($replaceNode, $imageNode);
                } else {
                    $this->outputLine('<error>Could not find local file for ' . $path . '</error>');
                    if ($imageNode->parentNode->nodeName === 'a') {
                        $imageNode->parentNode->parentNode->removeChild($imageNode->parentNode);
                        continue;
                    }
                }
                $imageNode->parentNode->removeChild($imageNode);
            }
        }
    }

    /**
     * Get the local file path from a hubspot url
     *
     * @param $src
     * @return mixed|null|string
     */
    protected function getLocalFilePath($src) {
        $info = parse_url($src);
        if (!substr($info['host'], 0, 12) === '.hubspot.net') {
            return null;
        }
        if (!preg_match('#^/(hub/[0-9]+/hubfs|hubfs/[0-9]+)/(.+)$#', $info['path'], $srcMatch)) {
            return null;
        }
        $src = rawurldecode($srcMatch[2]);
        foreach ($this->rewritePaths as $pattern => $replacement) {
            $src = preg_replace($pattern, $replacement, $src);
        }
        return $src;
    }

    /**
     * @param string $src
     * @return File
     */
    protected function getFile($src) {
        $identifier = $this->fileIdentifierPrefix . $src;
        $file = $this->storage->getFile($identifier);
        return $file;
    }

    /**
     * Get the processed image
     *
     * @param File $file
     * @param $width
     * @param $height
     * @return ProcessedFile
     *
     */
    protected function processImage($file, $width, $height)
    {
        static $magicImageService;
        if (!$magicImageService) {
            /** @var Richtext $richtextConfigurationProvider */
            $richtextConfigurationProvider = GeneralUtility::makeInstance(Richtext::class);
            $tsConfig = $richtextConfigurationProvider->getConfiguration(
                'tx_news_domain_model_news',
                'bodytext',
                $this->pid,
                0,
                ['richtext' => true]
            );

            /** @var MagicImageService $magicImageService */
            $magicImageService = GeneralUtility::makeInstance(MagicImageService::class);
            $magicImageService->setMagicImageMaximumDimensions($tsConfig);
        }

        if (!is_numeric($width)) {
            $width = $file->getProperty('width');
        }
        if (!is_numeric($height)) {
            $height = $file->getProperty('height');
        }

        return $magicImageService->createMagicImage($file, compact('width', 'height'));
    }
}