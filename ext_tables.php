<?php
if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

if (TYPO3_MODE === 'BE') {
    $extPath = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($_EXTKEY);

        // add module before 'Help'
    if (!isset($GLOBALS['TBE_MODULES']['tcTools'])) {
        $temp_TBE_MODULES = array();
        foreach ($GLOBALS['TBE_MODULES'] as $key => $val) {
            if ($key === 'help') {
                $temp_TBE_MODULES['tcTools'] = '';
                $temp_TBE_MODULES[$key] = $val;
            } else {
                $temp_TBE_MODULES[$key] = $val;
            }
        }

        $GLOBALS['TBE_MODULES'] = $temp_TBE_MODULES;
    }

    TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addModule(
        'tcTools',
        '',
        '',
        '',
        array(
            'access' => 'group,user',
            'name' => 'tcTools',
            'labels' => array(
                'tabs_images' => array(
                    'tab' => 'EXT:tc_beuser/Resources/Public/Images/moduleTcTools.gif',
                ),
                'll_ref' => 'LLL:EXT:tc_beuser/Resources/Private/Language/locallangTcTools.xlf',
            ),
        )
    );

    # UserAdmin Module
    TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addModule(
        'tcTools',
        'UserAdmin',
        'bottom',
        '',
        array(
            'routeTarget' => dkd\TcBeuser\Controller\UserAdminController::class . '::mainAction',
            'access' => 'group,user',
            'name' => 'tcTools_UserAdmin',
            'workspaces' => 'online',
            'labels' => array(
                'tabs_images' => array(
                    'tab' => 'EXT:tc_beuser/Resources/Public/Images/moduleUserAdmin.gif',
                ),
                'll_ref' => 'LLL:EXT:tc_beuser/Resources/Private/Language/locallangModuleUserAdmin.xlf',
            )
        )
    );

    # GroupAdmin Module
    TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addModule(
        'tcTools',
        'GroupAdmin',
        'bottom',
        '',
        array(
            'routeTarget' => dkd\TcBeuser\Controller\GroupAdminController::class . '::mainAction',
            'access' => 'group,user',
            'name' => 'tcTools_GroupAdmin',
            'workspaces' => 'online',
            'labels' => array(
                'tabs_images' => array(
                    'tab' => 'EXT:tc_beuser/Resources/Public/Images/moduleGroupAdmin.gif',
                ),
                'll_ref' => 'LLL:EXT:tc_beuser/Resources/Private/Language/locallangModuleGroupAdmin.xlf',
            )
        )
    );

    # FilemountsView Module
    TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addModule(
        'tcTools',
        'FilemountsView',
        'bottom',
        '',
        array(
            'routeTarget' => dkd\TcBeuser\Controller\FilemountsViewController::class . '::mainAction',
            'access' => 'group,user',
            'name' => 'tcTools_FilemountsView',
            'workspaces' => 'online',
            'labels' => array(
                'tabs_images' => array(
                    'tab' => 'EXT:tc_beuser/Resources/Public/Images/moduleFilemountsView.gif',
                ),
                'll_ref' => 'LLL:EXT:tc_beuser/Resources/Private/Language/locallangModuleFilemountsView.xlf',
            )
        )
    );

    # Overview Module
    TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addModule(
        'tcTools',
        'Overview',
        'bottom',
        '',
        array(
            'routeTarget' => dkd\TcBeuser\Controller\OverviewController::class . '::mainAction',
            'access' => 'group,user',
            'name' => 'tcTools_Overview',
            'workspaces' => 'online',
            'labels' => array(
                'tabs_images' => array(
                    'tab' => 'EXT:tc_beuser/Resources/Public/Images/moduleOverview.gif',
                ),
                'll_ref' => 'LLL:EXT:tc_beuser/Resources/Private/Language/locallangModuleOverview.xlf',
            )
        )
    );

    # Overview Module
    // Module Web > Access
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerModule(
        'dkd.TcBeuser',
        'web',
        'tx_Permission',
        'bottom',
        array(
            'Permission' => 'index, edit, update'
        ),
        array(
            'access' => 'group,user',
            'icon' => 'EXT:beuser/Resources/Public/Icons/module-permission.svg',
            'labels' => 'LLL:EXT:tc_beuser/Resources/Private/Language/locallangModulePermission.xlf',
            'navigationComponentId' => 'typo3-pagetree'
        )
    );

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::registerAjaxHandler(
        'PermissionAjaxController::dispatch',
        'dkd\\TcBeuser\\Controller\\PermissionAjaxController->dispatch'
    );
}