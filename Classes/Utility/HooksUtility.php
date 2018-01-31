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
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * methods for some hooks
 * $Id$
 *
 * @author Ingo Renner <ingo.renner@dkd.de>
 */
class HooksUtility
{

    public $columns;

    public function befuncPostProcessValue($params, $ref)
    {
    }

    public function fakeAdmin($params, &$pObj)
    {
        $access = $params['outputPermissions'];

        if (is_array($GLOBALS['MCONF']) &&
            GeneralUtility::isFirstPartOfStr($GLOBALS['MCONF']['name'], 'tcTools') &&
            $GLOBALS['BE_USER']->modAccess($GLOBALS['MCONF'], true)
        ) {
            $access = 31;
        }

        return $access;
    }

    /**
     * updating be_users
     */
    public function processDatamap_preProcessFieldArray($incomingFieldArray, $table, $id, $tcemain)
    {
        if ($table == 'be_groups') {
            //unset 'members' from TCA
            $this->columns['members'] = $GLOBALS['TCA'][$table]['columns']['members'];
            unset($GLOBALS['TCA'][$table]['columns']['members']);
        }
    }

    /**
     * put back 'members' field in be_groups TCA
     */
    public function processDatamap_afterDatabaseOperations($status, $table, $id, $fieldArray, $tce)
    {
        if ($table == 'be_groups') {
            if (!empty($tce->datamap[$table][$id]['members'])) {
                //get uid and title of group
                if (strstr($id, 'NEW')) {
                    //if it's a new record
                    $uid = $tce->substNEWwithIDs[$id];
                } else {
                    $uid = $id;
                }

                $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
                    'uid, title',
                    $table,
                    'uid ='.$uid
                );

                while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
                    $usergroup[$row['uid']] = $row['title'];
                }

                $userList = explode(',', $tce->datamap[$table][$id]['members']);
                if (substr($tce->datamap[$table][$id]['members'], -1, 1) == ',') {
                    unset($userList[count($userList)-1]);
                }

                if (!empty($userList)) {
                    foreach ($userList as $userUid) {
                        //get list of groups from user
                        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
                            '*',
                            'be_users',
                            'uid = '.$userUid
                        );
                        $userData = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);

                        //only new users
                        if (!GeneralUtility::inList($userData['usergroup'], $uid)) {
                            //update be_users with the new groups
                            $newGroup = $userData['usergroup']? $userData['usergroup'].','.$uid : $uid;
                            $updateArray = array(
                                'usergroup' => $newGroup
                            );
                            $res = $GLOBALS['TYPO3_DB']->exec_UPDATEquery(
                                'be_users',
                                'uid='.$userUid,
                                $updateArray
                            );
                        }
                    }
                }
            }
            //remove user
            //get all user, which in the group but not in incomingFieldArray['members']
            if (isset($tce->datamap[$table][$id]['members'])) {
                $subWhere = '';
                if (!empty($userList)) {
                    $subWhere = 'uid not in ('.implode(',', $userList).') AND ';
                }
                $where = $subWhere.'usergroup like '.$GLOBALS['TYPO3_DB']->fullQuoteStr('%'.$uid.'%', $table).
                    BackendUtility::BEenableFields('be_users').
                    BackendUtility::deleteClause('be_users');
                $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
                    '*',
                    'be_users',
                    $where
                );

                while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
                    //remove groups id
                    $usergroup = explode(',', $row['usergroup']);
                    for ($i=0; $i<=(count($usergroup)-1); $i++) {
                        if ($usergroup[$i] == $id) {
                            unset($usergroup[$i]);
                        }
                    }
                    $updateArray = array(
                        'usergroup' => implode(',', $usergroup)
                    );

                    //put it back
                    $res1 = $GLOBALS['TYPO3_DB']->exec_UPDATEquery(
                        'be_users',
                        'uid='.$row['uid'],
                        $updateArray
                    );
                }
            }


            //put back 'members' to TCA
            $tempCol = $this->columns;
            ExtensionManagementUtility::addTCAcolumns("be_groups", $tempCol);
        }
    }
}
