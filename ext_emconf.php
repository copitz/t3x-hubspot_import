<?php
/***************************************************************
 * Extension Manager/Repository config file
 *
 * Manual updates:
 * Only the data in the array - anything else is removed by next write.
 * "version" and "dependencies" must not be touched!
 ***************************************************************/

$EM_CONF[$_EXTKEY] = array(
	'title' => 'HubSpot import',
	'description' => 'Import data from HubSpot to TYPO3',
	'category' => 'templates',
	'author' => 'netresearch',
	'author_email' => 'netresearch',
	'state' => 'alpha',
	'internal' => '',
	'uploadfolder' => '0',
	'createDirs' => '',
	'clearCacheOnLoad' => 0,
	'version' => '0.0.1',
	'constraints' => array(
		'depends' => array(
		    'news' => '6.0.0-6.99.99'
		),
		'conflicts' => array(
		),
		'suggests' => array(
		),
	),
);
