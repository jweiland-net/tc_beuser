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

use TYPO3\CMS\Backend\Form\Utility\FormEngineUtility;
use TYPO3\CMS\Backend\Module\ModuleLoader;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Lang\LanguageService;

/**
 * OverviewUtility.php
 *
 * DESCRIPTION HERE
 * $Id$
 *
 * @author Ingo Renner <ingo.renner@dkd.de>
 * @author Ivan Kartolo <ivan.kartolo@dkd.de>
 */
class OverviewUtility
{

    public $row;
    /**
     * @var array $availableMethods a list of methods, that are directly available ( ~ the interface)
     */
    public $availableMethods = array(
        'renderColFilemounts',
        'renderColWebmounts',
        'renderColPagetypes',
        'renderColSelecttables',
        'renderColModifytables',
        'renderColNonexcludefields',
        'renderColExplicitallowdeny',
        'renderColLimittolanguages',
        'renderColWorkspaceperms',
        'renderColWorkspacememship',
        'renderColDescription',
        'renderColModules',
        'renderColTsconfig',
        'renderColTsconfighl',
        'renderColMembers',
    );

    public $backPath;

    /**
     * The table which is used
     *
     * @var string
     */
    public $table;

    /**
     * IconFactory
     *
     * @var IconFactory
     */
    protected $iconFactory;

    /**
     * Space icon used for alignment
     *
     * @var string
     */
    protected $spaceIcon;

    /**
     * OverviewUtility constructor.
     */
    public function __construct()
    {
        $this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $this->spaceIcon = '<span class="btn btn-default disabled">' .
            $this->iconFactory->getIcon('empty-empty', Icon::SIZE_SMALL)->render() .
            '</span>';
    }


    /**
     * method dispatcher
     * checks input vars and returns result of desired method if available
     *
     * @param string $method defines what to return
     * @param int $groupId
     * @param bool $open
     * @param string $backPath
     * @return string
     */
    public function handleMethod($method, $groupId, $open = false, $backPath = '')
    {

        $this->getLanguageService()->includeLLFile('EXT:tc_beuser/Resources/Private/Language/locallangOverview.xlf');

        $content = '';
        $method = trim(strval($method));
        $groupId = intval($groupId);
        $open = (bool) $open;

        // We need some uid in rootLine for the access check, so use first webmount
        $webmounts = $this->getBackendUser()->returnWebmounts();
        $this->pageinfo['uid'] = $webmounts[0];

        if (in_array($method, $this->availableMethods)) {
            $content = $this->$method($groupId, $open, $backPath);
        }

        return $content;
    }


    public function getTable($row, $setCols)
    {
        $content = '';
        $this->row = $row;

        $out = $this->renderListHeader($setCols);

        $cc = 0;
        $groups = GeneralUtility::intExplode(',', $row['usergroup']);
        foreach ($groups as $groupId) {
            if ($groupId != 0) {
                $tree = $this->getGroupTree($groupId);

                foreach ($tree as $row) {
                    $tCells = $this->renderListRow($setCols, $row, '');

                    $out .= '
<tr class="db_list_normal">
	'.implode('', $tCells).'
</tr>';
                    $cc++;
                }
            } else {
                return '<br /><br />' .
                $this->getLanguageService()->sL('LLL:EXT:tc_beuser/Resources/Private/Language/locallangGroupAdmin.xlf:not-found') .
                '<br />';
            }
        }

        $content .= '<table border="0" cellpadding="0" cellspacing="0" class="table table-striped table-hover">
					'.$out.'
				</table>'."\n";

        return $content;
    }

    /**
     * only used for group view
     */
    public function getTableGroup($row, $setCols)
    {
        $content = '';
        $this->row = $row;

        $out = $this->renderListHeader($setCols);

        $cc = 0;
        $groups = GeneralUtility::intExplode(',', $row['uid']);
        foreach ($groups as $groupId) {
            $tree = $this->getGroupTree($groupId);
            foreach ($tree as $row) {
                $tCells = $this->renderListRow($setCols, $row, '');
                $out .= '
<tr class="db_list_normal">
	'.implode('', $tCells).'
</tr>';
                $cc++;
            }
        }

        $content .= '<table border="0" cellpadding="0" cellspacing="0" class="table table-striped table-hover">
					'.$out.'
				</table>'."\n";

        return $content;
    }

    public function renderListHeader($setCols)
    {
        $content = '';
        $content .= '
			<thead>
				<th class="t3-row-header" colspan="'.(count($setCols) + 2).'">&nbsp;</th>
			</thead>'."\n";

        $content .= '<tr>'."\n";

            // always show groups and Id
        $label = $this->getLanguageService()->getLL('showCol-groups');
        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
        $content .= $this->wrapTd('ID:', 'class="c-headLine"');

        if (count($setCols)) {
            foreach ($setCols as $col => $set) {
                switch ($col) {
                    case 'members':
                        $label = $this->getLanguageService()->getLL('showCol-members');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'filemounts':
                        $label = $this->getLanguageService()->getLL('showCol-filemounts');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'webmounts':
                        $label = $this->getLanguageService()->getLL('showCol-webmounts');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'pagetypes':
                        $label = $this->getLanguageService()->getLL('showCol-pagetypes');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'selecttables':
                        $label = $this->getLanguageService()->getLL('showCol-selecttables');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'modifytables':
                        $label = $this->getLanguageService()->getLL('showCol-modifytables');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'nonexcludefields':
                        $label = $this->getLanguageService()->getLL('showCol-nonexcludefields');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'explicitallowdeny':
                        $label = $this->getLanguageService()->getLL('showCol-explicitallowdeny');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'limittolanguages':
                        $label = $this->getLanguageService()->getLL('showCol-limittolanguages');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'workspaceperms':
                        $label = $this->getLanguageService()->getLL('showCol-workspaceperms');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'workspacememship':
                        $label = $this->getLanguageService()->getLL('showCol-workspacememship');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'description':
                        $label = $this->getLanguageService()->getLL('showCol-description');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'modules':
                        $label = $this->getLanguageService()->getLL('showCol-modules');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'tsconfig':
                        $label = $this->getLanguageService()->getLL('showCol-tsconfig');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                    case 'tsconfighl':
                        $label = $this->getLanguageService()->getLL('showCol-tsconfighl');
                        $content .= $this->wrapTd($label.':', 'class="c-headLine"');
                        break;
                }
            }
        }
        $content .= '</tr>'."\n";

        return $content;
    }

    public function renderListRow($setCols, $treeRow, $class)
    {
        $tCells = array();

            // title:
        $rowTitle = $treeRow['HTML'].' '.htmlspecialchars($treeRow['row']['title']);
        $tCells[] = $this->wrapTd($rowTitle, 'nowrap="nowrap"', $class);
            // id
        $tCells[] = $this->wrapTd($treeRow['row']['uid'], 'nowrap="nowrap"', $class);

        if (count($setCols)) {
            foreach ($setCols as $colName => $set) {
                $td = call_user_func(
                    array(
                        &$this,
                        'renderCol'.ucfirst($colName)
                    ),
                    $treeRow['row']['uid'],
                    ''
                );

                $tCells[] = $this->wrapTd($td, 'id="'.mt_rand().'" nowrap="nowrap"', $class);
            }
        }

        return $tCells;
    }

    public function renderColFilemounts($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $backPath = $backPath ? $backPath : $GLOBALS['SOBE']->doc->backPath;
        $title    = $this->getLanguageService()->getLL('showCol-filemounts');
        $icon = $this->getTreeControlIcon($open);

        $this->table = 'sys_filemounts';
        $this->backPath = $backPath;
        if ($open) {
            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'file_mountpoints',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);

            $fileMounts = GeneralUtility::intExplode(',', $row['file_mountpoints']);
            $items = array();
            if (is_array($fileMounts) && $fileMounts[0] != 0) {
                $content .= '<br />';
                foreach ($fileMounts as $fm) {
                    $res = $this->getDatabaseConnection()->exec_SELECTquery(
                        '*',
                        $this->table,
                        'uid = '.$fm
                    );
                    $filemount = $this->getDatabaseConnection()->sql_fetch_assoc($res);

                    $fmIcon = $this->iconFactory->getIconForRecord(
                        $this->table,
                        $filemount,
                        Icon::SIZE_SMALL
                    )->render();

                    $items[] = '<tr><td>' .
                        $fmIcon . $filemount['title'] . '&nbsp;' .
                        '</td><td>' .
                        $this->makeUserControl($filemount) .
                        '</td></tr>'."\n";
                }
            }
            $content .= '<table>'.implode('', $items).'</table>';
        }
        $toggle = '<span onclick="updateData(this, \'renderColFilemounts\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColWebmounts($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $backPath = $backPath ? $backPath : $GLOBALS['SOBE']->doc->backPath;
        $title    = $this->getLanguageService()->getLL('showCol-webmounts');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'db_mountpoints',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);

            $webMounts = GeneralUtility::intExplode(',', $row['db_mountpoints']);
            if (is_array($webMounts) && $webMounts[0] != 0) {
                $content .= '<br />';
                foreach ($webMounts as $wm) {
                    $webmount = $this->getDatabaseConnection()->exec_SELECTgetRows(
                        'uid, title, nav_hide, doktype, module',
                        'pages',
                        'uid = '.$wm
                    );
                    $webmount = $webmount[0];

                    $wmIcon = $this->iconFactory->getIconForRecord(
                        'pages',
                        $webmount,
                        Icon::SIZE_SMALL
                    )->render();

                    $content .= $wmIcon . $webmount['title'] . '&nbsp;' . '<br />' . "\n";
                }
            }
        }

        $toggle = '<span onclick="updateData(this, \'renderColWebmounts\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColPagetypes($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $backPath = $backPath ? $backPath : $GLOBALS['SOBE']->doc->backPath;
        $title    = $this->getLanguageService()->getLL('showCol-pagetypes');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $content .= '<br />';

            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'pagetypes_select',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);

            $pageTypes = explode(',', $row['pagetypes_select']);
            reset($pageTypes);
            while (list($kk, $vv) = each($pageTypes)) {
                if (!empty($vv)) {
                    $ptIcon = $this->iconFactory->getIconForRecord(
                        'pages',
                        array('doktype' => $vv),
                        Icon::SIZE_SMALL
                    )->render();

                    $content .= $ptIcon . '&nbsp;' .
                        $this->getLanguageService()->sL(BackendUtility::getLabelFromItemlist('pages', 'doktype', $vv));
                    $content .= '<br />'."\n";
                }
            }
        }

        $toggle = '<span onclick="updateData(this, \'renderColPagetypes\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColSelecttables($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $title    = $this->getLanguageService()->getLL('showCol-selecttables');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $content .= '<br />';

            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'tables_select',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);
            $tablesSelect = explode(',', $row['tables_select']);
            reset($tablesSelect);
            while (list($kk, $vv) = each($tablesSelect)) {
                if (!empty($vv)) {
                    $ptIcon= $this->iconFactory->getIconForRecord(
                        'pages',
                        array(),
                        Icon::SIZE_SMALL
                    )->render();

                    $tableTitle = $GLOBALS['TCA'][$vv]['ctrl']['title'];
                    $content .= $ptIcon . '&nbsp;' .
                        $this->getLanguageService()->sL($tableTitle);
                    $content .= '<br />'."\n";
                }
            }
        }

        $toggle = '<span onclick="updateData(this, \'renderColSelecttables\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColModifytables($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $title    = $this->getLanguageService()->getLL('showCol-modifytables');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $content .= '<br />';

            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'tables_modify',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);
            $tablesModify = explode(',', $row['tables_modify']);
            reset($tablesModify);
            while (list($kk, $vv) = each($tablesModify)) {
                if (!empty($vv)) {
                    $ptIcon = $this->iconFactory->getIconForRecord(
                        $vv,
                        array(),
                        Icon::SIZE_SMALL
                    )->render();

                    $tableTitle = $GLOBALS['TCA'][$vv]['ctrl']['title'];
                    $content .= $ptIcon . '&nbsp;' . $this->getLanguageService()->sL($tableTitle);
                    $content .= '<br />'."\n";
                }
            }
        }

        $toggle = '<span onclick="updateData(this, \'renderColModifytables\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColNonexcludefields($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $title    = $this->getLanguageService()->getLL('showCol-nonexcludefields');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $content .= '<br />';

            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'non_exclude_fields',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);
            $non_exclude_fields = explode(',', $row['non_exclude_fields']);
            reset($non_exclude_fields);
            while (list($kk, $vv) = each($non_exclude_fields)) {
                if (!empty($vv)) {
                    $data = explode(':', $vv);
                    $tableTitle = $GLOBALS['TCA'][$data[0]]['ctrl']['title'];
                    $fieldTitle = $GLOBALS['TCA'][$data[0]]['columns'][$data[1]]['label'];
                    $content .= $this->getLanguageService()->sL($tableTitle) . ': ' .
                        rtrim($this->getLanguageService()->sL($fieldTitle), ':');
                    $content .= '<br />'."\n";
                }
            }
        }

        $toggle = '<span onclick="updateData(this, \'renderColNonexcludefields\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColExplicitallowdeny($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $backPath = $backPath ? $backPath : $GLOBALS['SOBE']->doc->backPath;
        $title    = $this->getLanguageService()->getLL('showCol-explicitallowdeny');
        $icon = $this->getTreeControlIcon($open);

        $adLabel = array(
            'ALLOW' => $this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xml:labels.allow'),
            'DENY' => $this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xml:labels.deny'),
        );

        $iconsPath = array(
            'ALLOW' => $this->iconFactory->getIcon('status-status-permission-granted', Icon::SIZE_SMALL)->render(),
            'DENY' => $this->iconFactory->getIcon('status-status-permission-denied', Icon::SIZE_SMALL)->render(),
        );

        if ($open) {
            $content .= '<br />';
            $data = '';

            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'explicit_allowdeny',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);
            if (!empty($row['explicit_allowdeny'])) {
                $explicit_allowdeny = explode(',', $row['explicit_allowdeny']);
                reset($explicit_allowdeny);
                foreach ($explicit_allowdeny as $val) {
                    $dataParts = explode(':', $val);
                    $items = $GLOBALS['TCA'][$dataParts[0]]['columns'][$dataParts[1]]['config']['items'];
                    foreach ($items as $val) {
                        if ($val[1] == $dataParts[2]) {
                            $data .= $iconsPath[$dataParts['3']] .
                                ' ['.$adLabel[$dataParts['3']].'] '.
                                $this->getLanguageService()->sL($val[0]).'<br />';
                        }
                    }
                }
            }
            $content .= $data .'<br />';
        }

        $toggle = '<span onclick="updateData(this, \'renderColExplicitallowdeny\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColLimittolanguages($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $backPath = $backPath ? $backPath : $GLOBALS['SOBE']->doc->backPath;
        $title    = $this->getLanguageService()->getLL('showCol-limittolanguages');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $content .= '<br />';
            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'allowed_languages',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);
            $allowed_languages = explode(',', $row['allowed_languages']);
            reset($allowed_languages);
            $availLang = BackendUtility::getSystemLanguages();

            $data = '';
            foreach ($allowed_languages as $langId) {
                foreach ($availLang as $availLangInfo) {
                    if ($availLangInfo[1] == $langId) {
                        $iconFlag = FormEngineUtility::getIconHtml($availLangInfo[2]);
                        $data .= $iconFlag . '&nbsp;' . $availLangInfo[0].'<br />';
                    }
                }
            }
            $content .= $data .'<br />';
        }

        $toggle = '<span onclick="updateData(this, \'renderColLimittolanguages\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColWorkspaceperms($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $title    = $this->getLanguageService()->getLL('showCol-workspaceperms');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $content .= '<br />';
            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'workspace_perms',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);
            $permissions = floatval($row['workspace_perms']);
            $items = $GLOBALS['TCA']['be_groups']['columns']['workspace_perms']['config']['items'];
            $check = array();
            foreach ($items as $key => $val) {
                if ($permissions & pow(2, $key)) {
                    $check[] = $this->getLanguageService()->sL($val[0]);
                }
            }
            $content .= implode('<br />', $check);
        }
        $toggle = '<span onclick="updateData(this, \'renderColWorkspaceperms\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColWorkspacememship($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $title    = $this->getLanguageService()->getLL('showCol-workspacememship');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $content .= '<br />';
            $userAuthGroup = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Authentication\\BackendUserAuthentication');
                //get workspace perms
            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'workspace_perms',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);
            $userAuthGroup->groupData['workspace_perms'] = $row['workspace_perms'];

                // Create accessible workspace arrays:
            $options = array();
            if ($userAuthGroup->checkWorkspace(array('uid' => 0))) {
                $options[0] = '0: [LIVE]';
            }
            if ($userAuthGroup->checkWorkspace(array('uid' => -1))) {
                $options[-1] = '-1: [Default Draft]';
            }
                // Add custom workspaces (selecting all, filtering by BE_USER check):
            $workspaces = $this->getDatabaseConnection()->exec_SELECTgetRows(
                'uid,title,adminusers,members,reviewers,db_mountpoints',
                'sys_workspace',
                'pid=0' . BackendUtility::deleteClause('sys_workspace'),
                '',
                'title'
            );
            if (count($workspaces)) {
                foreach ($workspaces as $rec) {
                    if ($userAuthGroup->checkWorkspace($rec)) {
                        $options[$rec['uid']] = $rec['uid'].': '.$rec['title'];

                            // Check if all mount points are accessible, otherwise show error:
                        if (trim($rec['db_mountpoints'])!=='') {
                            $mountPoints = GeneralUtility::intExplode(',', $userAuthGroup->workspaceRec['db_mountpoints'], 1);
                            foreach ($mountPoints as $mpId) {
                                if (!$userAuthGroup->isInWebMount($mpId, '1=1')) {
                                    $options[$rec['uid']].= '<br> \- WARNING: Workspace Webmount page id "'.$mpId.'" not accessible!';
                                }
                            }
                        }
                    }
                }
            }
            $content .= implode('<br />', $options);
        }

        $toggle = '<span onclick="updateData(this, \'renderColWorkspacememship\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColDescription($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $title    = $this->getLanguageService()->getLL('showCol-description');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'description',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);
            $content .= '<br />';

            $content .= '<pre>'.$row['description'].'</pre><br />'."\n";
        }

        $toggle = '<span onclick="updateData(this, \'renderColDescription\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColModules($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $backPath = $backPath ? $backPath : $GLOBALS['SOBE']->doc->backPath;
        $title    = $this->getLanguageService()->getLL('showCol-modules');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $content .='<br />';

            // get all modules
            /** @var ModuleLoader $loadModules */
            $loadModules = GeneralUtility::makeInstance(ModuleLoader::class);
            $loadModules->load($GLOBALS['TBE_MODULES']);

            $table = 'be_groups';
            // get selected module from the table (be_users or be_groups)
            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                '*',
                $table,
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);


            //var_dump($allMods);
            $items = array();
            foreach ($loadModules->modListGroup as $id => $moduleName) {
                if (GeneralUtility::inList($row['groupMods'], $moduleName)) {
                    $moduleIcon = $this->getLanguageService()->moduleLabels['tabs_images'][$moduleName . '_tab'];
                    if ($moduleIcon) {
                        $moduleIcon  = '../' . PathUtility::stripPathSitePrefix($moduleIcon);
                    }

                    $moduleLabel = '';
                    // Add label for main module:
                    $pp = explode('_', $moduleName);
                    if (count($pp) > 1) {
                        $moduleLabel .= $this->getLanguageService()->moduleLabels['tabs'][$pp[0] . '_tab'] . '>';
                    }
                    // Add modules own label now:
                    $moduleLabel .= $this->getLanguageService()->moduleLabels['tabs'][$moduleName . '_tab'];

                    $items[] = '<img src="' . $moduleIcon . '" width="16" height="16"/>&nbsp;'. $moduleLabel;
                }
            }
            $content .= implode('<br />', $items);
        }

        $toggle = '<span onclick="updateData(this, \'renderColModules\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColTsconfig($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $title    = $this->getLanguageService()->getLL('showCol-tsconfig');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'TSconfig',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);

            $content .= '<pre>'.$row['TSconfig'].'</pre><br />'."\n";
        }

        $toggle = '<span onclick="updateData(this, \'renderColTsconfig\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColTsconfighl($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $title    = $this->getLanguageService()->getLL('showCol-tsconfighl');
        $icon = $this->getTreeControlIcon($open);

        if ($open) {
            $tsparser = GeneralUtility::makeInstance(TypoScriptParser::class);
            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                'TSconfig',
                'be_groups',
                'uid = '.$groupId
            );
            $row = $this->getDatabaseConnection()->sql_fetch_assoc($res);
            $content = $tsparser->doSyntaxHighlight($row['TSconfig'], '', 1);
        }

        $toggle = '<span onclick="updateData(this, \'renderColTsconfighl\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    public function renderColMembers($groupId, $open = false, $backPath = '')
    {
        $content  = '';
        $backPath = $backPath ? $backPath : $GLOBALS['SOBE']->doc->backPath;
        $title    = $this->getLanguageService()->getLL('showCol-members');
        $icon = $this->getTreeControlIcon($open);

        $this->backPath = $backPath;
        $this->table = 'be_users';
        if ($open) {
            $content .= '<br />';
            $res = $this->getDatabaseConnection()->exec_SELECTquery(
                '*',
                'be_users',
                'usergroup like ' .
                $this->getDatabaseConnection()->fullQuoteStr(
                    '%' . $groupId . '%',
                    'be_users'
                ) .
                BackendUtility::deleteClause('be_users')
            );
            $members = array();
            while ($row = $this->getDatabaseConnection()->sql_fetch_assoc($res)) {
                if (GeneralUtility::inList($row['usergroup'], $groupId)) {
                    //$members[] = $row;
                    $fmIcon = $this->iconFactory->getIconForRecord(
                        'be_users',
                        $row,
                        Icon::SIZE_SMALL
                    )->render();

                    $members[] = '<tr><td>'.$fmIcon.' '.$row['realName'].' ('.$row['username'].')</td><td>'.$this->makeUserControl($row).'</td></tr>';
                }
            }
            $content .= '<table>'.implode('', $members).'</table>';
        }

        $toggle = '<span onclick="updateData(this, \'renderColMembers\', '
            .$groupId.', '
            .($open?'0':'1')
            .');" style="cursor: pointer;">'
            . $icon . $title
            .'</span>';

        return $toggle . $content;
    }

    /**
     * from mod4/index.php
     */
    public function editOnClick($params, $requestUri = '')
    {
        $retUrl = '&returnUrl=' . ($requestUri == -1 ? "'+T3_THIS_LOCATION+'" : rawurlencode($requestUri ? $requestUri : GeneralUtility::getIndpEnv('REQUEST_URI')));
        return "window.location.href='". BackendUtility::getModuleUrl('tcTools_UserAdmin') . $retUrl . $params . "'; return false;";
    }

    public function makeUserControl($userRecord)
    {
        $this->calcPerms = $this->getBackendUser()->calcPerms($this->pageinfo);
        $permsEdit = $this->calcPerms&16;

        $control = '';

        if ($this->table == 'be_users' && $permsEdit) {
            // edit
            $control = '<a href="#" class="btn btn-default" onclick="' . htmlspecialchars(
                $this->editOnClick('&edit[' . $this->table . '][' . $userRecord['uid'] . ']=edit&SET[function]=edit', -1)
            ) . '">' .
                $this->iconFactory->getIcon('actions-open', Icon::SIZE_SMALL)->render() .
                '</a>' . chr(10);
        }

            //info
        if ($this->getBackendUser()->check('tables_select', $this->table)
            && is_array(BackendUtility::readPageAccess($userRecord['pid'], $this->getBackendUser()->getPagePermsClause(1)))
        ) {
            $onClick = 'top.launchView(\'' . $this->table . '\', \'' . $userRecord['uid'] . '\'); return false;';
            $control .= '<a href="#" class="btn btn-default" onclick="' . htmlspecialchars($onClick) . '">' .
                $this->iconFactory->getIcon('actions-document-info', Icon::SIZE_SMALL)->render() .
                '</a>' . chr(10);
        }

            // hide/unhide
        $hiddenField = $GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['disabled'];
        if ($permsEdit) {
            $redirect = '&redirect=\'+T3_THIS_LOCATION+\'&vC=' . rawurlencode($this->getBackendUser()->veriCode()) . '&prErr=1&uPT=1';
            if ($userRecord[$hiddenField]) {
                $params = '&data[' . $this->table . '][' . $userRecord['uid'] . '][' . $hiddenField . ']=0&SET[function]=action';
                $control .= '<a href="#" class="btn btn-default" onclick="' . htmlspecialchars('return jumpToUrl(\'' .
                    BackendUtility::getModuleUrl('tcTools_UserAdmin') . $params . $redirect . '\');') . '">' .
                    $this->iconFactory->getIcon('actions-edit-unhide', Icon::SIZE_SMALL)->render() .
                    '</a>' . chr(10);
            } else {
                $params = '&data[' . $this->table . '][' . $userRecord['uid'] . '][' . $hiddenField . ']=1&SET[function]=action';
                $control .= '<a href="#" class="btn btn-default" onclick="' . htmlspecialchars('return jumpToUrl(\'' .
                    BackendUtility::getModuleUrl('tcTools_UserAdmin') . $params . $redirect . '\');') . '">' .
                    $this->iconFactory->getIcon('actions-edit-hide', Icon::SIZE_SMALL)->render() .
                    '</a>' . chr(10);
            }
        }

            // delete
        if ($permsEdit) {
            $params = '&cmd[' . $this->table . '][' . $userRecord['uid'] . '][delete]=1&SET[function]=action';
            $redirect = '&redirect=\'+T3_THIS_LOCATION+\'&vC=' . rawurlencode($this->getBackendUser()->veriCode()) . '&prErr=1&uPT=1';
            $control .= '<a href="#" class="btn btn-default" onclick="' . htmlspecialchars(
                'if (confirm(' .
                GeneralUtility::quoteJSvalue(
                    $this->getLanguageService()->getLL('deleteWarning') .
                    BackendUtility::referenceCount(
                        $this->table,
                        $userRecord['uid'],
                        ' (There are %s reference(s) to this record!)'
                    )
                ) . ')) {jumpToUrl(\'' . BackendUtility::getModuleUrl('tcTools_UserAdmin') . $params . $redirect . '\');} return false;'
            ) . '">' .
                $this->iconFactory->getIcon('actions-edit-delete', Icon::SIZE_SMALL)->render() .
            '</a>' . chr(10);
        }

            // switch user / switch user back
        if ($this->table == 'be_users' && $permsEdit && $this->getBackendUser()->isAdmin()) {
            if (!$userRecord[$GLOBALS['TCA'][$this->table]['ctrl']['enablecolumns']['disabled']] &&
                ($GLOBALS['BE_USER']->user['tc_beuser_switch_to'] || $GLOBALS['BE_USER']->isAdmin())) {
                if ($this->isRecordCurrentBackendUser($this->table, $userRecord)) {
                    $control .= $this->spaceIcon;
                } else {
                    $control .= '<a class="btn btn-default" href="' . GeneralUtility::linkThisScript(array('SwitchUser' => $userRecord['uid'])) . '" '.
                    'target="_top" title="' . htmlspecialchars('Switch user to: ' . $userRecord['username']) . '" >' .
                        $this->iconFactory->getIcon('actions-system-backend-user-switch', Icon::SIZE_SMALL)->render() .
                        '</a>' .
                        chr(10) . chr(10);
                }
            } else {
                $control .= $this->spaceIcon;
            }
        }

        return $control;
    }

    public function getGroupTree($groupId)
    {
        $treeStartingPoint  = $groupId;
        $treeStartingRecord = BackendUtility::getRecord('be_groups', $treeStartingPoint);
        $depth = 10;

            // Initialize tree object:
        /** @var \dkd\TcBeuser\Utility\GroupTreeUtility $tree */
        $tree = GeneralUtility::makeInstance('dkd\\TcBeuser\\Utility\\GroupTreeUtility');
        $tree->init();
        $tree->expandAll = true;

            // Creating top icon; the main group
        $html = $this->iconFactory->getIconForRecord('be_groups', $treeStartingRecord, Icon::SIZE_SMALL)->render();
        $tree->tree[] = array(
            'row' => $treeStartingRecord,
            'HTML' => $html
        );

        $dataTree = array();
        $dataTree[$groupId] = $tree->buildTree($groupId);
        $tree->setDataFromArray($dataTree);
            // Create the tree from starting point:
        if ($depth > 0) {
            // need to fake admin because getTree check if pid in webmount
            if ($this->getBackendUser()->user['admin'] != 1) {
                //make fake Admin
                TcBeuserUtility::fakeAdmin();
                $fakeAdmin = 1;
            }

            $tree->getTree($treeStartingPoint, $depth);

            // remove fake admin access
            if ($fakeAdmin) {
                TcBeuserUtility::removeFakeAdmin();
            }
        }

        return $tree->tree;
    }

    public function wrapTd($str, $tdParams = '', $class = '', $style = '')
    {
        return "\t".'<td'
            .($tdParams ? ' '.$tdParams : '')
            .($class ? ' class="'.$class.'"' : '')
            .' style="vertical-align: top;'.($style ? ' '.$style : '').'">'.$str.'</td>'."\n";
    }

    /**
     * Get icon fot the tree
     *
     * @param string $status status of the tree
     * @return string
     */
    protected function getTreeControlIcon($status)
    {
        if ($status) {
            $treeIconStatus = 'expand';
        } else {
            $treeIconStatus = 'collapse';
        }

        return $this->iconFactory->getIcon('apps-pagetree-' . $treeIconStatus, Icon::SIZE_SMALL)->render();
    }

    /**
     * Returns the Language Service
     * @return LanguageService
     */
    protected function getLanguageService()
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Returns the Backend User
     * @return BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * @return DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }

    /**
     * Check if the record represents the current backend user
     *
     * @param string $table
     * @param array $row
     * @return bool
     */
    protected function isRecordCurrentBackendUser($table, $row)
    {
        return $table === 'be_users' && (int)$row['uid'] === $this->getBackendUser()->user['uid'];
    }
}
