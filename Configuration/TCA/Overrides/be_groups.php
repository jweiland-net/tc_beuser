<?php
if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

if (TYPO3_MODE === 'BE') {
    // enabling regular BE users to edit BE groups
    $GLOBALS['TCA']['be_groups']['ctrl']['adminOnly'] = 0;

    $GLOBALS['TCA']['be_groups']['columns']['subgroup']['config']['itemsProcFunc'] =
        'dkd\\TcBeuser\\Utility\\TcBeuserUtility->getGroupsID';
}

$tempCol = array(
    'members' => array(
        'label' => 'User',
        'config' => array(
            'type' => 'select',
            'foreign_table' => 'be_users',
            'foreign_table_where' => 'ORDER BY username ASC',
            'size' => '10',
            'maxitems' => 100,
            'iconsInOptionTags' => 1,
        )
    )
);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('be_groups', $tempCol);
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes('be_groups', 'members');
