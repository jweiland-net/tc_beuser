<?php
namespace dkd\TcBeuser\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2006 Ingo Renner <ingo.renner@dkd.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use dkd\TcBeuser\Module\AbstractModuleController;
use dkd\TcBeuser\Utility\TcBeuserUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Module 'User / Group Overview' for the 'tc_beuser' extension.
 *
 * @author Ingo Renner <ingo.renner@dkd.de>
 * @author Ivan Kartolo <ivan.kartolo@dkd.de>
 * @package TYPO3
 * @subpackage tx_tcbeuser
 */
class OverviewController extends AbstractModuleController
{

    /**
     * Name of the module
     *
     * @var string
     */
    protected $moduleName = 'tcTools_Overview';

    public $jsCode;
    public $pageinfo;
    public $compareFlags;
    public $be_user;
    public $be_group;

    /**
     * Load needed locallang files
     */
    public function loadLocallang()
    {
        $this->getLanguageService()->includeLLFile('EXT:tc_beuser/Resources/Private/Language/locallangOverview.xlf');
        $this->getLanguageService()->includeLLFile('EXT:lang/locallang_alt_doc.xml');
    }

    /**
     * Entrance from the backend module. This replace the _dispatch
     *
     * @param ServerRequestInterface $request The request object from the backend
     * @param ResponseInterface $response The reponse object sent to the backend
     *
     * @return ResponseInterface Return the response object
     */
    public function mainAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->loadLocallang();

        if (GeneralUtility::_POST('ajaxCall')) {
            $method   = GeneralUtility::_POST('method');
            $groupId  = GeneralUtility::_POST('groupId');
            $open     = GeneralUtility::_POST('open');
            $backPath = GeneralUtility::_POST('backPath');

            $userView = GeneralUtility::makeInstance('dkd\\TcBeuser\\Utility\\OverviewUtility');
            $content  = $userView->handleMethod($method, $groupId, $open, $backPath);

            echo $content;
        } else {
            $this->init();

            $this->main();

            // wrap content with form tag
            $content= '<form action="' . htmlspecialchars($this->R_URI) . '" method="post" ' .
                'enctype="' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['form_enctype'] . '" ' .
                'name="editform" onsubmit="return TBE_EDITOR_checkSubmit(1);">' .
                $this->content .
                '</form>';

            $this->moduleTemplate->setContent($content);
            $response->getBody()->write($this->moduleTemplate->renderContent());
            return $response;
        }
    }

    /**
     * empty function, not needed
     */
    public function processData()
    {
        // TODO: Implement processData() method.
    }

    public function main()
    {
        $this->init();

        //TODO more access check!?
        $access = $this->getBackendUser()->modAccess($this->MCONF, true);

        if ($access || $this->getBackendUser()->isAdmin()) {
            // We need some uid in rootLine for the access check, so use first webmount
            $webmounts = $this->getBackendUser()->returnWebmounts();
            $this->pageinfo['uid'] = $webmounts[0];
            $this->pageinfo['_thePath'] = '/';

            if (GeneralUtility::_GP('beUser')) {
                $this->MOD_SETTINGS['function'] = 2;
            }

            if (GeneralUtility::_GP('beGroup')) {
                $this->MOD_SETTINGS['function'] = 1;
            }

            if ($this->MOD_SETTINGS['function'] == 1) {
                $title = $this->getLanguageService()->getLL('overview-groups');
            } elseif ($this->MOD_SETTINGS['function'] == 2) {
                $title = $this->getLanguageService()->getLL('overview-users');
            }

            $this->moduleTemplate->setTitle($title);

            // set JS for the AJAX call on overview
            // TODO: rewrite JS?
            $this->moduleTemplate->getPageRenderer()->addJsFile(
                ExtensionManagementUtility::extRelPath('tc_beuser') . 'Resources/Public/JavaScript/prototype.js'
            );
            $this->moduleTemplate->getPageRenderer()->addJsFile(
                ExtensionManagementUtility::extRelPath('tc_beuser') . 'Resources/Public/JavaScript/ajax.js'
            );

            $this->content = $this->moduleTemplate->header($title);
            $this->content .= $this->moduleContent();

            $this->generateMenu('OverviewMenu');
        }

        $this->getBackendUser()->user['admin'] = 0;
    }

    public function init()
    {
        parent::init();
        $this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        TcBeuserUtility::switchUser(GeneralUtility::_GP('SwitchUser'));

        $this->moduleTemplate->addJavaScriptCode(
            'OverviewModule',
            '
            script_ended = 0;
			function jumpToUrl(URL) {
				document.location = URL;
			}

			var T3_BACKPATH = \''.$this->doc->backPath.'\';
			var ajaxUrl = \'' . BackendUtility::getModuleUrl($GLOBALS['MCONF']['name']) . '\';
            ' .
            $this->moduleTemplate->redirectUrls(GeneralUtility::linkThisScript())
        );

        $this->id = 0;

        // update compareFlags
        if (GeneralUtility::_GP('ads')) {
            $this->compareFlags = GeneralUtility::_GP('compareFlags');
            $this->getBackendUser()->pushModuleData('tcTools_Overview/index.php/compare', $this->compareFlags);
        } else {
            $this->compareFlags = $this->getBackendUser()->getModuleData('tcTools_Overview/index.php/compare', 'ses');
        }

        // Setting return URL
        $this->returnUrl = GeneralUtility::_GP('returnUrl');
        $this->retUrl    = $this->returnUrl ? $this->returnUrl : 'dummy.php';

        //init user / group
        $beuser = GeneralUtility::_GET('beUser');
        if ($beuser) {
            $this->be_user = $beuser;
        }
        $begroup = GeneralUtility::_GET('beGroup');
        if ($begroup) {
            $this->be_group = $begroup;
        }
    }

    /**
     * Adds items to the ->MOD_MENU array. Used for the function menu selector.
     *
     * @return void
     */
    public function menuConfig()
    {
        $this->MOD_MENU = array(
            'function' => array(
                '1' => $this->getLanguageService()->getLL('overview-groups'),
                '2' => $this->getLanguageService()->getLL('overview-users'),
            )
        );

        $groupOnly = array();
        if ($this->MOD_SETTINGS['function'] == 1) { // groups
            $groupOnly['members'] = $this->getLanguageService()->getLL('showCol-members');
        }

        $groupAndUser = array(
            'filemounts'        => $this->getLanguageService()->getLL('showCol-filemounts'),
            'webmounts'         => $this->getLanguageService()->getLL('showCol-webmounts'),
            'pagetypes'         => $this->getLanguageService()->getLL('showCol-pagetypes'),
            'selecttables'      => $this->getLanguageService()->getLL('showCol-selecttables'),
            'modifytables'      => $this->getLanguageService()->getLL('showCol-modifytables'),
            'nonexcludefields'  => $this->getLanguageService()->getLL('showCol-nonexcludefields'),
            'explicitallowdeny' => $this->getLanguageService()->getLL('showCol-explicitallowdeny'),
            'limittolanguages'  => $this->getLanguageService()->getLL('showCol-limittolanguages'),
            'workspaceperms'    => $this->getLanguageService()->getLL('showCol-workspaceperms'),
            'workspacememship'  => $this->getLanguageService()->getLL('showCol-workspacememship'),
            'description'       => $this->getLanguageService()->getLL('showCol-description'),
            'modules'           => $this->getLanguageService()->getLL('showCol-modules'),
            'tsconfig'          => $this->getLanguageService()->getLL('showCol-tsconfig'),
            'tsconfighl'        => $this->getLanguageService()->getLL('showCol-tsconfighl'),
        );
        $this->MOD_MENU['showCols'] = array_merge($groupOnly, $groupAndUser);

        parent::menuConfig();
    }

    /**
     * Generates the module content
     *
     * @return string
     */
    public function moduleContent()
    {
        $content = '';

        switch ((string)$this->MOD_SETTINGS['function']) {
            case '1':
                // group view
                $content .= $this->getGroupView($this->be_group);
                $this->getButtons();
                break;
            case '2':
                // user view
                $content .= $this->getUserView($this->be_user);
                $this->getButtons();
                break;
        }

        return $content;
    }

    public function getUserView($userUid)
    {
        $content = '';

        if ($this->be_user == 0) {
            //warning - no user selected
            $content .= $this->getLanguageService()->getLL('select-user');

            $this->id = 0;
            $this->search_field = GeneralUtility::_GP('search_field');
            $this->pointer = MathUtility::forceIntegerInRange(
                GeneralUtility::_GP('pointer'),
                0,
                100000
            );
            $this->table = 'be_users';

            /** @var \dkd\TcBeuser\Utility\RecordListUtility $dblist */
            $dblist = GeneralUtility::makeInstance('dkd\\TcBeuser\\Utility\\RecordListUtility');
            $dblist->backPath = $this->doc->backPath;
            $dblist->script = $this->MCONF['script'];
            $dblist->alternateBgColors = true;
            $dblist->userMainGroupOnly = true;
            $dblist->calcPerms = $this->getBackendUser()->calcPerms($this->pageinfo);
            $dblist->showFields = array('username', 'realName', 'usergroup');
            $dblist->disableControls = array('edit' => true, 'hide' => true, 'delete' => true, 'import' => true);

            //Setup for analyze Icon
            $dblist->analyzeLabel = $this->getLanguageService()->sL('LLL:EXT:tc_beuser/Resources/Private/Language/locallangUserAdmin.xlf:analyze', 1);
            $dblist->analyzeParam = 'beUser';

            $dblist->start(0, $this->table, $this->pointer, $this->search_field);
            $dblist->generateList();

            $content .= $dblist->HTMLcode ? $dblist->HTMLcode : '<br />' .
                $this->getLanguageService()->getLL('not-found').'<br />';

            // Add JavaScript functions to the page:

            $this->moduleTemplate->addJavaScriptCode(
                'UserListInlineJS',
                '
				' . $this->moduleTemplate->redirectUrls($dblist->listURL()) . '
				' . $dblist->CBfunctions() . '
			'
            );

            // searchbox toolbar
            if (!$this->modTSconfig['properties']['disableSearchBox'] && ($dblist->HTMLcode || !empty($dblist->searchString))) {
                $searchBox = $dblist->getSearchBox();
                $this->moduleTemplate->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/Backend/ToggleSearchToolbox');

                $searchButton = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeLinkButton();
                $searchButton
                    ->setHref('#')
                    ->setClasses('t3js-toggle-search-toolbox')
                    ->setTitle($this->getLanguageService()->getLL('search-user'))
                    ->setIcon($this->iconFactory->getIcon('actions-search', Icon::SIZE_SMALL));

                $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton(
                    $searchButton,
                    ButtonBar::BUTTON_POSITION_LEFT,
                    90
                );

            }

            $content = $searchBox . $content;

        } else {
            //real content
            $this->table = 'be_users';
            $userRecord = BackendUtility::getRecord($this->table, $userUid);
            $content .= $this->getColSelector();
            $content .= '<br />';
            $content .= $this->getUserViewHeader($userRecord);
            /** @var \dkd\TcBeuser\Utility\OverviewUtility $userView */
            $userView = GeneralUtility::makeInstance('dkd\\TcBeuser\\Utility\\OverviewUtility');

            //if there is member in the compareFlags array, remove it. There is no 'member' in user view
            unset($this->compareFlags['members']);
            $content .= $userView->getTable($userRecord, $this->compareFlags);
        }

        return $content;
    }

    public function getGroupView($groupUid)
    {
        $content = '';

        if ($this->be_group == 0) {
            //warning - no user selected
            $content .= $this->getLanguageService()->getLL('select-group');

            $this->id = 0;
            $this->search_field = GeneralUtility::_GP('search_field');
            $this->pointer = MathUtility::forceIntegerInRange(
                GeneralUtility::_GP('pointer'),
                0,
                100000
            );
            $this->table = 'be_groups';

            /** @var \dkd\TcBeuser\Utility\RecordListUtility $dblist */
            $dblist = GeneralUtility::makeInstance('dkd\\TcBeuser\\Utility\\RecordListUtility');
            $dblist->backPath = $this->doc->backPath;
            $dblist->script = $this->MCONF['script'];
            $dblist->alternateBgColors = true;
            $dblist->userMainGroupOnly = true;
            $dblist->calcPerms = $this->getBackendUser()->calcPerms($this->pageinfo);
            $dblist->showFields = array('title');
            $dblist->disableControls = array(
                'edit' => true,
                'hide' => true,
                'delete' => true,
                'history' => true,
                'new' => true,
                'import' => true
            );

            //Setup for analyze Icon
            $dblist->analyzeLabel = $this->getLanguageService()->sL('LLL:EXT:tc_beuser/Resources/Private/Language/locallangGroupAdmin.xlf:analyze', 1);
            $dblist->analyzeParam = 'beGroup';

            $dblist->start(0, $this->table, $this->pointer, $this->search_field);
            $dblist->generateList();

            $content .= $dblist->HTMLcode ? $dblist->HTMLcode : '<br />'.$this->getLanguageService()->sL('LLL:EXT:tc_beuser/Resources/Private/Language/locallangGroupAdmin.xlf:not-found').'<br />';

            // searchbox toolbar
            if (!$this->modTSconfig['properties']['disableSearchBox'] && ($dblist->HTMLcode || !empty($dblist->searchString))) {
                $searchBox = $dblist->getSearchBox();
                $this->moduleTemplate->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/Backend/ToggleSearchToolbox');

                $searchButton = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeLinkButton();
                $searchButton
                    ->setHref('#')
                    ->setClasses('t3js-toggle-search-toolbox')
                    ->setTitle($this->getLanguageService()->getLL('search-group'))
                    ->setIcon($this->iconFactory->getIcon('actions-search', Icon::SIZE_SMALL));

                $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton(
                    $searchButton,
                    ButtonBar::BUTTON_POSITION_LEFT,
                    90
                );

            }

            $content = $searchBox . $content;
        } else {
            //real content
            $this->table = 'be_groups';
            $groupRecord = BackendUtility::getRecord($this->table, $groupUid);
            $content .= $this->getColSelector();
            $content .= '<br />';
//			$content .= $this->getUserViewHeader($groupRecord);

            /** @var \dkd\TcBeuser\Utility\OverviewUtility $userView */
            $userView = GeneralUtility::makeInstance('dkd\\TcBeuser\\Utility\\OverviewUtility');

            $content .= $userView->getTableGroup($groupRecord, $this->compareFlags);
        }

        return $content;
    }

    public function getColSelector()
    {
        $content = '';
        $i = 0;

        foreach ($this->MOD_MENU['showCols'] as $key => $label) {
            $content .= '<span style="display: block; float: left; min-width: 200px;">'
                .'<input type="checkbox" value="1" name="compareFlags['.$key.']" id="compareFlags['.$key.']"'.($this->compareFlags[$key]?' checked="checked"':'').' />'
                .'&nbsp;'
                . '<label for="compareFlags['.$key.']">' . $label . '</label>'
                .'</span> '.chr(10);

            $i++;
        }

        $content .= '<br style="clear: left;" /><br />';
        $content .= '<input type="submit" name="ads" value="Update" />';
        $content .= '<br />';

        return $content;
    }

    public function getUserViewHeader($userRecord)
    {
        $content = '';

        $alttext = BackendUtility::getRecordIconAltText($userRecord, $this->table);
        $recTitle = htmlspecialchars(BackendUtility::getRecordTitle($this->table, $userRecord));

        // icon
        $iconImg = $this->iconFactory->getIconForRecord(
            $this->table,
            $userRecord,
            Icon::SIZE_SMALL
        )->render();

        // controls
        $control = $this->makeUserControl($userRecord);

        $content .= $iconImg.' '.$recTitle.' '.$control;

        return $content;
    }

    public function makeUserControl($userRecord)
    {

        // edit
        $icon = $this->iconFactory->getIcon('actions-open', Icon::SIZE_SMALL)->render();
        $control = '<a href="#" class="btn btn-default" onclick="'.htmlspecialchars(
            $this->editOnClick(
                '&edit['.$this->table.']['.$userRecord['uid'].']=edit&SET[function]=edit',
                GeneralUtility::getIndpEnv('REQUEST_URI').'SET[function]=2'
            )
        ).'">' . $icon . '</a>';

        //info
        // always show info
        $icon = $this->iconFactory->getIcon('actions-document-info', Icon::SIZE_SMALL)->render();
        $control .= '<a href="#" class="btn btn-default" ' .
            'onclick="' . htmlspecialchars('top.launchView(\'' . $this->table . '\', \'' . $userRecord['uid'] . '\'); return false;') . '">' .
            $icon .
            '</a>';

        // hide/unhide
        $hiddenField = $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['disabled'];
        if ($userRecord[$hiddenField]) {
            $icon = $this->iconFactory->getIcon('actions-edit-unhide', Icon::SIZE_SMALL)->render();
            $params = '&data[' . $this->table . '][' . $userRecord['uid'] . '][' . $hiddenField . ']=0&SET[function]=action';
            $control .= '<a href="#" class="btn btn-default" ' .
                'onclick="return jumpToUrl(\'' . htmlspecialchars($this->actionOnClick($params, -1)) . '\');">' .
                $icon .
                '</a>';
        } else {
            $icon = $this->iconFactory->getIcon('actions-edit-hide', Icon::SIZE_SMALL)->render();
            $params = '&data[' . $this->table . '][' . $userRecord['uid'] . '][' . $hiddenField . ']=1&SET[function]=action';
            $control .= '<a href="#" class="btn btn-default" ' .
                'onclick="return jumpToUrl(\'' . htmlspecialchars($this->actionOnClick($params, -1)) . '\');">' .
                $icon .
                '</a>';
        }

        // delete
        $icon = $this->iconFactory->getIcon('actions-edit-delete', Icon::SIZE_SMALL)->render();
        $params = '&cmd['.$this->table.']['.$userRecord['uid'].'][delete]=1&SET[function]=action&vC=' . rawurlencode($this->getBackendUser()->veriCode()) . '&prErr=1&uPT=1';
        $control .= '<a href="#" class="btn btn-default" ' .
            'onclick="' . htmlspecialchars('if (confirm(' .
                GeneralUtility::quoteJSvalue(
                    $this->getLanguageService()->getLL('deleteWarning') .
                    BackendUtility::referenceCount(
                        $this->table,
                        $userRecord['uid'],
                        ' (There are %s reference(s) to this record!)'
                    )
                ) . ')) { return jumpToUrl(\'' . $this->actionOnClick($params, BackendUtility::getModuleUrl($GLOBALS['MCONF']['name'])) . '\'); } return false;'
            ) . '">' .
            $icon .
            '</a>';

        //TODO: only for admins or authorized user
        // swith user / switch user back
        if (!$userRecord[$hiddenField] &&
            ($this->getBackendUser()->user['tc_beuser_switch_to'] || $this->getBackendUser()->isAdmin())
        ) {
            if ($this->getBackendUser()->user['uid'] !== (int)$userRecord['uid']) {
                // show switch button if user is not current user
                $control .= '<a class="btn btn-default" ' .
                    'href="' . GeneralUtility::linkThisScript(array('SwitchUser' => $userRecord['uid'])) . '" '.
                    'target="_top" title="' . htmlspecialchars('Switch user to: ' . $userRecord['username']) . '" >' .
                    $this->iconFactory->getIcon('actions-system-backend-user-switch', Icon::SIZE_SMALL)->render() .
                    '</a>' .
                    chr(10) . chr(10);
            }
        }

        return $control;
    }

    /**
     * ingo.renner@dkd.de: from BackendUtility, modified
     *
     * Returns a JavaScript string (for an onClick handler) which will load the alt_doc.php script that shows the form for editing of the record(s) you have send as params.
     * REMEMBER to always htmlspecialchar() content in href-properties to ampersands get converted to entities (XHTML requirement and XSS precaution)
     * Usage: 35
     *
     * @param string $params is parameters sent along to alt_doc.php. This requires a much more details description which you must seek in Inside TYPO3s documentation of the alt_doc.php API. And example could be '&edit[pages][123]=edit' which will show edit form for page record 123.
     * @param string $requestUri is an optional returnUrl you can set - automatically set to REQUEST_URI.
     * @return string
     * @see template::issueCommand()
     */
    public function editOnClick($params, $requestUri = '')
    {
        $retUrl = '&returnUrl=' . ($requestUri == -1 ? "'+T3_THIS_LOCATION+'" : rawurlencode($requestUri ? $requestUri : GeneralUtility::getIndpEnv('REQUEST_URI')));
        return "window.location.href='" . BackendUtility::getModuleUrl('tcTools_UserAdmin') . $retUrl . $params . "'; return false;";
    }

    /**
     * create link for the hide/unhide and delete icon.
     * not using tce_db.php, because we need to manipulate user's permission
     *
     * @param string $params param with command (hide/unhide, delete) and records id
     * @param string $requestURI redirect link, after process the command
     * @return string jumpTo URL link with redirect
     */
    public function actionOnClick($params, $requestURI = '')
    {
        $redirect = '&redirect=' . ($requestURI == -1 ? "'+T3_THIS_LOCATION+'" : rawurlencode($requestURI ? $requestURI : GeneralUtility::getIndpEnv('REQUEST_URI'))) .
            '&vC=' . rawurlencode($this->getBackendUser()->veriCode()) . '&prErr=1&uPT=1';
        return BackendUtility::getModuleUrl('tcTools_UserAdmin') . $params . $redirect;
    }
}
