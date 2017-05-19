<?php
$GLOBALS['TCA']['be_users']['columns']['avatar']['config']['foreign_match_fields'] = [
    'fieldname' => 'avatar',
    'tablenames' => 'be_users',
    'table_local' => 'sys_file'
];