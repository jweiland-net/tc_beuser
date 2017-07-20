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
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Tree\View\AbstractTreeView;

/**
 * GroupTreeUtility.php
 *
 * DESCRIPTION HERE
 * $Id$
 *
 * @author Ingo Renner <ingo.renner@dkd.de>
 */
class GroupTreeUtility extends AbstractTreeView
{
    public $fieldArray = array('uid', 'title');
    public $defaultList = 'uid,title';

    /**
     * Init function
     * REMEMBER to feed a $clause which will filter out non-readable pages!
     *
     * @param string $clause Part of where query which will filter out non-readable pages.
     * @param string $orderByFields Record ORDER BY field
     * @return void
     */
    public function init($clause = '', $orderByFields = '')
    {
        parent::init(' AND deleted=0 '.$clause, 'title');

        $this->table    = 'be_groups';
        $this->treeName = 'groups';
    }

    /**
     * recursivly builds a data array from a root $id which is than used to
     * build a tree from it.
     *
     * @param integer $id the root id from where to start
     * @return array hierarical array with tree data
     */
    public function buildTree($id)
    {
        $tree = array();

        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
            'uid, title, subgroup',
            'be_groups',
            'deleted = 0 AND uid = '.$id
        );

        $row         = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
        $tree[$id]   = $row;

        if ($row['subgroup']) {
            $subGroups = GeneralUtility::intExplode(',', $row['subgroup']);
            foreach ($subGroups as $newGroupId) {
                $row[$this->subLevelID][$newGroupId] = $this->buildTree($newGroupId);
            }
            return $tree[$id] = $row;
        } else {
            return $row;
        }
    }

    /**
     * Fetches the data for the tree
     *
     * @param int $uid item id for which to select subitems (parent id)
     * @param int $depth Max depth (recursivity limit)
     * @param string $depthData HTML-code prefix for recursive calls.

     * @return int The count of items on the level
     */
    public function getTree($uid, $depth = 999, $depthData = '')
    {
        // Buffer for id hierarchy is reset:
        $this->buffer_idH = array();
        // Init vars
        $depth = (int)$depth;
        $HTML = '';
        $a = 0;
        $res = $this->getDataInit($uid);
        $c = $this->getDataCount($res);
        $crazyRecursionLimiter = 999;
        $idH = array();
        // Traverse the records:
        while ($crazyRecursionLimiter > 0 && ($row = $this->getDataNext($res))) {
            $pageUid = ($this->table === 'pages') ? $row['uid'] : $row['pid'];
            if (!$this->getBackendUser()->isInWebMount($pageUid)) {
                // Current record is not within web mount => skip it
                continue;
            }

            $a++;
            $crazyRecursionLimiter--;
            $newID = $row['uid'];
            if ($newID == 0) {
                throw new \RuntimeException('Endless recursion detected: TYPO3 has detected an error in the database. Please fix it manually (e.g. using phpMyAdmin) and change the UID of ' . $this->table . ':0 to a new value. See http://forge.typo3.org/issues/16150 to get more information about a possible cause.', 1294586383);
            }
            // Reserve space.
            $this->tree[] = array();
            end($this->tree);
            // Get the key for this space
            $treeKey = key($this->tree);
            // If records should be accumulated, do so
            if ($this->setRecs) {
                $this->recs[$row['uid']] = $row;
            }
            // Accumulate the id of the element in the internal arrays
            $this->ids[] = ($idH[$row['uid']]['uid'] = $row['uid']);
            $this->ids_hierarchy[$depth][] = $row['uid'];
            $this->orig_ids_hierarchy[$depth][] = $row['_ORIG_uid'] ?: $row['uid'];

            // Make a recursive call to the next level
            $nextLevelDepthData = $depthData . '<span class="treeline-icon treeline-icon-' . ($a === $c ? 'clear' : 'line') . '"></span>';
            $hasSub = $this->expandNext($newID) && !$row['php_tree_stop'];
            if ($depth > 1 && $hasSub) {
                $nextCount = $this->getTree($newID, $depth - 1, $nextLevelDepthData);
                if (!empty($this->buffer_idH)) {
                    $idH[$row['uid']]['subrow'] = $this->buffer_idH;
                }
                // Set "did expand" flag
                $isOpen = 1;
            } else {
                $nextCount = $this->getCount($newID);
                // Clear "did expand" flag
                $isOpen = 0;
            }

            // if first element
            if ($a === 1) {
                $treeIcon = $depthData . '<span class="treeline-icon"></span>';
            }

            // last element and no subs
            if ($a === $c) {
                $treeIcon = $depthData . '<span class="treeline-icon treeline-icon-joinbottom"></span>';
            }

            // Set HTML-icons, if any:
            if ($this->makeHTML) {
                $HTML = $treeIcon . $this->PMicon($row, $a, $c, $nextCount, $isOpen) . $this->wrapStop($this->getIcon($row), $row);
            }
            // Finally, add the row/HTML content to the ->tree array in the reserved key.
            $this->tree[$treeKey] = array(
                'row' => $row,
                'HTML' => $HTML,
                'invertedDepth' => $depth,
                'depthData' => $depthData,
                'bank' => $this->bank,
                'hasSub' => $nextCount && $hasSub,
                'isFirst' => $a === 1,
                'isLast' => $a === $c,
            );
        }

        $this->getDataFree($res);
        $this->buffer_idH = $idH;
        return $c;
    }


    /**
     * Wrap the plus/minus icon in a link
     *
     * @param string $icon HTML string to wrap, probably an image tag.
     * @param string $cmd Command for 'PM' get var
     * @param string $bMark If set, the link will have an anchor point (=$bMark) and a name attribute (=$bMark)
     * @param bool $isOpen
     * @return string Link-wrapped input string
     * @access private
     */
    public function PM_ATagWrap($icon, $cmd, $bMark = '', $isOpen = false)
    {
        if ($this->thisScript) {
            $anchor = $bMark ? '#' . $bMark : '';
            $name = $bMark ? ' name="' . $bMark . '"' : '';
            $aUrl = $this->getThisScript() . 'PM=' . $cmd . $anchor;
            return '<span class="list-tree-control ' . ($isOpen ? 'list-tree-control-open' : 'list-tree-control-closed') . '" ' . $name . '><i class="fa"></i></span>';
        } else {
            return $icon;
        }
    }
}
