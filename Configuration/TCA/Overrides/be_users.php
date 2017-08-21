<?php
if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}


if (TYPO3_MODE == 'BE') {
    // enabling regular BE users to edit BE users
    $GLOBALS['TCA']['be_users']['ctrl']['adminOnly'] = 0;

    //wizard for the password generator
    $wizConfig = array(
        'type' => 'userFunc',
        'userFunc' => 'dkd\\TcBeuser\\Utility\\PwdWizardUtility->main',
        'params' => array('type' => 'password')
    );

    $GLOBALS['TCA']['be_users']['columns']['password']['config']['wizards']['tx_tcbeuser'] = $wizConfig;
    $GLOBALS['TCA']['be_users']['columns']['usergroup']['config']['itemsProcFunc'] =
        'dkd\\TcBeuser\\Utility\\TcBeuserUtility->getGroupsID';

}

// fe_users modified
$be_users_cols = array(
    'tc_beuser_switch_to' => array(
        'label' => 'LLL:EXT:tc_beuser/Resources/Private/Language/locallang_tca.xlf:be_users.tc_beuser_switch_to',
        'exclude' => '1',
        'config' => array(
            'type' => 'check'
        )
    )
);

TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('be_users', $be_users_cols);
TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'be_users',
    '--div--;tc_beuser,tc_beuser_switch_to'
);

unset($GLOBALS['TCA']['be_users']['columns']['usergroup']['config']['wizards']);

$GLOBALS['TCA']['be_users']['columns']['usergroup']['config']['size'] = '10';

// initialize BE user. Unfortunately $GLOBALS['BE_USER'] is not set and we ca not use it here
$backendUser = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication::class);
$backendUser->start();

// disable possibility to edit usergroups
if (!$backendUser->isAdmin()) {
    $GLOBALS['TCA']['be_users']['columns']['usergroup']['config']['fieldControl']['editPopup']['disabled'] = true;
    $GLOBALS['TCA']['be_users']['columns']['usergroup']['config']['fieldControl']['addRecord']['disabled'] = true;
    $GLOBALS['TCA']['be_users']['columns']['usergroup']['config']['fieldControl']['listModule']['disabled'] = true;
}