<?php
namespace dkd\TcBeuser\Utility;

/***************************************************************
*  Copyright notice
*
*  (c) 2006 Ingo Renner (ingo.renner@dkd.de)
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

use TYPO3\CMS\Backend\RecordList\RecordListGetTableHookInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Recordlist\RecordList\DatabaseRecordList;   
use TYPO3\CMS\Recordlist\RecordList\RecordListHookInterface;

/**
 * class for listing DB tables in tc_beuser
 * $Id$
 *
 * @author Ingo Renner <ingo.renner@dkd.de>
 * @author Ivan Kartolo <ivan.kartolo@dkd.de>
 */
class RecordListUtility extends DatabaseRecordList
{

    public $showFields;
    public $userMainGroupOnly = false;
    public $hideDisabledRecords = false;
    /**
     * @var array $disableControls: disable particular features (=icons)
     */
    public $disableControls = array(
        'detail' => false,    // disable detail view
        'import'=> false,    // disable import feature
        'edit' => false,    // disable editing
        'hide' => false,    // disable hiding
        'delete' => false,    // disable deleting
    );

    /**
     * Traverses the table(s) to be listed and renders the output code for each:
     * The HTML is accumulated in $this->HTMLcode
     * Finishes off with a stopper-gif
     *
     * @return void
     */
    public function generateList()
    {

            // Set page record in header
        $this->pageRecord = BackendUtility::getRecordWSOL('pages', $this->id);

        $backendUser = $this->getBackendUserAuthentication();

        foreach ($GLOBALS['TCA'] as $tableName => $_) {
                // Checking if the table should be rendered:
                // Checks that we see only permitted/requested tables:
            if ((!$this->table || $tableName==$this->table) &&
                (!$this->tableList || GeneralUtility::inList($this->tableList, $tableName)) &&
                $backendUser->check('tables_select', $tableName)
            ) {
                    // Hide tables which are configured via TSConfig not to be shown (also works for admins):
                if (GeneralUtility::inList($this->hideTables, $tableName)) {
                    continue;
                }

                    // iLimit is set depending on whether we're in single- or multi-table mode
                if ($this->table) {
                    $this->iLimit = (isset($GLOBALS['TCA'][$tableName]['interface']['maxSingleDBListItems']) ?
                        intval($GLOBALS['TCA'][$tableName]['interface']['maxSingleDBListItems']) :
                        $this->itemsLimitSingleTable);
                } else {
                    $this->iLimit = (isset($GLOBALS['TCA'][$tableName]['interface']['maxDBListItems']) ?
                        intval($GLOBALS['TCA'][$tableName]['interface']['maxDBListItems']) :
                        $this->itemsLimitPerTable);
                }
                if ($this->showLimit) {
                    $this->iLimit = $this->showLimit;
                }

                    // Setting fields to select:
                if ($this->allFields) {
                    $fields = $this->makeFieldList($tableName);
                    $fields[] = 'tstamp';
                    $fields[] = 'crdate';
                    $fields[] = '_PATH_';
                    $fields[] = '_CONTROL_';
                    if (is_array($this->setFields[$tableName])) {
                        $fields = array_intersect($fields, $this->setFields[$tableName]);
                    } else {
                        $fields = array();
                    }
                } elseif (is_array($this->showFields)) {
                    $fields = $this->showFields;
                } else {
                    $fields = array();
                }

                    // Find ID to use (might be different for "versioning_followPages" tables)
                if (intval($this->searchLevels) == 0) {
                    $this->pidSelect = 'pid='.intval($this->id);
                }
                    // Finally, render the list:
                $this->HTMLcode .= $this->getTable($tableName, $this->id, implode(',', $fields));
            }
        }
    }

    /**
     * Creates the listing of records from a single table
     *
     * @param string $table : Table name
     * @param integer $id : Page id
     * @param string $rowList: List of fields to show in the listing.
     * Pseudo fields will be added including the record header.
     * @return string $out: HTML table with the listing for the record.
     */
    public function getTable($table, $id, $rowList = '')
    {
        $rowListArray = GeneralUtility::trimExplode(',', $rowList, true);
        // if no columns have been specified, show description (if configured)
        if (!empty($GLOBALS['TCA'][$table]['ctrl']['descriptionColumn']) && empty($rowListArray)) {
            array_push($rowListArray, $GLOBALS['TCA'][$table]['ctrl']['descriptionColumn']);
        }
        $backendUser = $this->getBackendUserAuthentication();
        $lang = $this->getLanguageService();
        $db = $this->getDatabaseConnection();
        // Init
        $addWhere = '';
        $titleCol = $GLOBALS['TCA'][$table]['ctrl']['label'];
        $thumbsCol = $GLOBALS['TCA'][$table]['ctrl']['thumbnail'];
        $l10nEnabled = $GLOBALS['TCA'][$table]['ctrl']['languageField']
            && $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']
            && !$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerTable'];
        $tableCollapsed = (bool)$this->tablesCollapsed[$table];
        // prepare space icon
        $this->spaceIcon = '<span class="btn btn-default disabled">' .
            $this->iconFactory->getIcon('empty-empty', Icon::SIZE_SMALL)->render() .
            '</span>';

        // Cleaning rowlist for duplicates and place the $titleCol as the first column always!
        $this->fieldArray = array();

        // ingo.renner@dkd.de
        $this->fieldArray = explode(',', $rowList);

        // Control-Panel
        if (!GeneralUtility::inList($rowList, '_CONTROL_')) {
            $this->fieldArray[] = '_CONTROL_';
        }
        // Clipboard
        if ($this->showClipboard) {
            $this->fieldArray[] = '_CLIPBOARD_';
        }
        // Path
        if ($this->searchLevels) {
            $this->fieldArray[] = '_PATH_';
        }
        // Localization
        if ($this->localizationView && $l10nEnabled) {
            $this->fieldArray[] = '_LOCALIZATION_';
            $this->fieldArray[] = '_LOCALIZATION_b';
            $addWhere .= ' AND (
				' . $GLOBALS['TCA'][$table]['ctrl']['languageField'] . '<=0
				OR
				' . $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] . ' = 0
			)';
        }
        // Cleaning up:
        $this->fieldArray = array_unique(array_merge($this->fieldArray, $rowListArray));
        if ($this->noControlPanels) {
            $tempArray = array_flip($this->fieldArray);
            unset($tempArray['_CONTROL_']);
            unset($tempArray['_CLIPBOARD_']);
            $this->fieldArray = array_keys($tempArray);
        }
        // Creating the list of fields to include in the SQL query:
        $selectFields = $this->fieldArray;
        $selectFields[] = 'uid';
        $selectFields[] = 'pid';
        // adding column for thumbnails
        if ($thumbsCol) {
            $selectFields[] = $thumbsCol;
        }
        if ($table == 'pages') {
            $selectFields[] = 'module';
            $selectFields[] = 'extendToSubpages';
            $selectFields[] = 'nav_hide';
            $selectFields[] = 'doktype';
            $selectFields[] = 'shortcut';
            $selectFields[] = 'shortcut_mode';
            $selectFields[] = 'mount_pid';
        }
        if (is_array($GLOBALS['TCA'][$table]['ctrl']['enablecolumns'])) {
            $selectFields = array_merge($selectFields, $GLOBALS['TCA'][$table]['ctrl']['enablecolumns']);
        }
        foreach (array('type', 'typeicon_column', 'editlock') as $field) {
            if ($GLOBALS['TCA'][$table]['ctrl'][$field]) {
                $selectFields[] = $GLOBALS['TCA'][$table]['ctrl'][$field];
            }
        }
        if ($GLOBALS['TCA'][$table]['ctrl']['versioningWS']) {
            $selectFields[] = 't3ver_id';
            $selectFields[] = 't3ver_state';
            $selectFields[] = 't3ver_wsid';
        }
        if ($l10nEnabled) {
            $selectFields[] = $GLOBALS['TCA'][$table]['ctrl']['languageField'];
            $selectFields[] = $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'];
        }
        if ($GLOBALS['TCA'][$table]['ctrl']['label_alt']) {
            $selectFields = array_merge(
                $selectFields,
                GeneralUtility::trimExplode(',', $GLOBALS['TCA'][$table]['ctrl']['label_alt'], true)
            );
        }
        // Unique list!
        $selectFields = array_unique($selectFields);
        $fieldListFields = $this->makeFieldList($table, 1);
        if (empty($fieldListFields) && $GLOBALS['TYPO3_CONF_VARS']['BE']['debug']) {
            $message = sprintf(
                $lang->sL('LLL:EXT:lang/locallang_mod_web_list.xlf:missingTcaColumnsMessage', true),
                $table,
                $table
            );
            $messageTitle = $lang->sL('LLL:EXT:lang/locallang_mod_web_list.xlf:missingTcaColumnsMessageTitle', true);
            /** @var FlashMessage $flashMessage */
            $flashMessage = GeneralUtility::makeInstance(
                FlashMessage::class,
                $message,
                $messageTitle,
                FlashMessage::WARNING,
                true
            );
            /** @var $flashMessageService FlashMessageService */
            $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
            /** @var $defaultFlashMessageQueue \TYPO3\CMS\Core\Messaging\FlashMessageQueue */
            $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
            $defaultFlashMessageQueue->enqueue($flashMessage);
        }
        // Making sure that the fields in the field-list ARE in the field-list from TCA!
        $selectFields = array_intersect($selectFields, $fieldListFields);
        // Implode it into a list of fields for the SQL-statement.
        $selFieldList = implode(',', $selectFields);
        $this->selFieldList = $selFieldList;
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['typo3/class.db_list_extra.inc']['getTable'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['typo3/class.db_list_extra.inc']['getTable'] as $classData) {
                $hookObject = GeneralUtility::getUserObj($classData);
                if (!$hookObject instanceof RecordListGetTableHookInterface) {
                    throw new \UnexpectedValueException(
                        '$hookObject must implement interface ' . RecordListGetTableHookInterface::class,
                        1195114460
                    );
                }
                $hookObject->getDBlistQuery($table, $id, $addWhere, $selFieldList, $this);
            }
        }

            // ingo.renner@dkd.de
        if ($this->hideDisabledRecords) {
            $addWhere .= ' AND '.$table.'.'
                .$GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled']
                .' = 0';
        }

            //ingo.renner@dkd.de
        if ($this->getBackendUserAuthentication()->user['admin'] == '0' && $table == 'be_users') {
            $addWhere .= ' AND admin = 0';
            $addWhere .= ' AND username NOT LIKE ("_cli%") ';
        }

        //dkd-kartolo
        //mod2, exclude fe_user which is also be_user
        if ($table == 'fe_users') {
            $addWhere .= ' AND username not in '.$this->excludeBE;
        }

        //dkd-kartolo
        //mod3, config dontShowPrefix
        if ($table == 'be_groups' && $this->getBackendUserAuthentication()->user['admin']!= '1') {
            $groupID = implode(',', TcBeuserUtility::showGroupID());
            if (!empty($groupID)) {
                $addWhere .= ' AND uid in ('.$groupID.')';
            } else {
                $addWhere .= ' AND uid not in ('.TcBeuserUtility::getAllGroupsID().')';
            }
        }

        // Create the SQL query for selecting the elements in the listing:
        // do not do paging when outputting as CSV
        if ($this->csvOutput) {
            $this->iLimit = 0;
        }
        if ($this->firstElementNumber > 2 && $this->iLimit > 0) {
            // Get the two previous rows for sorting if displaying page > 1
            $this->firstElementNumber = $this->firstElementNumber - 2;
            $this->iLimit = $this->iLimit + 2;
            // (API function from TYPO3\CMS\Recordlist\RecordList\AbstractDatabaseRecordList)
            $queryParts = $this->makeQueryArray($table, $id, $addWhere, $selFieldList);
            $this->firstElementNumber = $this->firstElementNumber + 2;
            $this->iLimit = $this->iLimit - 2;
        } else {
            // (API function from TYPO3\CMS\Recordlist\RecordList\AbstractDatabaseRecordList)
            $queryParts = $this->makeQueryArray($table, $id, $addWhere, $selFieldList);
        }

        // Finding the total amount of records on the page
        // (API function from TYPO3\CMS\Recordlist\RecordList\AbstractDatabaseRecordList)
        if (version_compare(TYPO3_version, '8.0.0', '<')) {
            $this->setTotalItems($queryParts);
        } else {
            $this->setTotalItems($table, $this->id, array($queryParts['WHERE']));
        }

        // Init:
        $dbCount = 0;
        $out = '';
        $tableHeader = '';
        $result = null;
        $listOnlyInSingleTableMode = $this->listOnlyInSingleTableMode && !$this->table;
        // If the count query returned any number of records, we perform the real query,
        // selecting records.
        if ($this->totalItems) {
            // Fetch records only if not in single table mode
            if ($listOnlyInSingleTableMode) {
                $dbCount = $this->totalItems;
            } else {
                // Set the showLimit to the number of records when outputting as CSV
                if ($this->csvOutput) {
                    $this->showLimit = $this->totalItems;
                    $this->iLimit = $this->totalItems;
                }
                $result = $db->exec_SELECT_queryArray($queryParts);
                $dbCount = $db->sql_num_rows($result);
            }
        }
        // If any records was selected, render the list:
        if ($dbCount) {
            $tableTitle = $lang->sL($GLOBALS['TCA'][$table]['ctrl']['title'], true);
            if ($tableTitle === '') {
                $tableTitle = $table;
            }
            // Header line is drawn
            $theData = array();
            if ($this->disableSingleTableView) {
                $theData[$titleCol] = '<span class="c-table">' . BackendUtility::wrapInHelp($table, '', $tableTitle)
                    . '</span> (<span class="t3js-table-total-items">' . $this->totalItems . '</span>)';
            } else {
                $icon = $this->table
                    ? '<span title="' . $lang->getLL('contractView', true) . '">' .
                        $this->iconFactory->getIcon('actions-view-table-collapse', Icon::SIZE_SMALL)->render() .
                        '</span>'
                    : '<span title="' . $lang->getLL('expandView', true) . '">' .
                        $this->iconFactory->getIcon('actions-view-table-expand', Icon::SIZE_SMALL)->render() .
                    '</span>';
                $theData[$titleCol] = $this->linkWrapTable(
                    $table,
                    $tableTitle . ' (<span class="t3js-table-total-items">' . $this->totalItems . '</span>) ' . $icon
                );
            }
            if ($listOnlyInSingleTableMode) {
                $tableHeader .= BackendUtility::wrapInHelp($table, '', $theData[$titleCol]);
            } else {
                // Render collapse button if in multi table mode
                $collapseIcon = '';
                if (!$this->table) {
                    $href = htmlspecialchars(
                        $this->listURL() . '&collapse[' . $table . ']=' . ($tableCollapsed ? '0' : '1')
                    );
                    $title = $tableCollapsed
                        ? $lang->sL('LLL:EXT:lang/locallang_core.xlf:labels.expandTable', true)
                        : $lang->sL('LLL:EXT:lang/locallang_core.xlf:labels.collapseTable', true);
                    $icon = '<span class="collapseIcon">' .
                        $this->iconFactory->getIcon(
                            ($tableCollapsed ? 'actions-view-list-expand' : 'actions-view-list-collapse'),
                            Icon::SIZE_SMALL
                        )->render() .
                        '</span>';
                    $collapseIcon = '<a href="' . $href . '"' .
                        ' title="' . $title .  '"' .
                        ' class="pull-right t3js-toggle-recordlist"' .
                        ' data-table="' . htmlspecialchars($table) . '"' .
                        ' data-toggle="collapse" data-target="#recordlist-' . htmlspecialchars($table) . '">'
                        . $icon . '</a>';
                }
                $tableHeader .= $theData[$titleCol] . $collapseIcon;
            }
            // Render table rows only if in multi table view or if in single table view
            $rowOutput = '';
            if (!$listOnlyInSingleTableMode || $this->table) {
                // Fixing an order table for sortby tables
                $this->currentTable = array();
                $currentIdList = array();
                $doSort = $GLOBALS['TCA'][$table]['ctrl']['sortby'] && !$this->sortField;
                $prevUid = 0;
                $prevPrevUid = 0;
                // Get first two rows and initialize prevPrevUid and prevUid if on page > 1
                if ($this->firstElementNumber > 2 && $this->iLimit > 0) {
                    $row = $db->sql_fetch_assoc($result);
                    $prevPrevUid = -((int)$row['uid']);
                    $row = $db->sql_fetch_assoc($result);
                    $prevUid = $row['uid'];
                }
                $accRows = array();
                // Accumulate rows here
                while ($row = $db->sql_fetch_assoc($result)) {
                    if (!$this->isRowListingConditionFulfilled($table, $row)) {
                        continue;
                    }
                    // In offline workspace, look for alternative record:
                    BackendUtility::workspaceOL($table, $row, $backendUser->workspace, true);
                    if (is_array($row)) {
                        $accRows[] = $row;
                        $currentIdList[] = $row['uid'];
                        if ($doSort) {
                            if ($prevUid) {
                                $this->currentTable['prev'][$row['uid']] = $prevPrevUid;
                                $this->currentTable['next'][$prevUid] = '-' . $row['uid'];
                                $this->currentTable['prevUid'][$row['uid']] = $prevUid;
                            }
                            $prevPrevUid = isset($this->currentTable['prev'][$row['uid']]) ? -$prevUid : $row['pid'];
                            $prevUid = $row['uid'];
                        }
                    }
                }
                $db->sql_free_result($result);
                $this->totalRowCount = count($accRows);
                // CSV initiated
                if ($this->csvOutput) {
                    $this->initCSV();
                }
                // Render items:
                $this->CBnames = array();
                $this->duplicateStack = array();
                $this->eCounter = $this->firstElementNumber;
                $cc = 0;
                foreach ($accRows as $row) {
                    // Render item row if counter < limit
                    if ($cc < $this->iLimit) {
                        $cc++;
                        $this->translations = false;
                        $rowOutput .= $this->renderListRow($table, $row, $cc, $titleCol, $thumbsCol);
                        // If localization view is enabled it means that the selected records are
                        // either default or All language and here we will not select translations
                        // which point to the main record:
                        if ($this->localizationView && $l10nEnabled) {
                            // For each available translation, render the record:
                            if (is_array($this->translations)) {
                                foreach ($this->translations as $lRow) {
                                    // $lRow isn't always what we want - if record was moved we've to work with the
                                    // placeholder records otherwise the list is messed up a bit
                                    if ($row['_MOVE_PLH_uid'] && $row['_MOVE_PLH_pid']) {
                                        $where = 't3ver_move_id="' . (int)$lRow['uid'] . '"' .
                                        ' AND pid="' . $row['_MOVE_PLH_pid'] . '"' .
                                        ' AND t3ver_wsid=' . $row['t3ver_wsid'] . BackendUtility::deleteClause($table);
                                        $tmpRow = BackendUtility::getRecordRaw($table, $where, $selFieldList);
                                        $lRow = is_array($tmpRow) ? $tmpRow : $lRow;
                                    }
                                    // In offline workspace, look for alternative record:
                                    BackendUtility::workspaceOL($table, $lRow, $backendUser->workspace, true);
                                    if (is_array($lRow) &&
                                        $backendUser->checkLanguageAccess($lRow[$GLOBALS['TCA'][$table]['ctrl']['languageField']])
                                    ) {
                                        $currentIdList[] = $lRow['uid'];
                                        $rowOutput .= $this->renderListRow($table, $lRow, $cc, $titleCol, $thumbsCol, 18);
                                    }
                                }
                            }
                        }
                    }
                    // Counter of total rows incremented:
                    $this->eCounter++;
                }
                // Record navigation is added to the beginning and end of the table if in single
                // table mode
                if ($this->table) {
                    $rowOutput = $this->renderListNavigation('top') .
                        $rowOutput .
                        $this->renderListNavigation('bottom');
                } else {
                    // Show that there are more records than shown
                    if ($this->totalItems > $this->itemsLimitPerTable) {
                        $countOnFirstPage = $this->totalItems > $this->itemsLimitSingleTable ? $this->itemsLimitSingleTable : $this->totalItems;
                        $hasMore = $this->totalItems > $this->itemsLimitSingleTable;
                        $colspan = $this->showIcon ? count($this->fieldArray) + 1 : count($this->fieldArray);
                        $rowOutput .= '<tr><td colspan="' . $colspan . '">
                            <a href="' . htmlspecialchars(($this->listURL() . '&table=' . rawurlencode($table))) . '" class="btn btn-default">' .
                            '<span class="t3-icon fa fa-chevron-down"></span> <i>[1 - ' . $countOnFirstPage . ($hasMore ? '+' : '') . ']</i></a>
                            </td></tr>';
                    }
                }
                // The header row for the table is now created:
                $out .= $this->renderListHeader($table, $currentIdList);
            }

            $collapseClass = $tableCollapsed && !$this->table ? 'collapse' : 'collapse in';
            $dataState = $tableCollapsed && !$this->table ? 'collapsed' : 'expanded';

            // The list of records is added after the header:
            $out .= $rowOutput;
            // ... and it is all wrapped in a table:
            $out = '



			<!--
				DB listing of elements:	"' . htmlspecialchars($table) . '"
			-->
				<div class="panel panel-space panel-default recordlist">
					<div class="panel-heading">
					' . $tableHeader . '
					</div>
					<div class="' . $collapseClass . '" data-state="' . $dataState . '" id="recordlist-' . htmlspecialchars($table) . '">
						<div class="table-fit">
							<table data-table="' . htmlspecialchars($table) . '" class="table table-striped table-hover' . ($listOnlyInSingleTableMode ? ' typo3-dblist-overview' : '') . '">
								' . $out . '
							</table>
						</div>
					</div>
				</div>
			';
            // Output csv if...
            // This ends the page with exit.
            if ($this->csvOutput) {
                $this->outputCSV($table);
            }
        }
        // Return content:
        return $out;
    }

    /**
     * Rendering the header row for a table
     *
     * @param string $table Table name
     * @param array $currentIdList Array of the currectly displayed uids of the table
     * @return string Header table row
     * @access private
     * @see getTable()
     */
    public function renderListHeader($table, $currentIdList)
    {
        $lang = $this->getLanguageService();
        // Init:
        $theData = array();
        $icon = '';
        // Traverse the fields:
        foreach ($this->fieldArray as $fCol) {
            // Calculate users permissions to edit records in the table:
            $permsEdit = $this->calcPerms & ($table == 'pages' ? 2 : 16) && $this->overlayEditLockPermissions($table);
            switch ((string)$fCol) {
                case '_PATH_':
                    // Path
                    $theData[$fCol] = '<i>[' . $lang->sL('LLL:EXT:lang/locallang_core.xlf:labels._PATH_', true) . ']</i>';
                    break;
                case '_REF_':
                    // References
                    //$theData[$fCol] = '<i>[' . $lang->sL('LLL:EXT:lang/locallang_mod_file_list.xlf:c__REF_', true) . ']</i>';
                    break;
                case '_LOCALIZATION_':
                    // Path
                    $theData[$fCol] = '<i>[' . $lang->sL('LLL:EXT:lang/locallang_core.xlf:labels._LOCALIZATION_', true) . ']</i>';
                    break;
                case '_LOCALIZATION_b':
                    // Path
                    $theData[$fCol] = $lang->getLL('Localize', true);
                    break;
                case '_CLIPBOARD_':
                    if (!$this->getModule()->MOD_SETTINGS['clipBoard']) {
                        break;
                    }
                    // Clipboard:
                    $cells = array();
                    // If there are elements on the clipboard for this table,
                    // and the parent page is not locked by editlock
                    // then display the "paste into" icon:
                    $elFromTable = $this->clipObj->elFromTable($table);
                    if (!empty($elFromTable) && $this->overlayEditLockPermissions($table)) {
                        $href = htmlspecialchars($this->clipObj->pasteUrl($table, $this->id));
                        $confirmMessage = $this->clipObj->confirmMsgText('pages', $this->pageRow, 'into', $elFromTable);
                        $cells['pasteAfter'] = '<a class="btn btn-default t3js-modal-trigger"'
                            . ' href="' . $href . '"'
                            . ' title="' . $lang->getLL('clip_paste', true) . '"'
                            . ' data-title="' . $lang->getLL('clip_paste', true) . '"'
                            . ' data-content="' . htmlspecialchars($confirmMessage) . '"'
                            . ' data-severity="warning">'
                            . $this->iconFactory->getIcon('actions-document-paste-after', Icon::SIZE_SMALL)->render()
                            . '</a>';
                    }
                    // If the numeric clipboard pads are enabled, display the control icons for that:
                    if ($this->clipObj->current != 'normal') {
                        // The "select" link:
                        $spriteIcon = $this->iconFactory->getIcon('actions-edit-copy', Icon::SIZE_SMALL)->render();
                        $cells['copyMarked'] = $this->linkClipboardHeaderIcon($spriteIcon, $table, 'setCB', '', $lang->getLL('clip_selectMarked'));

                        // The "edit marked" link:
                        $editIdList = implode(',', $currentIdList);
                        $editIdList = '\'+editList(' . GeneralUtility::quoteJSvalue($table) . ',' . GeneralUtility::quoteJSvalue($editIdList) . ')+\'';
                        $params = 'edit[' . $table . '][' . $editIdList . ']=edit';
                        $onClick = BackendUtility::editOnClick('', '', -1);
                        $onClickArray = explode('?', $onClick, 2);
                        $lastElement = array_pop($onClickArray);
                        array_push($onClickArray, $params . '&' . $lastElement);
                        $onClick = implode('?', $onClickArray);
                        $cells['edit'] = '<a class="btn btn-default" href="#" onclick="' . htmlspecialchars($onClick) . '" title="'
                            . $lang->getLL('clip_editMarked', true) . '">'
                            . $this->iconFactory->getIcon('actions-document-open', Icon::SIZE_SMALL)->render() . '</a>';
                        // The "Delete marked" link:
                        $cells['delete'] = $this->linkClipboardHeaderIcon(
                            $this->iconFactory->getIcon('actions-edit-delete', Icon::SIZE_SMALL)->render(),
                            $table,
                            'delete',
                            sprintf($lang->getLL('clip_deleteMarkedWarning'), $lang->sL($GLOBALS['TCA'][$table]['ctrl']['title'])),
                            $lang->getLL('clip_deleteMarked')
                        );
                        // The "Select all" link:
                        $onClick = htmlspecialchars(('checkOffCB(' . GeneralUtility::quoteJSvalue(implode(',', $this->CBnames)) . ', this); return false;'));
                        $cells['markAll'] = '<a class="btn btn-default" rel="" href="#" onclick="' . $onClick . '" title="'
                            . $lang->getLL('clip_markRecords', true) . '">'
                            . $this->iconFactory->getIcon('actions-document-select', Icon::SIZE_SMALL)->render() . '</a>';
                    } else {
                        $cells['empty'] = '';
                    }
                    /**
                     * @hook renderListHeaderActions: Allows to change the clipboard icons of the Web>List table headers
                     * @usage Above each listed table in Web>List a header row is shown.
                     *        This hook allows to modify the icons responsible for the clipboard functions
                     *        (shown above the clipboard checkboxes when a clipboard other than "Normal" is selected),
                     *        or other "Action" functions which perform operations on the listed records.
                     */
                    if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['typo3/class.db_list_extra.inc']['actions'])) {
                        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['typo3/class.db_list_extra.inc']['actions'] as $classData) {
                            $hookObject = GeneralUtility::getUserObj($classData);
                            if (!$hookObject instanceof RecordListHookInterface) {
                                throw new \UnexpectedValueException('$hookObject must implement interface ' . RecordListHookInterface::class, 1195567850);
                            }
                            $cells = $hookObject->renderListHeaderActions($table, $currentIdList, $cells, $this);
                        }
                    }
                    $theData[$fCol] = '<div class="btn-group" role="group">' . implode('', $cells) . '</div>';
                    break;
                case '_CONTROL_':
                    // dkd-kartolo: dummy header
                    $theData[$fCol] = '&nbsp;';
                    break;
                default:
                    // Regular fields header:
                    $theData[$fCol] = '';

                    // Check if $fCol is really a field and get the label and remove the colons
                    // at the end
                    $sortLabel = BackendUtility::getItemLabel($table, $fCol);
                    if ($sortLabel !== null) {
                        $sortLabel = $lang->sL($sortLabel, true);
                        $sortLabel = rtrim(trim($sortLabel), ':');
                    } else {
                        // No TCA field, only output the $fCol variable with square brackets []
                        $sortLabel = htmlspecialchars($fCol);
                        $sortLabel = '<i>[' . rtrim(trim($sortLabel), ':') . ']</i>';
                    }

                    if ($this->table && is_array($currentIdList)) {
                        // If the numeric clipboard pads are selected, show duplicate sorting link:
                        if ($this->clipNumPane()) {
                            $theData[$fCol] .= '<a class="btn btn-default" href="' . htmlspecialchars($this->listURL('', '-1') . '&duplicateField=' . $fCol)
                                . '" title="' . $lang->getLL('clip_duplicates', true) . '">'
                                . $this->iconFactory->getIcon('actions-document-duplicates-select', Icon::SIZE_SMALL)->render() . '</a>';
                        }
                        if (strlen($theData[$fCol]) > 0) {
                            $theData[$fCol] = '<div class="btn-group" role="group">' . $theData[$fCol] . '</div> ';
                        }
                    }
                    $theData[$fCol] .= $this->addSortLink($sortLabel, $fCol, $table);
            }
        }

        /**
         * @hook renderListHeader: Allows to change the contents of columns/cells of the Web>List table headers
         * @usage Above each listed table in Web>List a header row is shown.
         *        Containing the labels of all shown fields and additional icons to create new records for this
         *        table or perform special clipboard tasks like mark and copy all listed records to clipboard, etc.
         */
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['typo3/class.db_list_extra.inc']['actions'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['typo3/class.db_list_extra.inc']['actions'] as $classData) {
                $hookObject = GeneralUtility::getUserObj($classData);
                if (!$hookObject instanceof RecordListHookInterface) {
                    throw new \UnexpectedValueException('$hookObject must implement interface ' . RecordListHookInterface::class, 1195567855);
                }
                $theData = $hookObject->renderListHeader($table, $currentIdList, $theData, $this);
            }
        }

        // Create and return header table row:
        return '<thead>' . $this->addElement(1, $icon, $theData, '', '', '', 'th') . '</thead>';
    }

    /**
     * Rendering a single row for the list
     *
     * @param    string $table : Table name
     * @param    array $row : Current record
     * @param    integer $cc : Counter, counting for each time an element is rendered (used for alternating colors)
     * @param    string $titleCol : Table field (column) where header value is found
     * @param    string $thumbsCol : Table field (column) where (possible) thumbnails can be found
     * @param    int $indent : indent from left.
     * @return    string        Table row for the element
     * @access private
     * @see getTable()
     */
    public function renderListRow($table, $row, $cc, $titleCol, $thumbsCol, $indent = 0)
    {
        if (!is_array($row)) {
            return '';
        }
        $rowOutput = '';
        $id_orig = null;
        // If in search mode, make sure the preview will show the correct page
        if ((string)$this->searchString !== '') {
            $id_orig = $this->id;
            $this->id = $row['pid'];
        }
        // Add special classes for first and last row
        $rowSpecial = '';
        if ($cc == 1 && $indent == 0) {
            $rowSpecial .= ' firstcol';
        }
        if ($cc == $this->totalRowCount || $cc == $this->iLimit) {
            $rowSpecial .= ' lastcol';
        }

        $row_bgColor = ' class="' . $rowSpecial . '"';

        // Overriding with versions background color if any:
        $row_bgColor = $row['_CSSCLASS'] ? ' class="' . $row['_CSSCLASS'] . '"' : $row_bgColor;
        // Incr. counter.
        $this->counter++;
        // The icon with link
        $toolTip = BackendUtility::getRecordToolTip($row, $table);
        $additionalStyle = $indent ? ' style="margin-left: ' . $indent . 'px;"' : '';
        $iconImg = '<span ' . $toolTip . ' ' . $additionalStyle . '>'
            . $this->iconFactory->getIconForRecord($table, $row, Icon::SIZE_SMALL)->render()
            . '</span>';
        $theIcon = $this->clickMenuEnabled ? BackendUtility::wrapClickMenuOnIcon($iconImg, $table, $row['uid']) : $iconImg;
        // Preparing and getting the data-array
        $theData = array();
        $localizationMarkerClass = '';

        foreach ($this->fieldArray as $fCol) {
            if ($fCol == $titleCol) {
                $recTitle = BackendUtility::getRecordTitle($table, $row, false, true);
                $warning = '';
                // If the record is edit-locked	by another user, we will show a little warning sign:
                $lockInfo = BackendUtility::isRecordLocked($table, $row['uid']);
                if ($lockInfo) {
                    $warning = '<a href="#" onclick="alert('
                        . GeneralUtility::quoteJSvalue($lockInfo['msg']) . '); return false;" title="'
                        . htmlspecialchars($lockInfo['msg']) . '">'
                        . $this->iconFactory->getIcon('status-warning-in-use', Icon::SIZE_SMALL)->render() . '</a>';
                }
                $theData[$fCol] = $theData['__label'] = $warning . $this->linkWrapItems($table, $row['uid'], $recTitle, $row);
                // Render thumbnails, if:
                // - a thumbnail column exists
                // - there is content in it
                // - the thumbnail column is visible for the current type
                $type = 0;
                if (isset($GLOBALS['TCA'][$table]['ctrl']['type'])) {
                    $typeColumn = $GLOBALS['TCA'][$table]['ctrl']['type'];
                    $type = $row[$typeColumn];
                }
                // If current type doesn't exist, set it to 0 (or to 1 for historical reasons,
                // if 0 doesn't exist)
                if (!isset($GLOBALS['TCA'][$table]['types'][$type])) {
                    $type = isset($GLOBALS['TCA'][$table]['types'][0]) ? 0 : 1;
                }
                $visibleColumns = $GLOBALS['TCA'][$table]['types'][$type]['showitem'];

                if ($this->thumbs &&
                    trim($row[$thumbsCol]) &&
                    preg_match('/(^|(.*(;|,)?))' . $thumbsCol . '(((;|,).*)|$)/', $visibleColumns) === 1
                ) {
                    $theData[$fCol] .= '<br />' . $this->thumbCode($row, $table, $thumbsCol);
                }
                if (isset($GLOBALS['TCA'][$table]['ctrl']['languageField'])
                    && $row[$GLOBALS['TCA'][$table]['ctrl']['languageField']] != 0
                    && $row[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']] != 0
                ) {
                    // It's a translated record with a language parent
                    $localizationMarkerClass = ' localization';
                }
            } elseif ($fCol == 'pid') {
                $theData[$fCol] = $row[$fCol];
            } elseif ($fCol == '_PATH_') {
                $theData[$fCol] = $this->recPath($row['pid']);
            } elseif ($fCol == '_REF_') {
                $theData[$fCol] = $this->createReferenceHtml($table, $row['uid']);
            } elseif ($fCol == '_CONTROL_') {
                $theData[$fCol] = $this->makeControl($table, $row);
            } elseif ($fCol == '_CLIPBOARD_') {
                $theData[$fCol] = $this->makeClip($table, $row);
            } elseif ($fCol == '_LOCALIZATION_') {
                list($lC1, $lC2) = $this->makeLocalizationPanel($table, $row);
                $theData[$fCol] = $lC1;
                $theData[$fCol . 'b'] = '<div class="btn-group">' . $lC2 . '</div>';
            } elseif ($fCol == '_LOCALIZATION_b') {
                // deliberately empty
            } elseif ($this->userMainGroupOnly && $table == 'be_users' && $fCol == 'usergroup') {
                // ingo.renner@dkd.de
                $theData[$fCol] = htmlspecialchars($this->getUserMainGroup($row[$fCol]));
            } else {
                $pageId = $table === 'pages' ? $row['uid'] : $row['pid'];
                $tmpProc = BackendUtility::getProcessedValueExtra($table, $fCol, $row[$fCol], 100, $row['uid'], true, $pageId);
                $theData[$fCol] = $this->linkUrlMail(htmlspecialchars($tmpProc), $row[$fCol]);
                if ($this->csvOutput) {
                    $row[$fCol] = BackendUtility::getProcessedValueExtra($table, $fCol, $row[$fCol], 0, $row['uid']);
                }
            }
        }
        // Reset the ID if it was overwritten
        if ((string)$this->searchString !== '') {
            $this->id = $id_orig;
        }
        // Add row to CSV list:
        if ($this->csvOutput) {
            $this->addToCSV($row);
        }
        // Add classes to table cells
//        $this->addElement_tdCssClass[$titleCol] = 'col-title' . $localizationMarkerClass;
        $this->addElement_tdCssClass['_CONTROL_'] = 'col-control';
        if ($this->getModule()->MOD_SETTINGS['clipBoard']) {
            $this->addElement_tdCssClass['_CLIPBOARD_'] = 'col-clipboard';
        }
        $this->addElement_tdCssClass['_PATH_'] = 'col-path';
        $this->addElement_tdCssClass['_LOCALIZATION_'] = 'col-localizationa';
        $this->addElement_tdCssClass['_LOCALIZATION_b'] = 'col-localizationb';
        // Create element in table cells:
        $theData['uid'] = $row['uid'];
        if (isset($GLOBALS['TCA'][$table]['ctrl']['languageField'])
            && isset($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])
            && !isset($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerTable'])
        ) {
            $theData['parent'] = $row[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']];
        }
        $rowOutput .= $this->addElement(1, $theIcon, $theData, $row_bgColor);
        // Finally, return table row element:
        return $rowOutput;
    }

    public function getUserMainGroup($allGroups)
    {
        $allGroups = explode(',', $allGroups);
        if (!empty($allGroups[0])) {
            $mainGroup = $this->getDatabaseConnection()->exec_SELECTgetRows(
                'title',
                'be_groups',
                'uid = '.$allGroups[0]
            );
        }

        return $mainGroup[0]['title'];
    }

    /**
     * modified to point to own module
     * dkd-kartolo
     *
     * Returns a JavaScript string (for an onClick handler) which will load the EditDocumentController script that shows the form for editing of the record(s) you have send as params.
     * REMEMBER to always htmlspecialchar() content in href-properties to ampersands get converted to entities (XHTML requirement and XSS precaution)
     *
     * @param string $params Parameters sent along to EditDocumentController. This requires a much more details description which you must seek in Inside TYPO3s documentation of the FormEngine API. And example could be '&edit[pages][123] = edit' which will show edit form for page record 123.
     * @param string $_ (unused)
     * @param string $requestUri An optional returnUrl you can set - automatically set to REQUEST_URI.
     *
     * @return string
     */
    public static function editOnClick($params, $_ = '', $requestUri = '')
    {
        if ($requestUri == -1) {
            $returnUrl = 'T3_THIS_LOCATION';
        } else {
            $returnUrl = GeneralUtility::quoteJSvalue(rawurlencode($requestUri ?: GeneralUtility::getIndpEnv('REQUEST_URI')));
        }
        return 'window.location.href=' . GeneralUtility::quoteJSvalue(BackendUtility::getModuleUrl($GLOBALS['MCONF']['name']) . $params . '&returnUrl=') . '+' . $returnUrl . '; return false;';
    }

    /**
     * Creates the control panel for a single record in the listing.
     *
     * @param string $table The table
     * @param array $row The record for which to make the control panel.
     * @return string HTML table with the control panel (unless disabled)
     */
    public function makeControl($table, $row)
    {
        if ($this->dontShowClipControlPanels) {
            return '';
        }

        $rowUid = $row['uid'];

        $cells = array(
            'primary' => array(),
            'secondary' => array()
        );

        // If the listed table is 'pages' we have to request the permission settings for each page:
        if ($table == 'pages') {
            $localCalcPerms = $this->getBackendUserAuthentication()->calcPerms(BackendUtility::getRecord('pages', $row['uid']));
        }

        // This expresses the edit permissions for this particular element:
        $permsEdit = ($table == 'pages' && ($localCalcPerms & 2)) || ($table != 'pages' && ($this->calcPerms & 16));

        # EDIT button
        if (!$this->disableControls['edit']) {
            // "Edit" link: ( Only if permissions to edit the page-record of the content of the parent page ($this->id)
            if ($permsEdit) {
                $params = '&edit[' . $table . '][' . $row['uid'] . ']=edit';
                $iconIdentifier = 'actions-open';
                $overlayIdentifier = !$this->isEditable($table) ? 'overlay-readonly' : null;
                $editAction = '<a class="btn btn-default" href="#" onclick="' . htmlspecialchars(self::editOnClick($params, '', -1))
                    . '" title="' . $this->getLanguageService()->getLL('edit', true) . '">' .
                    $this->iconFactory->getIcon($iconIdentifier, Icon::SIZE_SMALL, $overlayIdentifier)->render() .
                    '</a>';
            } else {
                $editAction = $this->spaceIcon;
            }
            $this->addActionToCellGroup($cells, $editAction, 'edit');
        }

            //dkd-kartolo
            //show magnifier (mod4)
        if (!$this->disableControls['detail']) {
            $infoAction = '<a href="#" class="btn btn-default" onclick="javascript:top.goToModule(\'tcTools_Overview\', 1, \'&' .
                $this->analyzeParam . '=' . $row['uid'] . '\')"' .
                ' title="' . $this->analyzeLabel . '">' .
                $this->iconFactory->getIcon('apps-toolbar-menu-search', Icon::SIZE_SMALL)->render() .
                '</a>';
            $this->addActionToCellGroup($cells, $infoAction, 'info');
        }

            //dkd-kartolo
            //show import fe user icon
        if (!$this->disableControls['import']) {
            $scriptname = GeneralUtility::getIndpEnv('SCRIPT_NAME');
            $params = '&SET[function]=import&feID=' . $row['uid'];
            $importAction = '<a href="#" class="btn btn-default" onclick="' .
                htmlspecialchars(self::editOnClick($params)) .
                '" title="' . $this->getLanguageService()->getLL('import', 1) .'">' .
                $this->iconFactory->getIcon('actions-document-import-t3d', Icon::SIZE_SMALL)->render() .
                '</a>';
            $this->addActionToCellGroup($cells, $importAction, 'import');
        }

        // "Info": (All records)
        $onClick = 'top.launchView(' . GeneralUtility::quoteJSvalue($table) . ', ' . (int)$row['uid'] . '); return false;';
        $viewBigAction = '<a class="btn btn-default" href="#" onclick="' . htmlspecialchars($onClick) . '" title="' . $this->getLanguageService()->getLL('showInfo', true) . '">'
            . $this->iconFactory->getIcon('actions-document-info', Icon::SIZE_SMALL)->render() . '</a>';
        $this->addActionToCellGroup($cells, $viewBigAction, 'viewBig');

        // "Hide/Unhide" links:
        if (!$this->disableControls['hide']) {
            $hiddenField = $GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'];
            if ($permsEdit && $hiddenField && $GLOBALS['TCA'][$table]['columns'][$hiddenField]
                && (!$GLOBALS['TCA'][$table]['columns'][$hiddenField]['exclude']
                    || $this->getBackendUserAuthentication()->check('non_exclude_fields', $table . ':' . $hiddenField))
            ) {
                if ($this->isRecordCurrentBackendUser($table, $row)) {
                    $hideAction = $this->spaceIcon;
                } else {
                    $hideTitle = $this->getLanguageService()->getLL('hide' . ($table == 'pages' ? 'Page' : ''), true);
                    $unhideTitle = $this->getLanguageService()->getLL('unHide' . ($table == 'pages' ? 'Page' : ''), true);

                    if ($row[$hiddenField]) {
                        $params = '&data[' . $table . '][' . $row['uid'] . '][' . $hiddenField . ']=0&SET[function]=action';
                        $hideAction = '<a href="#" class="btn btn-default" ' .
                            ' title="' . $unhideTitle . '" ' .
                            'onclick="' . htmlspecialchars('return jumpToUrl(\'' . self::actionOnClick($params) . '\');') . '">' .
                            $this->iconFactory->getIcon('actions-edit-unhide', Icon::SIZE_SMALL)->render() .
                            '</a>';
                    } else {
                        $params = '&data[' . $table . '][' . $row['uid'] . '][' . $hiddenField . ']=1&SET[function]=action';
                        $hideAction = '<a href="#" class="btn btn-default" ' .
                            'title="' . $hideTitle . '" ' .
                            'onclick=" ' . htmlspecialchars('return jumpToUrl(\'' . self::actionOnClick($params) . '\');') . '">' .
                            $this->iconFactory->getIcon('actions-edit-hide', Icon::SIZE_SMALL)->render() .
                            '</a>';
                    }
                }
                $this->addActionToCellGroup($cells, $hideAction, 'hide');
            }
        }

        // "Delete" link:
        if (!$this->disableControls['delete']) {
            if ($permsEdit && ($table === 'pages' && $localCalcPerms & Permission::PAGE_DELETE || $table !== 'pages' && $this->calcPerms & Permission::CONTENT_EDIT)) {
                // Check if the record version is in "deleted" state, because that will switch the action to "restore"
                if ($this->getBackendUserAuthentication()->workspace > 0 && isset($row['t3ver_state']) && (int)$row['t3ver_state'] === 2) {
                    $actionName = 'restore';
                    $refCountMsg = '';
                } else {
                    $actionName = 'delete';
                    $refCountMsg = BackendUtility::referenceCount(
                        $table,
                        $row['uid'],
                        ' ' .
                        $this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:labels.referencesToRecord'),
                        $this->getReferenceCount(
                            $table,
                            $row['uid']
                        )
                    ) .
                    BackendUtility::translationCount(
                        $table,
                        $row['uid'],
                        ' ' .
                        $this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:labels.translationsOfRecord')
                    );
                }

                if ($this->isRecordCurrentBackendUser($table, $row)) {
                    $deleteAction = $this->spaceIcon;
                } else {
                    $titleOrig = BackendUtility::getRecordTitle($table, $row, false, true);
                    $title = GeneralUtility::slashJS(GeneralUtility::fixed_lgd_cs($titleOrig, $this->fixedL), true);
                    $warningText = $this->getLanguageService()->getLL($actionName . 'Warning') . ' "' . $title . '" ' . '[' . $table . ':' . $row['uid'] . ']' . $refCountMsg;
                    $linkTitle = $this->getLanguageService()->getLL($actionName, true);

                    $params = '&cmd[' . $table . '][' . $row['uid'] . '][delete]=1&SET[function]=action';
                    $deleteAction = '<a href="#" class="btn btn-default"' .
                        ' title="' . $linkTitle . '"' .
                        ' onclick="' . htmlspecialchars('if (confirm(' . GeneralUtility::quoteJSvalue($warningText) . ')) {jumpToUrl(\'' . $this->actionOnClick($params) . '\');}') . '">' .
                        $this->iconFactory->getIcon('actions-edit-' . $actionName, Icon::SIZE_SMALL)->render() .
                        '</a>';
                }
            } else {
                $deleteAction = $this->spaceIcon;
            }
            $this->addActionToCellGroup($cells, $deleteAction, 'delete');
        }

        //TODO: only for admins or authorized user
        // swith user / switch user back
        if ($table == 'be_users') {
            if (!$row[$GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled']] && ($this->getBackendUserAuthentication()->user['tc_beuser_switch_to'] || $this->getBackendUserAuthentication()->isAdmin())) {
                if ($this->isRecordCurrentBackendUser($table, $row)) {
                    $switchAction = $this->spaceIcon;
                } else {
                    $switchAction = '<a class="btn btn-default" href="' . GeneralUtility::linkThisScript(array('SwitchUser' => $row['uid'])) . '" target="_top" title="' . htmlspecialchars('Switch user to: ' . $row['username']) . '" >' .
                        $this->iconFactory->getIcon('actions-system-backend-user-switch', Icon::SIZE_SMALL)->render() .
                        '</a>' .
                        chr(10) . chr(10);
                }
            } else {
                $switchAction = $this->spaceIcon;
            }
            $this->addActionToCellGroup($cells, $switchAction, 'switch');
        }

            // If the record is edit-locked	by another user, we will show a little warning sign:
        if ($lockInfo = BackendUtility::isRecordLocked($table, $row['uid'])) {
            $lockAction ='<a href="#" class="btn btn-default" onclick="'.htmlspecialchars('alert('.GeneralUtility::quoteJSvalue($lockInfo['msg']).');return false;').'" ' .
                ' title="'.htmlspecialchars($lockInfo['msg']).'" >'.
                $this->iconFactory->getIcon('status-warning-lock', Icon::SIZE_SMALL)->render() .
                '</a>';
        } else {
            $lockAction = $this->spaceIcon;
        }
        $this->addActionToCellGroup($cells, $lockAction, 'lock');


            // Compile items into a DIV-element:
        $output = '<!-- CONTROL PANEL: ' . $table . ':' . $row['uid'] . ' -->';
        foreach ($cells as $classification => $actions) {
            $output .= ' <div class="btn-group" role="group">' . implode('', $actions) . '</div>';
        }
        return $output;
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
            '&vC=' . rawurlencode($this->getBackendUserAuthentication()->veriCode()) . '&prErr=1&uPT=1';
        return BackendUtility::getModuleUrl($GLOBALS['MCONF']['name']) . $params . $redirect;
    }

    /**
     * Get database instance
     *
    * @return \TYPO3\CMS\Core\Database\DatabaseConnection
    */
    protected function getDatabaseConnection() {
        return $GLOBALS['TYPO3_DB'];
    }
}
