<?php
namespace dkd\TcBeuser\Utility;

use TYPO3\CMS\Backend\Form\Exception\AccessDeniedException;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Form\FormResultCompiler;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Backend\Form\Utility\FormEngineUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/***************************************************************
*  Copyright notice
*
*  (c) 2006 dkd-ivan
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

class EditFormUtility
{

    public $elementsData;
    public $errorC;
    public $newC;
    public $editconf;
    public $tceforms;
    public $inputData;

    /**
     * Set to the URL of this script including variables which is needed to re-display the form. See main()
     *
     * @var string
     */
    public $R_URI;

    /**
     * Array of values to force being set (as hidden fields). Will be set as $this->defVals
     * IF defVals does not exist.
     *
     * @var array
     */
    public $overrideVals;

    /**
     * Is set to the pid value of the last shown record - thus indicating which page to
     * show when clicking the SAVE/VIEW button
     *
     * @var int
     */
    public $viewId;

    /**
     * Is set to additional parameters (like "&L=xxx") if the record supports it.
     *
     * @var string
     */
    public $viewId_addParams;

    /**
     * Is loaded with the "title" of the currently "open document" - this is used in the
     * Document Selector box. (see makeDocSel())
     *
     * @var string
     */
    public $storeTitle = '';

    /**
     * Alternative title for the document handler.
     *
     * @var string
     */
    public $recTitle;

    /**
     * Used internally to disable the storage of the document reference (eg. new records)
     *
     * @var bool
     */
    public $dontStoreDocumentRef = 0;

    /**
     * @var FormResultCompiler
     */
    public $formResultCompiler;

    /**
     * Commalist of fieldnames to edit. The point is IF you specify this list, only those
     * fields will be rendered in the form. Otherwise all (available) fields in the record
     * is shown according to the types configuration in $GLOBALS['TCA']
     *
     * @var bool
     */
    public $columnsOnly;

    /**
     * Array contains the error string from main module class
     *
     * @var array
     */
    public $error;

    public function makeEditForm()
    {
        // Initialize variables:
        $this->elementsData = array();
        $this->errorC = 0;
        $this->newC = 0;
        $editForm = '';
        $trData = null;
        $beUser = $this->getBackendUser();
        // Traverse the GPvar edit array
        // Tables:
        foreach ($this->editconf as $table => $conf) {
            if (is_array($conf) && $GLOBALS['TCA'][$table] && $beUser->check('tables_modify', $table)) {
                // Traverse the keys/comments of each table (keys can be a commalist of uids)
                foreach ($conf as $cKey => $command) {
                    if ($command == 'edit' || $command == 'new') {
                        // Get the ids:
                        $ids = GeneralUtility::trimExplode(',', $cKey, true);
                        // Traverse the ids:
                        foreach ($ids as $theUid) {
                            // Don't save this document title in the document selector if the document is new.
                            if ($command === 'new') {
                                $this->dontStoreDocumentRef = 1;
                            }

                            /** @var TcaDatabaseRecord $formDataGroup */
                            $formDataGroup = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
                            /** @var FormDataCompiler $formDataCompiler */
                            $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class, $formDataGroup);
                            /** @var NodeFactory $nodeFactory */
                            $nodeFactory = GeneralUtility::makeInstance(NodeFactory::class);

                            try {
                                // Reset viewId - it should hold data of last entry only
                                $this->viewId = 0;
                                $this->viewId_addParams = '';

                                $formDataCompilerInput = [
                                    'tableName' => $table,
                                    'vanillaUid' => (int)$theUid,
                                    'command' => $command,
                                    'returnUrl' => $this->R_URI,
                                ];
                                if (is_array($this->overrideVals) && is_array($this->overrideVals[$table])) {
                                    $formDataCompilerInput['overrideValues'] = $this->overrideVals[$table];
                                }

                                $formData = $formDataCompiler->compile($formDataCompilerInput);

                                // Set this->viewId if possible
                                if ($command === 'new'
                                    && $table !== 'pages'
                                    && !empty($formData['parentPageRow']['uid'])
                                ) {
                                    $this->viewId = $formData['parentPageRow']['uid'];
                                } else {
                                    if ($table == 'pages') {
                                        $this->viewId = $formData['databaseRow']['uid'];
                                    } elseif (!empty($formData['parentPageRow']['uid'])) {
                                        $this->viewId = $formData['parentPageRow']['uid'];
                                        // Adding "&L=xx" if the record being edited has a languageField
                                        // with a value larger than zero!
                                        if (!empty($formData['processedTca']['ctrl']['languageField'])
                                            && is_array($formData['databaseRow'][$formData['processedTca']['ctrl']['languageField']])
                                            && $formData['databaseRow'][$formData['processedTca']['ctrl']['languageField']][0] > 0
                                        ) {
                                            $this->viewId_addParams = '&L=' . $formData['databaseRow'][$formData['processedTca']['ctrl']['languageField']][0];
                                        }
                                    }
                                }

                                // Determine if delete button can be shown
                                $deleteAccess = false;
                                if ($command === 'edit') {
                                    $permission = $formData['userPermissionOnPage'];
                                    if ($formData['tableName'] === 'pages') {
                                        $deleteAccess = $permission & Permission::PAGE_DELETE ? true : false;
                                    } else {
                                        $deleteAccess = $permission & Permission::CONTENT_EDIT ? true : false;
                                    }
                                }

                                // Display "is-locked" message:
                                if ($command === 'edit') {
                                    $lockInfo = BackendUtility::isRecordLocked($table, $formData['databaseRow']['uid']);
                                    if ($lockInfo) {
                                        /** @var $flashMessage \TYPO3\CMS\Core\Messaging\FlashMessage */
                                        $flashMessage = GeneralUtility::makeInstance(
                                            FlashMessage::class,
                                            $lockInfo['msg'],
                                            '',
                                            FlashMessage::WARNING
                                        );
                                        /** @var $flashMessageService \TYPO3\CMS\Core\Messaging\FlashMessageService */
                                        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
                                        /** @var $defaultFlashMessageQueue FlashMessageQueue */
                                        $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
                                        $defaultFlashMessageQueue->enqueue($flashMessage);
                                    }
                                }

                                // Record title
                                if (!$this->storeTitle) {
                                    $this->storeTitle = $this->recTitle
                                        ? htmlspecialchars($this->recTitle)
                                        : BackendUtility::getRecordTitle(
                                            $table,
                                            FormEngineUtility::databaseRowCompatibility($formData['databaseRow']),
                                            true
                                        );
                                }

                                $this->elementsData[] = array(
                                    'table' => $table,
                                    'uid' => $formData['databaseRow']['uid'],
                                    'pid' => $formData['databaseRow']['pid'],
                                    'cmd' => $command,
                                    'deleteAccess' => $deleteAccess
                                );

                                if ($command !== 'new') {
                                    BackendUtility::lockRecords(
                                        $table,
                                        $formData['databaseRow']['uid'],
                                        $table === 'tt_content' ? $formData['databaseRow']['pid'] : 0
                                    );
                                }

                                //dkd-kartolo
                                //put feusers data in the be_users form as new be_users
                                if (!empty($this->feID) && $table=='be_users') {
                                    $res = $this->getDatabaseConnection()->exec_SELECTquery(
                                        '*',
                                        'fe_users',
                                        'uid = ' . $this->feID
                                    );
                                    $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);
                                    $formData['databaseRow']['username'] = $row['username'];
                                    $formData['databaseRow']['realName'] = $row['name'] ?:
                                        $row['first_name'] . ' ' . $row['last_name'];
                                    $formData['databaseRow']['email'] = $row['email'];
                                    $formData['databaseRow']['password'] = $row['password'];
                                }


                                //dkd-kartolo
                                //put list of users in the 'members' field
                                //used to render list of member in the be_groups form
                                if ($table == 'be_groups') {
                                    $res = $this->getDatabaseConnection()->exec_SELECTquery(
                                        '*',
                                        'be_users',
                                        'usergroup like ' . $this->getDatabaseConnection()->fullQuoteStr(
                                            '%'.$formData['databaseRow']['uid'].'%',
                                            'be_users'
                                        ).
                                        BackendUtility::deleteClause('be_users')
                                    );

                                    $users = array();
                                    if ($this->getDatabaseConnection()->sql_num_rows($res)>0) {
                                        while ($row = $this->getDatabaseConnection()->sql_fetch_assoc($res)) {
                                            if (GeneralUtility::inList($row['usergroup'], $formData['databaseRow']['uid'])) {
                                                $users[] = $row['uid'];
                                                //$users[] = $row['uid'].'|'.$row['username'];
                                            }
                                        }
                                    }
                                    //$users = implode(',', $users);
                                    $formData['databaseRow']['members'] = $users;
                                }

                                //dkd-kartolo
                                //mod3, read TSconfig createWithPrefix
                                if ($table == 'be_groups') {
                                    $tsConfig = $this->getBackendUser()->userTS['tx_tcbeuser.'];
                                    if (is_array($tsConfig)) {
                                        if (array_key_exists('createWithPrefix', $tsConfig) && $command == 'new') {
                                            $formData['databaseRow']['title'] = $tsConfig['createWithPrefix'];
                                        }
                                    }

                                    if (strstr($formData['databaseRow']['TSconfig'], 'tx_tcbeuser') &&
                                        $this->getBackendUser()->user['admin'] != 1
                                    ) {
                                        $columnsOnly = explode(',', $this->columnsOnly);
                                        $this->columnsOnly = implode(',', ArrayUtility::removeArrayEntryByValue($columnsOnly, 'TSconfig'));
                                        $this->error[] = array('info',$GLOBALS['LANG']->getLL('tsconfig-disabled'));
                                    }
                                }

                                // Set list if only specific fields should be rendered. This will trigger
                                // ListOfFieldsContainer instead of FullRecordContainer in OuterWrapContainer
                                if ($this->columnsOnly) {
                                    if (is_array($this->columnsOnly)) {
                                        $formData['fieldListToRender'] = $this->columnsOnly[$table];
                                    } else {
                                        $formData['fieldListToRender'] = $this->columnsOnly;
                                    }
                                }

                                $formData['renderType'] = 'outerWrapContainer';
                                $formResult = $nodeFactory->create($formData)->render();

                                $html = $formResult['html'];

                                $formResult['html'] = '';
                                $formResult['doSaveFieldName'] = 'doSave';

                                // @todo: Put all the stuff into FormEngine as final "compiler" class
                                // @todo: This is done here for now to not rewrite JStop()
                                // @todo: and printNeededJSFunctions() now
                                $this->formResultCompiler->mergeResult($formResult);

                                // Seems the pid is set as hidden field (again) at end?!
                                if ($command == 'new') {
                                    // @todo: looks ugly
                                    $html .= LF
                                        . '<input type="hidden"'
                                        . ' name="data[' . htmlspecialchars($table) . '][' . htmlspecialchars($formData['databaseRow']['uid']) . '][pid]"'
                                        . ' value="' . (int)$formData['databaseRow']['pid'] . '" />';
                                    $this->newC++;
                                }

                                // show error
                                if (is_array($this->error)) {
                                    foreach ($this->error as $errorArray) {
                                        /** @var $flashMessage \TYPO3\CMS\Core\Messaging\FlashMessage */
                                        $flashMessage = GeneralUtility::makeInstance(
                                            FlashMessage::class,
                                            $errorArray[1],
                                            '',
                                            ($errorArray[0]=='error' ? FlashMessage::ERROR : FlashMessage::WARNING)
                                        );
                                        /** @var $flashMessageService \TYPO3\CMS\Core\Messaging\FlashMessageService */
                                        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
                                        /** @var $defaultFlashMessageQueue FlashMessageQueue */
                                        $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
                                        $defaultFlashMessageQueue->enqueue($flashMessage);
                                    }
                                }

                                $editForm .= $html;
                            } catch (AccessDeniedException $e) {
                                $this->errorC++;
                                // Try to fetch error message from "recordInternals" be user object
                                // @todo: This construct should be logged and localized and de-uglified
                                $message = $beUser->errorMsg;
                                if (empty($message)) {
                                    // Create message from exception.
                                    $message = $e->getMessage() . ' ' . $e->getCode();
                                }
                                $editForm .= $this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:labels.noEditPermission', true)
                                    . '<br /><br />' . htmlspecialchars($message) . '<br /><br />';
                            }
                        } // End of for each uid
                    }
                }
            }
        }
        return $editForm;
    }

    /**
     * @return \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Returns LanguageService
     *
     * @return \TYPO3\CMS\Lang\LanguageService
     */
    protected function getLanguageService()
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Returns the database connection
     *
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}
