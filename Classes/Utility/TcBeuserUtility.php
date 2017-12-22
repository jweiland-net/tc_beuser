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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;

/**
 * clas for module configuration handling
 *
 * $Id$
 *
 * @author Ingo Renner <ingo.renner@dkd.de>
 */
class TcBeuserUtility
{

    public $config;

    public static function fakeAdmin()
    {
        self::getBackendUser()->user['admin'] = 1;
    }

    public static function removeFakeAdmin()
    {
        self::getBackendUser()->user['admin'] = 0;
    }

    public static function getSubgroup($id)
    {
        $res = self::getDatabaseConnection()->exec_SELECTquery(
            'uid,title,subgroup',
            'be_groups',
            'deleted = 0 AND uid ='.$id
        );
        $row = self::getDatabaseConnection()->sql_fetch_assoc($res);
        $uid = '';
        if ($row['subgroup']) {
            $subGroup = GeneralUtility::intExplode(',', $row['subgroup']);
            foreach ($subGroup as $subGroupUID) {
                $uid .= $subGroupUID.','.self::getSubgroup($subGroupUID).',';
            }
            return $uid;
        } else {
            return $row['uid'];
        }
    }

    public static function allowWhereMember($tsConfig)
    {
        $userGroup = explode(',', self::getBackendUser()->user['usergroup']);

        $allowWhereMember = array();
        foreach ($userGroup as $uid) {
            $groupID = $uid.','.self::getSubgroup($uid);
            if (strstr($groupID, ',')) {
                $groupIDarray = explode(',', $groupID);
                $allowWhereMember = array_merge($allowWhereMember, array_unique($groupIDarray));
            } else {
                $allowWhereMember[] = $groupID;
            }
        }
        $allowWhereMember = ArrayUtility::removeArrayEntryByValue($allowWhereMember, '');

        return $allowWhereMember;
    }

    public static function allowCreated($TSconfig, $where)
    {
        $res = self::getDatabaseConnection()->exec_SELECTquery(
            'uid',
            'be_groups',
            $where.' AND cruser_id = '.self::getBackendUser()->user['uid']
        );
        if (self::getDatabaseConnection()->sql_num_rows($res) > 0) {
            while ($row = self::getDatabaseConnection()->sql_fetch_assoc($res)) {
                $allowCreated[] = $row['uid'];
            }
        } else {
            $allowCreated = array();
        }

        return $allowCreated;
    }

    public static function allow($tsConfig, $where)
    {
        if (isset($tsConfig['allow']) && !empty($tsConfig['allow'])) {
            if ($tsConfig['allow'] == 'all') {
                $addWhere = empty($showGroupID) ? '' : ' AND uid not in ('.implode(',', $showGroupID).')';
                $res = self::getDatabaseConnection()->exec_SELECTquery(
                    'uid',
                    'be_groups',
                    $where.$addWhere
                );
                if (self::getDatabaseConnection()->sql_num_rows($res)>0) {
                    while ($row = self::getDatabaseConnection()->sql_fetch_assoc($res)) {
                        $allowID[] = $row['uid'];
                    }
                }
            } elseif (strstr($tsConfig['allow'], ',')) {
                $allowID = explode(',', $tsConfig['allow']);
            } else {
                $allowID = array(trim($tsConfig['allow']));
            }
        } else {
            $allowID = array();
        }
        return $allowID;
    }

    public static function denyID($tsConfig, $where)
    {
        if (isset($tsConfig['deny']) && !empty($tsConfig['deny'])) {
            if (strstr($tsConfig['deny'], ',')) {
                $denyID = explode(',', $tsConfig['deny']);
            } else {
                $denyID = array(trim($tsConfig['deny']));
            }
        } else {
            $denyID = array();
        }
        return $denyID;
    }

    public static function showPrefixID($tsConfig, $where, $mode)
    {
        $addWhere = "";

        if (isset($tsConfig[$mode]) && !empty($tsConfig[$mode])) {
            if (strstr($tsConfig[$mode], ',')) {
                $prefix = explode(',', $tsConfig[$mode]);
                foreach ($prefix as $pre) {
                    $whereTemp[] = 'title like '.self::getDatabaseConnection()->fullQuoteStr(trim($pre).'%', 'be_groups');
                }
                $addWhere .= ' AND ('.implode(' OR ', $whereTemp).')';
            } else {
                $addWhere .= ' AND '.'title like '.self::getDatabaseConnection()->fullQuoteStr($tsConfig[$mode].'%', 'be_groups');
            }

            $res = self::getDatabaseConnection()->exec_SELECTquery(
                'uid',
                'be_groups',
                $where.$addWhere
            );
            if (self::getDatabaseConnection()->sql_num_rows($res) > 0) {
                while ($row = self::getDatabaseConnection()->sql_fetch_assoc($res)) {
                    $showPrefixID[] = $row['uid'];
                }
            } else {
                $showPrefixID = array();
            }
        } else {
            $showPrefixID = array();
        }
        return $showPrefixID;
    }

    public static function showGroupID()
    {
        $tsConfig = self::getBackendUser()->userTS['tx_tcbeuser.'] ? self::getBackendUser()->userTS['tx_tcbeuser.'] : array();
            // default value
        $tsConfig['allowCreated'] = (strlen(trim($tsConfig['allowCreated'])) > 0)? $tsConfig['allowCreated'] : '1';
        $tsConfig['allowWhereMember'] = (strlen(trim($tsConfig['allowWhereMember'])) > 0)? $tsConfig['allowWhereMember'] : '1';

        $where = 'pid = 0'.BackendUtility::deleteClause('be_groups');

        if ($GLOBALS['TYPO3_CONF_VARS']['BE']['explicitADmode'] == 'explicitAllow') {
            $showGroupID = array();

                //put ID allowWhereMember
            if ($tsConfig['allowWhereMember'] == 1) {
                $allowWhereMember = self::allowWhereMember($tsConfig);
                $showGroupID = array_merge($showGroupID, $allowWhereMember);
            }

                //put ID allowCreated
            if ($tsConfig['allowCreated'] == 1) {
                $allowCreated = self::allowCreated($tsConfig, $where);
                $showGroupID = array_merge($showGroupID, $allowCreated);
            }

                //allow
            $allowID = self::allow($tsConfig, $where);
            $showGroupID = array_merge($showGroupID, $allowID);

                //put ID showPrefix
            $showPrefix = self::showPrefixID($tsConfig, $where, 'showPrefix');
            $showGroupID = array_merge($showGroupID, $showPrefix);
        } else {
            //explicitDeny
            $showGroupID = explode(',', self::getAllGroupsID());
            $denyGroupID = array();

                //put ID allowWhereMember
            if ($tsConfig['allowWhereMember'] == 0) {
                $allowWhereMember = self::allowWhereMember($tsConfig);
                $denyGroupID = array_merge($denyGroupID, $allowWhereMember);
            }

                //put ID allowCreated
            if ($tsConfig['allowCreated'] == 0) {
                $allowCreated = self::allowCreated($tsConfig, $where);
                $denyGroupID = array_merge($denyGroupID, $allowCreated);
            }

                //deny
            if ($tsConfig['deny'] == 'all') {
                $denyGroupID = array_merge($denyGroupID, explode(',', self::getAllGroupsID()));
            } else {
                $denyID = self::denyID($tsConfig, $where);
                $denyGroupID = array_merge($denyGroupID, $denyID);
            }

                //put ID dontShowPrefix
            $dontShowPrefix = self::showPrefixID($tsConfig, $where, 'dontShowPrefix');
            $denyGroupID = array_merge($denyGroupID, $dontShowPrefix);

                //remove $denyGroupID from $showGroupID
            $showGroupID = array_diff($showGroupID, $denyGroupID);
        }

        return $showGroupID;
    }

    /**
     * manipulate the list of usergroups based on TS Config
     */
    public static function getGroupsID(&$param, &$pObj)
    {
        if (self::getBackendUser()->user['admin'] == '0') {
            $where = 'pid = 0 '.BackendUtility::deleteClause('be_groups');
            $groupID = implode(',', self::showGroupID());
            if (!empty($groupID)) {
                $where .= ' AND uid in ('.$groupID.')';
            } else {
                $where .= ' AND uid not in ('.self::getAllGroupsID().')';
            }
        } else {
            $where = '1'.BackendUtility::deleteClause('be_groups');
        }

        $res = self::getDatabaseConnection()->exec_SELECTquery(
            '*',
            'be_groups',
            $where,
            '',
            'title ASC'
        );
        $param['items'] = array();

        while ($row=self::getDatabaseConnection()->sql_fetch_assoc($res)) {
            $param['items'][]= array($GLOBALS['LANG']->sL($row['title']),$row['uid'],'');
        }
        return $param;
    }

    /**
     * get all ID in a comma-list
     */
    public static function getAllGroupsID()
    {
        $res = self::getDatabaseConnection()->exec_SELECTquery(
            'uid',
            'be_groups',
            '1'.BackendUtility::deleteClause('be_groups')
        );
        $id = array();
        while ($row = self::getDatabaseConnection()->sql_fetch_assoc($res)) {
            $id[] = $row['uid'];
        }
        return implode(',', $id);
    }


    /**
     * Switches to a given user (SU-mode) and then redirects to the start page
     * of the backend to refresh the navigation etc.
     *
     * @param string $switchUser BE-user record that will be switched to
     * @return void
     */
    public static function switchUser($switchUser)
    {
        $targetUser = BackendUtility::getRecord('be_users', $switchUser);
        if (is_array($targetUser)) {
            $updateData['ses_userid'] = (int)$targetUser['uid'];
            $updateData['ses_backuserid'] = intval(self::getBackendUser()->user['uid']);

            // Set backend user listing module as starting module for switchback
            self::getBackendUser()->uc['startModuleOnFirstLogin'] = 'tctools_UserAdmin';
            self::getBackendUser()->writeUC();

            $whereClause = 'ses_id=' . self::getDatabaseConnection()->fullQuoteStr(self::getBackendUser()->id, 'be_sessions');
            $whereClause .= ' AND ses_name=' . self::getDatabaseConnection()->fullQuoteStr(BackendUserAuthentication::getCookieName(), 'be_sessions');
            $whereClause .= ' AND ses_userid=' . (int)self::getBackendUser()->user['uid'];

            self::getDatabaseConnection()->exec_UPDATEquery(
                'be_sessions',
                $whereClause,
                $updateData
            );

            $redirectUrl = 'index.php' . ($GLOBALS['TYPO3_CONF_VARS']['BE']['interfaces'] ? '' : '?commandLI=1');
            HttpUtility::redirect($redirectUrl);
        }
    }

    /**
     * Returns the Backend User
     * @return BackendUserAuthentication
     */
    protected static function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * @return DatabaseConnection
     */
    protected static function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}
