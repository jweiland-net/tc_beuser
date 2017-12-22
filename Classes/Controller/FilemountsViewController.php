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
use TYPO3\CMS\Backend\Form\FormResultCompiler;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Module 'User / Group Overview' for the 'tc_beuser' extension.
 *
 * @author Ingo Renner <ingo.renner@dkd.de>
 * @author Ivan Kartolo <ivan.kartolo@dkd.de>
 * @package TYPO3
 * @subpackage tx_tcbeuser
 */
class FilemountsViewController extends AbstractModuleController
{

    /**
     * Name of the module
     *
     * @var string
     */
    protected $moduleName = 'tcTools_FilemountsView';

    public $jsCode;
    public $pageinfo;

    /** @var  \dkd\TcBeuser\Utility\EditFormUtility */
    public $editForm;

    /**
     * Data value from GP
     *
     * @var string
     */
    public $data;

    /**
     * Command from GP
     *
     * @var string
     */
    public $cmd;

    /**
     * Disable RTE from GP
     *
     * @var string
     */
    public $disableRTE;

    /**
     * Error string
     *
     * @var array
     */
    public $error;

    /**
     * working only with be_users table
     *
     * @var string
     */
    public $table = 'sys_filemounts';

    /**
     * Load needed locallang files
     */
    public function loadLocallang()
    {
        $this->getLanguageService()->includeLLFile('EXT:tc_beuser/Resources/Private/Language/locallangFilemountsView.xlf');
        $this->getLanguageService()->includeLLFile('EXT:lang/locallang_alt_doc.xml');
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

            $title = $this->getLanguageService()->getLL('title');
            $this->moduleTemplate->setTitle($title);

            $this->content = $this->moduleTemplate->header($title);
            $this->content .= $this->moduleContent();

            $this->generateMenu('FilemountViewMenu');
        }
    }

    /**
     * Do processing of data, submitting it to TCEmain.
     *
     * @return void
     */
    public function processData()
    {
        if ($this->getBackendUser()->user['admin'] != 1) {
            //make fake Admin
            TcBeuserUtility::fakeAdmin();
            $fakeAdmin = 1;
        }

        // GPvars specifically for processing:
        $this->data = GeneralUtility::_GP('data');
        $this->cmd = GeneralUtility::_GP('cmd')?GeneralUtility::_GP('cmd'):array();
        $this->disableRTE = GeneralUtility::_GP('_disableRTE');

        //set path to be relative to fileadmin
        $pathExists = false;
        if (is_array($this->data)) {
            $table = array_keys($this->data);
            $uid = array_keys($this->data[$table[0]]);
            if (!is_numeric($uid)) {
                $this->data[$table[0]][$uid[0]]['base']=1;

                //check the new path
                $res = $this->getDatabaseConnection()->exec_SELECTquery(
                    '*',
                    $this->table,
                    'path = ' . $this->getDatabaseConnection()->fullQuoteStr($this->data[$table[0]][$uid[0]]['path'], $this->table) .
                    BackendUtility::deleteClause('sys_filemounts')
                );

                if ($this->getDatabaseConnection()->sql_num_rows($res) > 0) {
                    while ($row = $this->getDatabaseConnection()->sql_fetch_assoc($res)) {
                        if ($row['uid'] != $uid[0]) {
                            $pathExists = true;
                        }
                    }
                }
            }
        }
        if ($pathExists) {
            $this->error[] = array('error',$this->getLanguageService()->getLL('error-path'));
        } else {
            // See tce_db.php for relevate options here:
            // Only options related to $this->data submission are included here.
            /** @var \TYPO3\CMS\Core\DataHandling\DataHandler $tce */
            $tce = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\DataHandling\\DataHandler');

            // Setting default values specific for the user:
            $tcaDefaultOverride = $this->getBackendUser()->getTSConfigProp('TCAdefaults');
            if (is_array($tcaDefaultOverride)) {
                $tce->setDefaultsFromUserTS($tcaDefaultOverride);
            }

            // Setting internal vars:
            if ($this->getBackendUser()->uc['neverHideAtCopy']) {
                $tce->neverHideAtCopy = 1;
            }
            $tce->debug = 0;
            $tce->disableRTE = $this->disableRTE;

            // Loading TCEmain with data:
            $tce->start($this->data, $this->cmd);
            if (is_array($this->mirror)) {
                $tce->setMirror($this->mirror);
            }

            // If pages are being edited, we set an instruction about updating the page tree after this operation.
            if (isset($this->data['pages'])) {
                BackendUtility::setUpdateSignal('updatePageTree');
            }


            // Checking referer / executing
            $refInfo = parse_url(GeneralUtility::getIndpEnv('HTTP_REFERER'));
            $httpHost = GeneralUtility::getIndpEnv('TYPO3_HOST_ONLY');
            if ($httpHost!=$refInfo['host'] &&
                $this->vC!=$this->getBackendUser()->veriCode() &&
                !$GLOBALS['TYPO3_CONF_VARS']['SYS']['doNotCheckReferer']) {
                $tce->log(
                    '',
                    0,
                    0,
                    0,
                    1,
                    "Referer host '%s' and server host '%s' did not match and veriCode was not valid either!",
                    1,
                    array($refInfo['host'], $httpHost)
                );
            } else {
                // Perform the saving operation with TCEmain:
                $tce->process_uploads($_FILES);
                $tce->process_datamap();
                $tce->process_cmdmap();

                // If there was saved any new items, load them:
                if (count($tce->substNEWwithIDs_table)) {
                    // Resetting editconf:
                    $this->editconf = array();

                    // Traverse all new records and forge the content of ->editconf
                    // so we can continue to EDIT these records!
                    foreach ($tce->substNEWwithIDs_table as $nKey => $nTable) {
                        $editId = $tce->substNEWwithIDs[$nKey];
                        // translate new id to the workspace version:
                        if ($versionRec = BackendUtility::getWorkspaceVersionOfRecord($this->getBackendUser()->workspace, $nTable, $editId, 'uid')) {
                            $editId = $versionRec['uid'];
                        }

                        $this->editconf[$nTable][$editId]='edit';
                        if ($nTable=='pages' && $this->retUrl!='dummy.php' && $this->returnNewPageId) {
                            $this->retUrl .= '&id='.$tce->substNEWwithIDs[$nKey];
                        }
                    }
                }

                $tce->printLogErrorMessages(
                    isset($_POST['_saveandclosedok_x']) ?
                    $this->retUrl :
                    // popView will not be invoked here,
                    // because the information from the submit button for save/view will be lost ....
                    // But does it matter if there is an error anyways?
                    $this->R_URL_parts['path'].'?'.GeneralUtility::implodeArrayForUrl('', $this->R_URL_getvars)
                );
            }
        }

        if ($fakeAdmin) {
            TcBeuserUtility::removeFakeAdmin();
        }

        if (isset($_POST['_saveandclosedok']) || $this->closeDoc < 0) {
            //If any new items has been save, the document is CLOSED because
            // if not, we just get that element re-listed as new. And we don't want that!
            $this->closeDocument();
        }
    }

    public function init()
    {
        parent::init();

        $this->id = 0;
        $this->search_field = GeneralUtility::_GP('search_field');
        $this->pointer = MathUtility::forceIntegerInRange(
            GeneralUtility::_GP('pointer'),
            0,
            100000
        );

        $set = GeneralUtility::_GET('SET');
        if ($set['function'] == 'action') {
            $this->MOD_SETTINGS['function'] = $set['function'];
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
                '1' => $this->getLanguageService()->getLL('list-filemounts'),
                '2' => $this->getLanguageService()->getLL('create-filemount'),
            ),
        );
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

        if (!empty($this->editconf)) {
            $this->MOD_SETTINGS['function'] = 'edit';
        }

        switch ((string)$this->MOD_SETTINGS['function']) {
            case '1':
                // list Filemounts
                $content .= $this->getFilemountList();
                $this->getButtons();
                break;

            case '2':
                // create new Filemount
                $data = GeneralUtility::_GP('data');
                $dataKey = is_array($data) ? array_keys($data[$this->table]): array();
                if (is_numeric($dataKey[0])) {
                    $this->editconf = array($this->table => array($dataKey[0] => 'edit'));
                } else { // create new user
                    $this->editconf = array($this->table => array(0=>'new'));
                }
                $content .= $this->getFilemountEdit();
                // get Save, close, etc button
                $this->getSaveButton();
                break;

            case 'edit':
                $content .= $this->getFilemountEdit();
                // get Save, close, etc button
                $this->getSaveButton();
                break;

            case 'action':
                $this->processData();
                HttpUtility::redirect(GeneralUtility::_GP('redirect'));
                break;
        }

        return $content;
    }

    public function getFilemountList()
    {
        $content = '';

        /** @var \dkd\TcBeuser\Utility\RecordListUtility $dblist */
        $dblist = GeneralUtility::makeInstance('dkd\\TcBeuser\\Utility\\RecordListUtility');
        $dblist->script = GeneralUtility::linkThisScript();
        $dblist->alternateBgColors = true;
        $dblist->calcPerms = $this->getBackendUser()->calcPerms($this->pageinfo);
        $dblist->showFields = array('title', 'path');
        $dblist->disableControls = array(
            'edit' => false,
            'detail' => true,
            'import' => true,
            'delete' => false,
            'hide' => false
        );

        $dblist->start(0, $this->table, $this->pointer, $this->search_field);

        // default sorting, needs to be set after $dblist->start()
        $sort = GeneralUtility::_GET('sortField');
        if (is_null($sort)) {
            $dblist->sortField = 'title';
        }
        $dblist->generateList();
        $content .= $dblist->HTMLcode;

        // Add JavaScript functions to the page:
        $this->moduleTemplate->addJavaScriptCode(
            'FilemountListInlineJS',
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
                ->setTitle($this->getLanguageService()->getLL('search-filemount'))
                ->setIcon($this->iconFactory->getIcon('actions-search', Icon::SIZE_SMALL));
            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton(
                $searchButton,
                ButtonBar::BUTTON_POSITION_LEFT,
                90
            );
        }

        // make new filemount link
        $content .= '<!--
						Link for creating a new record:
					-->
		<div id="typo3-newRecordLink">
		<a href="' . BackendUtility::getModuleUrl($this->moduleName, array('SET[function]' => 2)) . '">' .
            $this->iconFactory->getIcon('actions-document-new', Icon::SIZE_SMALL)->render() . ' ' .
            $this->getLanguageService()->getLL('create-filemount') .
            '</a>';

        $content = '<form action="' . htmlspecialchars($dblist->listURL()) . '" method="post" name="dblistForm">' .
            $content .
            '<input type="hidden" name="cmd_table" /><input type="hidden" name="cmd" /></form>';

        return $searchBox . $content;
    }

    public function getFilemountEdit()
    {
        // lets fake admin
        $fakeAdmin = false;

        if ($this->getBackendUser()->user['admin'] != 1) {
            //make fake Admin
            TcBeuserUtility::fakeAdmin();
            $fakeAdmin = true;
        }

        $content = '';

        //show warning
        $this->error[] = array(
            'warning',
            $this->getLanguageService()->getLL('filemount-msg')
        );

        // Creating the editing form, wrap it with buttons, document selector etc.
        //show only these columns

        /** @var FormResultCompiler formResultCompiler */
        $formResultCompiler = GeneralUtility::makeInstance(FormResultCompiler::class);

        // Creating the editing form, wrap it with buttons, document selector etc.
        /** @var \dkd\TcBeuser\Utility\EditFormUtility editForm */
        $this->editForm = GeneralUtility::makeInstance('dkd\\TcBeuser\\Utility\\EditFormUtility');
        $this->editForm->formResultCompiler = $formResultCompiler;
        $this->editForm->columnsOnly = 'title,path';
        $this->editForm->editconf = $this->editconf;
        $this->editForm->error = $this->error;
        $this->editForm->inputData = $this->data;

        // set base to fileadmin
        // Todo: make this configurable?
        if ($this->editconf[$this->table][0] == 'new') {
            $this->editForm->overrideVals = array(
                $this->table => array(
                    'base' => '1'
                )
            );
        }

        $editForm = $this->editForm->makeEditForm();
        $this->viewId = $this->editForm->viewId;

        if ($editForm) {
            // ingo.renner@dkd.de
            reset($this->editForm->elementsData);
            $this->firstEl = current($this->editForm->elementsData);

            if ($this->viewId) {
                // Module configuration:
                $this->modTSconfig = BackendUtility::getModTSconfig($this->viewId, 'mod.xMOD_alt_doc');
            } else {
                $this->modTSconfig=array();
            }

            $content = $formResultCompiler->JStop();
            $content .= $this->compileForm($editForm);
            $content .= $formResultCompiler->printNeededJSFunctions();
            $content .= '</form>';
        }

        if ($fakeAdmin) {
            TcBeuserUtility::removeFakeAdmin();
        }

        return $content;
    }
}
