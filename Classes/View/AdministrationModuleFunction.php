<?php

namespace Tx\Realurl\View;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Helmut Hummel <helmut.hummel@typo3.org>
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
 *  A copy is found in the text file GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Backend\Tree\View\PageTreeView;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Class AdministrationModuleFunction
 */
class AdministrationModuleFunction extends \TYPO3\CMS\Backend\Module\AbstractFunctionModule
{

    const ICON_ERROR = 'status-dialog-error';
    const ICON_WARNING = 'status-dialog-warning';

    /**
     * @var int
     */
    protected $searchResultCounter = 0;

    /**
     * @var null|\TYPO3\CMS\Core\Imaging\IconFactory
     */
    protected $iconFactory = null;

    /**
     *
     */
    public function __construct()
    {
        $this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $this->getLanguageService()->includeLLfile('EXT:realurl/Resources/Private/Language/locallang_info_module.xml');
    }

    /**
     * Returns the menu array
     *
     * @return    array
     */
    public function modMenu()
    {
        $modMenu = array(
            'depth' => array(
                0 => $this->getLanguageService()->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:labels.depth_0'),
                1 => $this->getLanguageService()->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:labels.depth_1'),
                2 => $this->getLanguageService()->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:labels.depth_2'),
                3 => $this->getLanguageService()->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:labels.depth_3'),
                99 => $this->getLanguageService()->sL('LLL:EXT:lang/Resources/Private/Language/locallang_core.xlf:labels.depth_infi'),
            ),
            'type' => array(
                'pathcache' => 'ID-to-path mapping',
                'decode' => 'Decode cache',
                'encode' => 'Encode cache',
                'uniqalias' => 'Unique Aliases',
                'redirects' => 'Redirects',
                'config' => 'Configuration',
                'log' => 'Error Log'
            )
        );

        $modMenu['type'] = BackendUtility::unsetMenuItems($this->pObj->modTSconfig['properties'], $modMenu['type'],
            'menu.realurl_type');
        return $modMenu;
    }

    /**
     * MAIN function for cache information
     *
     * @return    string        Output HTML for the module.
     */
    public function main()
    {
        if ($this->pObj->id) {
            $result = $this->createModuleContentForPage();
        } else {
            $result = '<p>' . $this->getLanguageService()->getLL('no_page_id') . '</p>';
        }

        return $result;
    }

    /**
     * Enter description here ...
     */
    protected function createModuleContentForPage()
    {
        $result = $this->getFunctionMenu() . '<br>';

        switch ($this->pObj->MOD_SETTINGS['type']) {
            case 'pathcache':
                $this->edit_save();
                $result .= $this->getDepthSelector();
                $moduleContent = $this->renderModule($this->initializeTree());
                $result .= $this->renderSearchForm();
                $result .= $moduleContent;
                break;
            case 'encode':
                $result .= $this->getDepthSelector();
                $result .= $this->encodeView($this->initializeTree());
                break;
            case 'decode':
                $result .= $this->getDepthSelector();
                $result .= $this->decodeView($this->initializeTree());
                break;
            case 'uniqalias':
                $this->edit_save_uniqAlias();
                $result .= $this->uniqueAlias();
                break;
            case 'config':
                $result .= $this->getDepthSelector();
                $result .= $this->configView();
                break;
            case 'redirects':
                $result .= $this->redirectView();
                break;
            case 'log':
                $result .= $this->logView();
                break;
        }
        return $result;
    }

    /**
     * Obtains function selection menu.
     *
     * @return string
     */
    protected function getFunctionMenu()
    {
        return '<div class="form-group"><label>' . $this->getLanguageService()->getLL('function') . '</label>' .
            BackendUtility::getFuncMenu($this->pObj->id, 'SET[type]',
                $this->pObj->MOD_SETTINGS['type'], $this->pObj->MOD_MENU['type']) . '</div>';
    }

    /**
     * Creates depth selector HTML for the page tree.
     *
     * @return string
     */
    protected function getDepthSelector()
    {
        return '<div class="form-group"><label>' . $this->getLanguageService()->getLL('depth') . '</label>' .
            BackendUtility::getFuncMenu($this->pObj->id, 'SET[depth]', $this->pObj->MOD_SETTINGS['depth'],
                $this->pObj->MOD_MENU['depth']) . '</div>';
    }

    /**
     * Initializes the page tree.
     *
     * @return PageTreeView
     */
    protected function initializeTree()
    {
        $tree = GeneralUtility::makeInstance(PageTreeView::class);
        $tree->addField('nav_title', true);
        $tree->addField('alias', true);
        $tree->addField('tx_realurl_pathsegment', true);
        $tree->init('AND ' . $GLOBALS['BE_USER']->getPagePermsClause(1));

        $treeStartingPoint = intval($this->pObj->id);
        $treeStartingRecord = BackendUtility::getRecord('pages', $treeStartingPoint);
        BackendUtility::workspaceOL('pages', $treeStartingRecord);

        // Creating top icon; the current page
        $tree->tree[] = array(
            'row' => $treeStartingRecord,
            'HTML' => $tree->getIcon($treeStartingRecord)
        );

        // Create the tree from starting point:
        if ($this->pObj->MOD_SETTINGS['depth'] > 0) {
            $tree->getTree($treeStartingPoint, $this->pObj->MOD_SETTINGS['depth'], '');
        }
        return $tree;
    }

    /****************************
     *
     * Path Cache rendering:
     *
     ****************************/

    /**
     * Rendering the information
     *
     * @param    array        The Page tree data
     * @return    string        HTML for the information table.
     */
    public function renderModule(PageTreeView $tree)
    {

        // Initialize:
        $searchPath = trim(GeneralUtility::_GP('pathPrefixSearch'));
        $cmd = GeneralUtility::_GET('cmd');
        $entry = GeneralUtility::_GET('entry');
        $searchForm_replace = GeneralUtility::_POST('_replace');
        $searchForm_delete = GeneralUtility::_POST('_delete');

        $trackSameUrl = [];
        $this->searchResultCounter = 0;

        // Traverse tree:
        $output = '';
        $cc = 0;
        foreach ($tree->tree as $row) {

            // Get all pagepath entries for page:
            $pathCacheInfo = $this->getPathCache($row['row']['uid']);

            // Row title:
            $rowTitle = $row['HTML'] . BackendUtility::getRecordTitle('pages', $row['row'], true);
            $cellAttrib = ($row['row']['_CSSCLASS'] ? ' class="' . $row['row']['_CSSCLASS'] . '"' : '');

            // Add at least one empty element:
            if (!count($pathCacheInfo)) {

                // Add title:
                $tCells = [];
                $tCells[] = '<td nowrap="nowrap"' . $cellAttrib . '>' . ($row['depthData'] ?: '') . $rowTitle . '</td>';

                // Empty row:
                $tCells[] = '<td colspan="10">&nbsp;</td>';

                // Compile Row:
                $output .= '
						<tr>
							' . implode('
							', $tCells) . '
						</tr>';
                $cc++;
            } else {
                foreach ($pathCacheInfo as $c => $inf) {

                    // Init:
                    $deletedEntry = false;
                    $hash = $inf['pagepath'] . '|' . $inf['rootpage_id'] . '|' . $inf['language_id'];    // MP is not a part of this because the path itself should be different simply because the MP makes a different path! (see UriGeneratorAndResolver::pagePathtoID())

                    // Add icon/title and ID:
                    $tCells = [];
                    if (!$c) {
                        $tCells[] = '<td nowrap="nowrap" rowspan="' . count($pathCacheInfo) . '"' . $cellAttrib . '>' . $rowTitle . '</td>';
                        $tCells[] = '<td rowspan="' . count($pathCacheInfo) . '">' . $inf['page_id'] . '</td>';
                    }

                    // Add values from alternative field used to generate URL:
                    $baseRow = $row['row'];    // page row as base.
                    $onClick = BackendUtility::editOnClick('&edit[pages][' . $row['row']['uid'] . ']=edit&columnsOnly=title,nav_title,alias,tx_realurl_pathsegment');
                    $editIcon = '<a href="#" onclick="' . htmlspecialchars($onClick) . '">' .
                        $this->getIcon('actions-open') .
                        '</a>';
                    $onClick = BackendUtility::viewOnClick($row['row']['uid']);
                    $editIcon .= '<a href="#" onclick="' . htmlspecialchars($onClick) . '">' .
                        $this->getIcon('actions-search') .
                        '</a>';

                    if ($inf['language_id'] > 0) {    // For alternative languages, show another list of fields, form page overlay record:
                        $editIcon = '';
                        list($olRec) = BackendUtility::getRecordLocalization('pages', $row['row']['uid'],
                            intval($inf['language_id']));
                        if (is_array($olRec)) {
                            $baseRow = array_merge($baseRow, $olRec);
                            $onClick = BackendUtility::editOnClick('&edit[pages_language_overlay][' . $olRec['uid'] . ']=edit&columnsOnly=title,nav_title');
                            $editIcon = '<a href="#" onclick="' . htmlspecialchars($onClick) . '">' .
                                $this->getIcon('actions-open') .
                                '</a>';
                            $onClick = BackendUtility::viewOnClick($row['row']['uid'], '', null, '',
                                '&L=' . $olRec['sys_language_uid']);
                            $editIcon .= '<a href="#" onclick="' . htmlspecialchars($onClick) . '">' .
                                $this->getIcon('actions-search') .
                                '</a>';
                        } else {
                            $baseRow = [];
                        }
                    }
                    $tCells[] = '<td>' . $editIcon . '</td>';

                    // 	Sources for segment:
                    $sources = count($baseRow) ? implode(' | ', array(
                        $baseRow['tx_realurl_pathsegment'],
                        $baseRow['alias'],
                        $baseRow['nav_title'],
                        $baseRow['title']
                    )) : '';
                    $tCells[] = '<td nowrap="nowrap">' . htmlspecialchars($sources) . '</td>';

                    // Show page path:
                    if (strcmp($searchPath, '') && GeneralUtility::isFirstPartOfStr($inf['pagepath'],
                            $searchPath) && !$inf['expire']) {

                        // Delete entry:
                        if ($searchForm_delete) {
                            $this->deletePathCacheEntry($inf['cache_id']);
                            $deletedEntry = true;
                            $pagePath = '[DELETED]';
                        } elseif ($searchForm_replace) {
                            $replacePart = trim(GeneralUtility::_POST('pathPrefixReplace'));
                            $this->editPathCacheEntry($inf['cache_id'],
                                $replacePart . substr($inf['pagepath'], strlen($searchPath)));

                            $pagePath =
                                '<span class="typo3-red">' .
                                htmlspecialchars($replacePart) .
                                '</span>' .
                                htmlspecialchars(substr($inf['pagepath'], strlen($searchPath)));
                        } else {
                            $pagePath =
                                '<span class="typo3-red">' .
                                htmlspecialchars(substr($inf['pagepath'], 0, strlen($searchPath))) .
                                '</span>' .
                                htmlspecialchars(substr($inf['pagepath'], strlen($searchPath)));
                            $this->searchResultCounter++;
                        }
                    } else {
                        // Delete entries:
                        if ($cmd === 'edit' && (!strcmp($entry, $inf['cache_id']) || !strcmp($entry, 'ALL'))) {
                            $pagePath = '<input type="text" name="edit[' . $inf['cache_id'] . ']" value="' . htmlspecialchars($inf['pagepath']) . '" size="40" />';
                            if ($cmd === 'edit' && $entry != 'ALL') {
                                $pagePath .= $this->saveCancelButtons();
                            }
                        } else {
                            $pagePath = htmlspecialchars($inf['pagepath']);
                        }
                    }

                    $tCells[] = '<td' . ($inf['expire'] ? ' style="font-style: italic; color:#999999;"' : '') . '>' . $pagePath . '</td>';

                    if ($deletedEntry) {
                        $tCells[] = '<td>&nbsp;</td>';
                    } else {
                        $tCells[] = '<td>' .
                            '<a href="' . $this->linkSelf('&cmd=delete&entry=' . $inf['cache_id']) . '">' .
                            $this->getIcon('actions-delete', 'Delete') .
                            '</a>' .
                            '<a href="' . $this->linkSelf('&cmd=edit&entry=' . $inf['cache_id']) . '">' .
                            $this->getIcon('actions-open', 'Edit') .
                            '</a>' .
                            '<a href="' . $this->linkSelf('&pathPrefixSearch=' . rawurlencode($inf['pagepath'])) . '">' .
                            $this->getIcon('actions-document-save', 'Use for search') .
                            '</a>' .
                            '<a href="' . $this->linkSelf('&cmd=copy&entry=' . $inf['cache_id']) . '">' .
                            $this->getIcon('actions-edit-copy', 'Copy entry') .
                            '</a>' .
                            '</td>';
                    }
                    $tCells[] = '<td' . ($inf['expire'] && $inf['expire'] < time() ? ' style="color: red;"' : '') . '>' .
                        ($inf['expire'] ? htmlspecialchars(BackendUtility::dateTimeAge($inf['expire'], -1)) : '') .
                        ($inf['expire'] ?
                            '<a href="' . $this->linkSelf('&cmd=raiseExpire&entry=' . $inf['cache_id']) . '">' .
                            $this->getIcon('actions-move-up', 'Set expire time to 30 days') .
                            '</a>' : '') .
                        '</td>';

                    // Set error msg:
                    $error = '';
                    if (!strcmp($inf['pagepath'], '')) {
                        if ($row['row']['uid'] != $this->pObj->id) {    // Show error of "Empty" only for levels under the root. Yes, we cannot know that the pObj->id is the true root of the site, but at least any SUB page should probably have a path string!
                            $error = $this->getIcon(self::ICON_WARNING) . 'Empty';
                        }
                    } elseif (isset($trackSameUrl[$hash])) {
                        $error = $this->getIcon(self::ICON_WARNING) . 'Already used on page ID ' . $trackSameUrl[$hash];
                    } else {
                        $error = '&nbsp;';
                    }
                    $tCells[] = '<td>' . $error . '</td>';

                    $tCells[] = '<td>' . htmlspecialchars($inf['language_id']) . '</td>';
                    $tCells[] = '<td>' . htmlspecialchars($inf['mpvar']) . '</td>';
                    $tCells[] = '<td>' . htmlspecialchars($inf['rootpage_id']) . '</td>';

                    #$tCells[]='<td nowrap="nowrap">'.htmlspecialchars(\TYPO3\CMS\Backend\Utility\BackendUtility::datetime($inf['expire'])).' / '.htmlspecialchars(\TYPO3\CMS\Backend\Utility\BackendUtility::calcAge($inf['expire']-time())).'</td>';

                    $trackSameUrl[$hash] = $inf['page_id'];

                    // Compile Row:
                    $rowClass = 'bgColor' . ($cc % 2 ? '-20' : '-10');
                    $output .= '
						<tr class="' . $rowClass . '">
							' . implode('
							', $tCells) . '
						</tr>';
                    $cc++;
                }
            }
        }

        // Create header:
        $tCells = [];
        $tCells[] = '<th>Title</th>';
        $tCells[] = '<th>ID</th>';
        $tCells[] = '<th>&nbsp;</th>';
        $tCells[] = '<th>PathSegment | Alias | NavTitle | Title</th>';
        $tCells[] = '<th>Pagepath</th>';
        $tCells[] = '<th>' .
            '<a href="' . $this->linkSelf('&cmd=delete&entry=ALL') . '" onclick="return confirm(\'Are you sure you want to flush all cached page paths?\');">' .
            $this->getIcon('actions-delete') .
            '</a>' .
            '<a href="' . $this->linkSelf('&cmd=edit&entry=ALL') . '">' .
            $this->getIcon('actions-open') .
            '</a>' .
            '</th>';
        $tCells[] = '<th>Expires ' .
            '<a href="' . $this->linkSelf('&cmd=flushExpired') . '">' .
            $this->getIcon('actions-delete', 'Flush all expired') .
            '</a>' .
            '</th>';
        $tCells[] = '<th>Errors</th>';
        $tCells[] = '<th>Lang</th>';
        $tCells[] = '<th>&MP</th>';
        $tCells[] = '<th>RootPage ID</th>';
        $output = '
            <thead>
			<tr>
				' . implode('
				', $tCells) . '
			</tr></thead><tbody>' . $output . '</tbody>';

        // Compile final table and return:
        $output = '
        <br><br>
		<table class="table table-striped table-bordered table-condensed table-hover" id="tx-realurl-pathcacheTable">' . $output . '
		</table>';

        if ($cmd === 'edit' && $entry == 'ALL') {
            $output .= $this->saveCancelButtons();
        }

        return $output;
    }

    /**
     * Fetch path caching information for page.
     *
     * @param    int        Page ID
     * @return    array        Path Cache records
     */
    public function getPathCache($pageId)
    {
        $showLanguage = GeneralUtility::_GP('showLanguage');
        $cmd = GeneralUtility::_GET('cmd');
        $entry = GeneralUtility::_GET('entry');

        $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_pathcache');
        $constraints = [
            $queryBuilder->expr()->eq(
                'page_id',
                $queryBuilder->createNamedParameter((int)$pageId, \PDO::PARAM_INT)
            )
        ];

        if ((string)$showLanguage !== '' && (string)$showLanguage !== '0') {
            $constraints[] = $queryBuilder->expr()->eq(
                'language_id',
                $queryBuilder->createNamedParameter((int)$showLanguage, \PDO::PARAM_INT)
            );
        }

        $rows = $queryBuilder->select('*')
            ->from('tx_realurl_pathcache')
            ->where(
                ...$constraints
            )
            ->addOrderBy('language_id')
            ->addOrderBy('expire')
            ->execute()
            ->fetchAll();

        // Traverse result:
        $output = [];
        foreach ($rows as $row) {

            // Delete entries:
            if ($cmd === 'delete' && (!strcmp($entry, $row['cache_id']) || !strcmp($entry, 'ALL'))) {
                $this->deletePathCacheEntry($row['cache_id']);
                // Raise expire times:
            } elseif ($cmd === 'raiseExpire' && !strcmp($entry, $row['cache_id'])) {
                $this->raiseExpirePathCacheEntry($row);
                $output[] = $row;
            } elseif ($cmd === 'flushExpired' && $row['expire'] && $row['expire'] < time()) {
                $this->deletePathCacheEntry($row['cache_id']);
            } elseif ($cmd === 'copy' && (!strcmp($entry, $row['cache_id']))) {
                $output[] = $this->copyPathCacheEntry($row);
                $output[] = $row;
            } else {    // ... or add:
                $output[] = $row;
            }
        }
        return $output;
    }

    /**
     * Links to the module script and sets necessary parameters (only for path cache display)
     *
     * @param string $parameters Additional GET vars
     * @return string script + query
     */
    public function linkSelf($parameters)
    {
        return htmlspecialchars(BackendUtility::getModuleUrl(
                'web_info',
                array(
                    'id' => $this->pObj->id,
                    'showLanguage' => GeneralUtility::_GP('showLanguage'),
                )
            ) . $parameters);
    }

    /**
     * Create search form
     *
     * @return    string        HTML
     */
    public function renderSearchForm()
    {
        $output = $this->getLanguageSelector();
        $output .= '<div class="form-group">' . $this->getSearchField() . '</div>';
        $output .= $this->getReplaceAndDeleteFields();
        $output .= '<input type="hidden" name="id" value="' . $this->pObj->id . '" />';

        return $output;
    }

    /**
     * Obtains fields for replace/delete.
     *
     * @return string
     */
    private function getReplaceAndDeleteFields()
    {
        $output = '';

        if ($this->searchResultCounter && !GeneralUtility::_POST('_replace') && !GeneralUtility::_POST('_delete')) {
            $output .= '<div class="form-group"><label for="pathPrefixReplace">Replace with:</label> <input class="form-control" type="text" name="pathPrefixReplace" value="' . htmlspecialchars(GeneralUtility::_GP('pathPrefixSearch')) . '" />';
            $output .= '<input class="btn btn-success" type="submit" name="_replace" value="Replace" /> or <input type="submit" class="btn btn-danger" name="_delete" value="Delete" /></div>';
            $output .= '<div><b>' . sprintf('Found: %d result(s).', $this->searchResultCounter) . '</b></div>';
        }
        return $output;
    }

    /**
     * Enter description here ...
     * @param output
     */
    protected function getSearchField()
    {
        $output = '<input class="form-control" type="text" placeholder="' . $this->getLanguageService()->getLL('look_up') . ' ' . $this->getLanguageService()->getLL('search_path') . '" name="pathPrefixSearch" id="pathPrefixSearch" value="' .
            htmlspecialchars(GeneralUtility::_GP('pathPrefixSearch')) . '" />';

        return $output;
    }

    /**
     * Generates language selector.
     *
     * @return string
     */
    protected function getLanguageSelector()
    {
        $languages = $this->getSystemLanguages();

        $options = [];
        $showLanguage = GeneralUtility::_GP('showLanguage');
        foreach ($languages as $languageUid => $language) {
            $selected = (int)$showLanguage === (int)$languageUid ? ' selected="selected"' : '';
            $options[] = '<option value="' . $languageUid . '"' . $selected . '>' .
                htmlspecialchars($language['title']) . '</option>';
        }

        return '<div class="form-group"><label for="showLanguage">' . $this->getLanguageService()->getLL('language') .
            '</label> <select class="form-control" name="showLanguage">' . implode('', $options) . '</select></div>';
    }

    /**
     * Obtains system languages.
     *
     * @return array
     */
    protected function getSystemLanguages()
    {
        $languages = [];
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_language');
        $statement = $queryBuilder->select('uid', 'title')
            ->from('sys_language')
            ->orderBy('sorting')
            ->execute();
        while ($lRow = $statement->fetch()) {
            if ($this->getBackendUserAuthentication()->checkLanguageAccess($lRow['uid'])) {
                $languages[$lRow['uid']] = $lRow;
            }
        }

        $defaultLanguageLabel = $this->getDefaultLanguageName();

        array_unshift($languages, array('uid' => 0, 'title' => $defaultLanguageLabel));
        array_unshift($languages, array('uid' => '', 'title' => $this->getLanguageService()->getLL('all_languages')));

        return $languages;
    }

    /**
     * Obtains the name of the default language.
     *
     * @return string
     */
    protected function getDefaultLanguageName()
    {
        $tsConfig = BackendUtility::getPagesTSconfig($this->pObj->id);
        if (isset($tsConfig['mod.']['SHARED.']['defaultLanguageLabel'])) {
            $label = $tsConfig['mod.']['SHARED.']['defaultLanguageLabel'];
        } else {
            $label = $this->getLanguageService()->getLL('default_language');
        }
        return $label;
    }

    /**
     * Deletes an entry in pathcache table
     *
     * @param    int        Path Cache id (cache_id)
     * @return    void
     */
    public function deletePathCacheEntry($cache_id)
    {
        $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_pathcache');
        $queryBuilder->delete('tx_realurl_pathcache')
            ->where(
                $queryBuilder->expr()->eq(
                    'cache_id',
                    $queryBuilder->createNamedParameter((int)$cache_id, \PDO::PARAM_INT)
                )
            )
            ->execute();
    }

    /**
     * Deletes an entry in pathcache table
     *
     * @param    int        Path Cache id (cache_id)
     * @return    void
     */
    public function raiseExpirePathCacheEntry(&$row)
    {
        $row['expire'] = time() + 30 * 24 * 3600;
        $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_pathcache');
        $queryBuilder->update('tx_realurl_pathcache')
            ->set('expire', $row['expire'])
            ->where(
                $queryBuilder->expr()->eq(
                    'cache_id',
                    $queryBuilder->createNamedParameter((int)$row['cache_id'], \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->gt(
                    'expire',
                    0
                )
            )
            ->execute();
    }

    /**
     * Copies an entry in pathcache table
     *
     * @param    array        Record to copy, passed by reference, will be updated.
     * @return    array        New record.
     */
    public function copyPathCacheEntry(&$oEntry)
    {
        // Select old record:
        $cEntry = $oEntry;
        unset($cEntry['cache_id']);
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $databaseConnectionForPages = $connectionPool->getConnectionForTable('tx_realurl_pathcache');
        $databaseConnectionForPages->insert(
            'tx_realurl_pathcache',
            $cEntry
        );
        $cEntry['cache_id'] = (int)$databaseConnectionForPages->lastInsertId('pages');

        // Update the old record with expire time:
        if (!$oEntry['expire']) {
            $oEntry['expire'] = time() + 30 * 24 * 3600;
            $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_pathcache');
            $queryBuilder->update('tx_realurl_pathcache')
                ->where(
                    $queryBuilder->expr()->eq(
                        'cache_id',
                        $queryBuilder->createNamedParameter((int)$oEntry['cache_id'], \PDO::PARAM_INT)
                    )
                )
                ->set('expire', (int)$oEntry['expire'])
                ->execute();
        }

        return $cEntry;
    }

    /**
     * Changes the "pagepath" value of an entry in the pathcache table
     *
     * @param    int        Path Cache id (cache_id)
     * @param    string        New value for the pagepath
     * @return    void
     */
    public function editPathCacheEntry($cache_id, $value)
    {
        $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_pathcache');
        $queryBuilder->update('tx_realurl_pathcache')
            ->set('pagepath', $value)
            ->where(
                $queryBuilder->expr()->eq(
                    'cache_id',
                    $queryBuilder->createNamedParameter((int)$cache_id, \PDO::PARAM_INT)
                )
            )
            ->execute();

        // Look up the page id so we can clear the encodeCache entries:
        $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_pathcache');
        $pageId = $queryBuilder->select('page_id')
            ->from('tx_realurl_pathcache')
            ->where(
                $queryBuilder->expr()->eq(
                    'cache_id',
                    $queryBuilder->createNamedParameter((int)$cache_id, \PDO::PARAM_INT)
                )
            )
            ->execute()
            ->fetchColumn(0);

        $this->clearDEncodeCache('page_' . $pageId); // Encode cache
        $this->clearDEncodeCache('page_' . $pageId, true);    // Decode cache
    }

    /**
     * Will look for submitted pagepath cache entries to save
     *
     * @return    void
     */
    public function edit_save()
    {
        if (GeneralUtility::_POST('_edit_save')) {
            $editArray = GeneralUtility::_POST('edit');
            foreach ($editArray as $cache_id => $value) {
                $this->editPathCacheEntry($cache_id, trim($value));
            }
        }
    }

    /**
     * Save / Cancel buttons
     *
     * @param    string        Extra code.
     * @return    string        Form elements
     */
    public function saveCancelButtons($extra = '')
    {
        $output = '<input type="submit" class="btn btn-primary" name="_edit_save" value="Save" /> ';
        $output .= '<input type="submit" class="btn btn-default" name="_edit_cancel" value="Cancel" />';
        $output .= $extra;

        return $output;
    }

    /**************************
     *
     * Decode view
     *
     **************************/

    /**
     * Rendering the decode-cache content
     *
     * @param    array        The Page tree data
     * @return    string        HTML for the information table.
     */
    public function decodeView(PageTreeView $tree)
    {

        // Delete entries:
        $cmd = GeneralUtility::_GP('cmd');
        $subcmd = '';
        if ($cmd === 'deleteDC') {
            $subcmd = GeneralUtility::_GP('entry');
            $this->clearDEncodeCache($subcmd, true);
        }

        // Traverse tree:
        $output = '';
        $cc = 0;
        $countDisplayed = 0;
        foreach ($tree->tree as $row) {

            // Select rows:
            $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_urldecodecache');
            $displayRows = $queryBuilder->select('*')
                ->from('tx_realurl_urldecodecache')
                ->where(
                    $queryBuilder->expr()->eq(
                        'page_id',
                        $queryBuilder->createNamedParameter((int)$row['row']['uid'], \PDO::PARAM_INT)
                    )
                )
                ->execute()
                ->fetchAll();

            // Row title:
            $rowTitle = $row['HTML'] . BackendUtility::getRecordTitle('pages', $row['row'], true);

            // Add at least one empty element:
            if (!count($displayRows) || $subcmd === 'displayed') {

                // Add title:
                $tCells = [];
                $tCells[] = '<td nowrap="nowrap">' . ($row['depthData'] ?: '') . $rowTitle . '</td>';

                // Empty row:
                $tCells[] = '<td colspan="6">&nbsp;</td>';

                // Compile Row:
                $output .= '
					<tr>
						' . implode('
						', $tCells) . '
					</tr>';
                $cc++;

                if ($subcmd === 'displayed') {
                    foreach ($displayRows as $c => $inf) {
                        $this->clearDEncodeCache('urlhash_' . $inf['url_hash'], true);
                    }
                }
            } else {
                foreach ($displayRows as $c => $inf) {

                    // Add icon/title and ID:
                    $tCells = [];
                    if (!$c) {
                        $tCells[] = '<td nowrap="nowrap" rowspan="' . count($displayRows) . '">' . $rowTitle . '</td>';
                        $tCells[] = '<td nowrap="nowrap" rowspan="' . count($displayRows) . '">' . $row['row']['uid'] . '</td>';
                        $tCells[] = '<td rowspan="' . count($displayRows) . '">' .
                            '<a href="' . $this->linkSelf('&cmd=deleteDC&entry=page_' . intval($row['row']['uid'])) . '">' .
                            $this->getIcon('actions-delete', 'Delete entries for page') .
                            '</a>' .
                            '</td>';
                    }

                    // Path:
                    $tCells[] = '<td>' . htmlspecialchars($inf['spurl']) . '</td>';

                    // Get vars:
                    $queryValues = unserialize($inf['content']);
                    $queryParams = '?id=' . $queryValues['id'] .
                        (is_array($queryValues['GET_VARS']) ? GeneralUtility::implodeArrayForUrl('',
                            $queryValues['GET_VARS']) : '');
                    $tCells[] = '<td>' . htmlspecialchars($queryParams) . '</td>';

                    // Delete:
                    $tCells[] = '<td>' .
                        '<a href="' . $this->linkSelf('&cmd=deleteDC&entry=urlhash_' . rawurlencode($inf['url_hash'])) . '">' .
                        $this->getIcon('actions-delete', 'Delete entry') .
                        '</a>' .
                        '</td>';

                    // Timestamp:
                    $tCells[] = '<td>' . htmlspecialchars(BackendUtility::datetime($inf['tstamp'])) . ' / ' . htmlspecialchars(BackendUtility::calcAge(time() - $inf['tstamp'])) . '</td>';

                    // Compile Row:
                    $output .= '
						<tr class="bgColor' . ($cc % 2 ? '-20' : '-10') . '">
							' . implode('
							', $tCells) . '
						</tr>';
                    $cc++;
                    $countDisplayed++;
                }
            }
        }

        $count = $this->getQueryBuilderForTable('tx_realurl_urldecodecache')
            ->count('*')
            ->from('tx_realurl_urldecodecache')
            ->execute()
            ->fetchColumn(0);

        // Create header:
        $tCells = [];
        $tCells[] = '<th>Title</th>';
        $tCells[] = '<th>ID</th>';
        $tCells[] = '<th>&nbsp;</th>';
        $tCells[] = '<th>Path</th>';
        $tCells[] = '<th>GET variables</th>';
        $tCells[] = '<th>&nbsp;</th>';
        $tCells[] = '<th>Timestamp</th>';

        $output = '
            <thead>
                <tr>
                    ' . implode('
                    ', $tCells) . '
                </tr>
			</thead><tbody>' . $output . '</tbody>';

        // Compile final table and return:
        $output = '<br/><br/>
		Displayed entries: <b>' . $countDisplayed . '</b> ' .
            '<a href="' . $this->linkSelf('&cmd=deleteDC&entry=displayed') . '">' .
            $this->getIcon('actions-delete', 'Delete displayed entries') .
            '</a>' .
            '<br/>
		Total entries in decode cache: <b>' . $count . '</b> ' .
            '<a href="' . $this->linkSelf('&cmd=deleteDC&entry=all') . '">' .
            $this->getIcon('actions-delete', 'Delete WHOLE decode cache!') .
            '</a>' .
            '
		
        <br><br>
		<table class="table table-striped table-bordered table-condensed table-hover" id="tx-realurl-pathcacheTable">' . $output . '
		</table>';

        return $output;
    }

    /**************************
     *
     * Encode view
     *
     **************************/

    /**
     * Rendering the encode-cache content
     *
     * @param    array        The Page tree data
     * @return    string        HTML for the information table.
     */
    public function encodeView(PageTreeView $tree)
    {

        // Delete entries:
        $cmd = GeneralUtility::_GP('cmd');
        $subcmd = '';
        if ($cmd === 'deleteEC') {
            $subcmd = GeneralUtility::_GP('entry');
            $this->clearDEncodeCache($subcmd);
        }

        // Traverse tree:
        $cc = 0;
        $countDisplayed = 0;
        $output = '';
        $duplicates = [];

        foreach ($tree->tree as $row) {

            // Select rows:
            $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_urlencodecache');
            $displayRows = $queryBuilder->select('*')
                ->from('tx_realurl_urlencodecache')
                ->where(
                    $queryBuilder->expr()->eq(
                        'page_id',
                        $queryBuilder->createNamedParameter((int)$row['row']['uid'], \PDO::PARAM_INT)
                    )
                )
                ->orderBy('content')
                ->execute()
                ->fetchAll();

            // Row title:
            $rowTitle = $row['HTML'] . BackendUtility::getRecordTitle('pages', $row['row'], true);

            // Add at least one empty element:
            if (!count($displayRows) || $subcmd === 'displayed') {

                // Add title:
                $tCells = [];
                $tCells[] = '<td nowrap="nowrap">' . ($row['depthData'] ?: '') . $rowTitle . '</td>';
                $tCells[] = '<td nowrap="nowrap">&nbsp;</td>';

                // Empty row:
                $tCells[] = '<td colspan="7">&nbsp;</td>';

                // Compile Row:
                $output .= '
					<tr class="bgColor' . ($cc % 2 ? '-20' : '-10') . '">
						' . implode('
						', $tCells) . '
					</tr>';
                $cc++;

                if ($subcmd === 'displayed') {
                    foreach ($displayRows as $c => $inf) {
                        $this->clearDEncodeCache('urlhash_' . $inf['url_hash']);
                    }
                }
            } else {
                foreach ($displayRows as $c => $inf) {
                    // Add icon/title and ID:
                    $tCells = [];
                    if (!$c) {
                        $tCells[] = '<td nowrap="nowrap" rowspan="' . count($displayRows) . '">' . $rowTitle . '</td>';
                        $tCells[] = '<td nowrap="nowrap" rowspan="' . count($displayRows) . '">' . $row['row']['uid'] . '</td>';
                        $tCells[] = '<td rowspan="' . count($displayRows) . '">' .
                            '<a href="' . $this->linkSelf('&cmd=deleteEC&entry=page_' . intval($row['row']['uid'])) . '">' .
                            $this->getIcon('actions-delete', 'Delete entries for page') .
                            '</a>' .
                            '</td>';
                    }

                    // Get vars:
                    $tCells[] = '<td>' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($inf['origparams'],
                            100)) . '</td>';

                    // Internal Extras:
                    $tCells[] = '<td>' . ($inf['internalExtras'] ? GeneralUtility::arrayToLogString(unserialize($inf['internalExtras'])) : '&nbsp;') . '</td>';

                    // Path:
                    $tCells[] = '<td>' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($inf['content'], 100)) . '</td>';

                    // Delete:
                    $tCells[] = '<td>' .
                        '<a href="' . $this->linkSelf('&cmd=deleteEC&entry=urlhash_' . rawurlencode($inf['url_hash'])) . '">' .
                        $this->getIcon('actions-delete', 'Delete entry!') .
                        '</a>' .
                        '</td>';

                    // Error:
                    $eMsg = ($duplicates[$inf['content']] && $duplicates[$inf['content']] !== $row['row']['uid'] ? $this->getIcon(self::ICON_WARNING) . 'Already used on page ID ' . $duplicates[$inf['content']] . '<br/>' : '');
                    $tCells[] = '<td>' . $eMsg . '</td>';

                    // Timestamp:
                    $tCells[] = '<td>' . htmlspecialchars(BackendUtility::datetime($inf['tstamp'])) . ' / ' . htmlspecialchars(BackendUtility::calcAge(time() - $inf['tstamp'])) . '</td>';

                    // Compile Row:
                    $output .= '
						<tr>
							' . implode('
							', $tCells) . '
						</tr>';
                    $cc++;

                    $countDisplayed++;

                    if (!isset($duplicates[$inf['content']])) {
                        $duplicates[$inf['content']] = $row['row']['uid'];
                    }
                }
            }
        }

        $count = $this->getQueryBuilderForTable('tx_realurl_urlencodecache')
            ->count('*')
            ->from('tx_realurl_urlencodecache')
            ->execute()
            ->fetchColumn(0);

        // Create header:
        $tCells = [];
        $tCells[] = '<th>Title:</th>';
        $tCells[] = '<th>ID:</th>';
        $tCells[] = '<th>&nbsp;</th>';
        $tCells[] = '<th>Host | GET variables:</th>';
        $tCells[] = '<th>Internal Extras:</th>';
        $tCells[] = '<th>Path:</th>';
        $tCells[] = '<th>&nbsp;</th>';
        $tCells[] = '<th>Errors:</th>';
        $tCells[] = '<th>Timestamp:</th>';

        $output = '
<thead>
			<tr>
				' . implode('
				', $tCells) . '
			</tr>
</thead><tbody>' . $output . '</tbody>';

        // Compile final table and return:
        $output = '

		<br/>
		<br/>
		Displayed entries: <b>' . $countDisplayed . '</b> ' .
            '<a href="' . $this->linkSelf('&cmd=deleteEC&entry=displayed') . '">' .
            $this->getIcon('actions-delete', 'Delete displayed entries') .
            '</a>' .
            '<br/>
		Total entries in encode cache: <b>' . $count . '</b> ' .
            '<a href="' . $this->linkSelf('&cmd=deleteEC&entry=all') . '">' .
            $this->getIcon('actions-delete', 'Delete WHOLE encode cache!') .
            '</a>' .
            '<br/>
		<table class="table table-striped table-bordered table-condensed table-hover" id="tx-realurl-pathcacheTable">' . $output . '
		</table>';

        return $output;
    }

    /**
     *
     */
    public function clearDEncodeCache($cmd, $decodeCache = false)
    {
        $table = $decodeCache ? 'tx_realurl_urldecodecache' : 'tx_realurl_urlencodecache';
        list($keyword, $id) = explode('_', $cmd);

        switch ((string)$keyword) {
            case 'all':
                $queryBuilder = $this->getQueryBuilderForTable($table);
                $queryBuilder->delete($table)->execute();
                break;
            case 'page':
                $queryBuilder = $this->getQueryBuilderForTable($table);
                $queryBuilder->delete($table)
                    ->where(
                        $queryBuilder->expr()->eq(
                            'page_id',
                            $queryBuilder->createNamedParameter((int)$id, \PDO::PARAM_INT)
                        )
                    )
                    ->execute();
                break;
            case 'urlhash':
                $queryBuilder = $this->getQueryBuilderForTable($table);
                $queryBuilder->delete($table)
                    ->where(
                        $queryBuilder->expr()->eq(
                            'url_hash',
                            $queryBuilder->createNamedParameter($id, \PDO::PARAM_STR)
                        )
                    )
                    ->execute();
                break;
            default:
                break;
        }
    }

    /*****************************
     *
     * Unique Alias
     *
     *****************************/

    /**
     * Shows the mapping between aliases and unique IDs of arbitrary tables
     *
     * @return    string        HTML
     */
    public function uniqueAlias()
    {
        $tableName = GeneralUtility::_GP('table');
        $cmd = GeneralUtility::_GET('cmd');
        $entry = GeneralUtility::_GET('entry');
        $search = GeneralUtility::_POST('search');

        // Select rows:
        $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_uniqalias');
        $res = $queryBuilder->select('tablename')
            ->addSelectLiteral(
                $queryBuilder->expr()->count('tablename', 'number_of_rows')
            )
            ->from('tx_realurl_uniqalias')
            ->orderBy('tablename')
            ->groupBy('tablename')
            ->execute();

        $overviewRows = [];
        while ($row = $res->fetch()) {
            if (!is_array($overviewRows[$row['tablename']])) {
                $overviewRows[$row['tablename']] = $row;
            }
        }

        if ($tableName && isset($overviewRows[$tableName])) {    // Show listing of single table:

            // Some Commands:
            if ($cmd === 'delete') {
                $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_uniqalias');
                if ($entry === 'ALL') {
                    $queryBuilder->delete('tx_realurl_uniqalias')
                        ->where(
                            $queryBuilder->expr()->eq(
                                'tablename',
                                $queryBuilder->createNamedParameter($tableName, \PDO::PARAM_STR)
                            )
                        );
                } else {
                    $queryBuilder->delete('tx_realurl_uniqalias')
                        ->where(
                            $queryBuilder->expr()->eq(
                                'tablename',
                                $queryBuilder->createNamedParameter($tableName, \PDO::PARAM_STR)
                            ),
                            $queryBuilder->expr()->eq(
                                'uid',
                                $queryBuilder->createNamedParameter((int)$entry, \PDO::PARAM_INT)
                            )
                        );
                }
                $queryBuilder->execute();
            }
            if ($cmd === 'flushExpired') {
                $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_uniqalias');
                $queryBuilder->delete('tx_realurl_uniqalias')
                    ->where(
                        $queryBuilder->expr()->eq(
                            'tablename',
                            $queryBuilder->createNamedParameter($tableName, \PDO::PARAM_STR)
                        ),
                        $queryBuilder->expr()->gt(
                            'expire',
                            0
                        ),
                        $queryBuilder->expr()->lt(
                            'expire',
                            time()
                        )
                    )
                    ->execute();
            }

            // Select rows:
            $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_uniqalias');
            $constraints = [
                $queryBuilder->expr()->eq(
                    'tablename',
                    $queryBuilder->createNamedParameter($tableName, \PDO::PARAM_STR)
                )
            ];

            if (!empty($search)) {
                $constraints[] = $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq(
                        'value_id',
                        $queryBuilder->createNamedParameter($search, \PDO::PARAM_STR)
                    ),
                    $queryBuilder->expr()->like(
                        'value_alias',
                        $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($search) . '%', \PDO::PARAM_STR)
                    )
                );
            }

            $tableContent = $queryBuilder->select('*')
                ->from('tx_realurl_uniqalias')
                ->where(...$constraints)
                ->addOrderBy('value_id')
                ->addOrderBy('lang')
                ->addOrderBy('expire')
                ->execute()
                ->fetchAll();

            $cc = 0;
            $field_id = $field_alias = $output = '';
            $duplicates = [];
            foreach ($tableContent as $aliasRecord) {
                // Add data:
                $tCells = [];
                $tCells[] = '<td>' . htmlspecialchars($aliasRecord['value_id']) . '</td>';

                if ((string)$cmd === 'edit' && ($entry === 'ALL' || !strcmp($entry, $aliasRecord['uid']))) {
                    $tCells[] = '<td>' .
                        '<input type="text" name="edit[' . $aliasRecord['uid'] . ']" value="' . htmlspecialchars($aliasRecord['value_alias']) . '" />' .
                        ($entry !== 'ALL' ? $this->saveCancelButtons('') : '') .
                        '</td>';
                } else {
                    $tCells[] = '<td' . ($aliasRecord['expire'] ? ' style="font-style: italic; color:#999999;"' : '') . '>' . htmlspecialchars($aliasRecord['value_alias']) . '</td>';
                }

                $tCells[] = '<td>' . htmlspecialchars($aliasRecord['lang']) . '</td>';
                $tCells[] = '<td' . ($aliasRecord['expire'] && $aliasRecord['expire'] < time() ? ' style="color: red;"' : '') . '>' . htmlspecialchars(BackendUtility::dateTimeAge($aliasRecord['expire'])) . '</td>';

                $tCells[] = '<td>' .
                    // Edit link:
                    '<a href="' . $this->linkSelf('&table=' . rawurlencode($tableName) . '&cmd=edit&entry=' . $aliasRecord['uid']) . '">' .
                    $this->getIcon('actions-open') .
                    '</a>' .
                    // Delete link:
                    '<a href="' . $this->linkSelf('&table=' . rawurlencode($tableName) . '&cmd=delete&entry=' . $aliasRecord['uid']) . '">' .
                    $this->getIcon('actions-delete') .
                    '</a>' .
                    '</td>';

                $keyForDuplicates = $aliasRecord['value_alias'] . ':::' . $aliasRecord['lang'];
                $tCells[] = '<td>' .
                    (isset($duplicates[$keyForDuplicates]) ? $this->getIcon(self::ICON_WARNING) . 'Already used by ID ' . $duplicates[$aliasRecord['value_alias']] : '&nbsp;') .
                    '</td>';

                $field_id = $aliasRecord['field_id'];
                $field_alias = $aliasRecord['field_alias'];

                // Compile Row:
                $output .= '
					<tr class="bgColor' . ($cc % 2 ? '-20' : '-10') . '">
						' . implode('
						', $tCells) . '
					</tr>';
                $cc++;

                $duplicates[$keyForDuplicates] = $aliasRecord['value_id'];
            }

            // Create header:
            $tCells = [];
            $tCells[] = '<td>ID (Field: ' . $field_id . ')</td>';
            $tCells[] = '<td>Alias (Field: ' . $field_alias . '):</td>';
            $tCells[] = '<td>Lang:</td>';
            $tCells[] = '<td>Expire:' .
                (!$search ? '<a href="' . $this->linkSelf('&table=' . rawurlencode($tableName) . '&cmd=flushExpired') . '">' .
                    $this->getIcon('actions-delete', 'Flush expired') .
                    '</a>' : '') .
                '</td>';
            $tCells[] = '<td>' .
                (!$search ? '<a href="' . $this->linkSelf('&table=' . rawurlencode($tableName) . '&cmd=edit&entry=ALL') . '">' .
                    $this->getIcon('actions-open', 'Edit all') .
                    '</a>' .
                    '<a href="' . $this->linkSelf('&table=' . rawurlencode($tableName) . '&cmd=delete&entry=ALL') . '" onclick="return confirm(\'Delete all?\');">' .
                    $this->getIcon('actions-delete', 'Delete all') .
                    '</a>' : '') .
                '</td>';
            $tCells[] = '<td>Error:</td>';

            $output = '
				<tr class="bgColor5 tableheader">
					' . implode('
					', $tCells) . '
				</tr>' . $output;
            // Compile final table and return:
            $output = '

			<br/>
			Table: <b>' . htmlspecialchars($tableName) . '</b><br/>
			Aliases: <b>' . htmlspecialchars(count($tableContent)) . '</b><br/>
			Search: <input type="text" name="search" value="' . htmlspecialchars($search) . '" /><input class="btn btn-primary" type="submit" name="_" value="Search" />
			<input type="hidden" name="table" value="' . htmlspecialchars($tableName) . '" />
			<input type="hidden" name="id" value="' . htmlspecialchars($this->pObj->id) . '" />
			<br/><br/>
			<table id="tx-realurl-pathcacheTable" class="table table-striped table-bordered table-condensed table-hover">' . $output . '
			</table>';

            if ($entry === 'ALL') {
                $output .= $this->saveCancelButtons('<input type="hidden" name="table" value="' . htmlspecialchars($tableName) . '" /><input type="hidden" name="id" value="' . htmlspecialchars($this->pObj->id) . '" />');
            }
        } else {    // Create overview:
            $cc = 0;
            $output = '';
            if (count($overviewRows)) {
                foreach ($overviewRows as $aliasRecord) {

                    // Add data:
                    $tCells = [];
                    $tCells[] = '<td><a href="' . $this->linkSelf('&table=' . rawurlencode($aliasRecord['tablename'])) . '">' . $aliasRecord['tablename'] . '</a></td>';
                    $tCells[] = '<td>' . $aliasRecord['number_of_rows'] . '</td>';

                    // Compile Row:
                    $output .= '
						<tr>
							' . implode('
							', $tCells) . '
						</tr>';
                    $cc++;
                }

                $output = '
					<thead>
					<tr>
                        <th>Table</th>
                        <th>Alias</th>
					</tr>
					</thead><tbody>' . $output . '</tbody>';

                // Compile final table and return:
                $output = '
                <br><br>
				<table class="table table-striped table-bordered table-condensed table-hover" id="tx-realurl-pathcacheTable">' . $output . '
				</table>';
            }
        }

        return $output;
    }

    /**
     * Changes the "alias" value of an entry in the unique alias table
     *
     * @param    int        UID of unique alias
     * @param    string        New value for the alias
     * @return    void
     */
    public function editUniqAliasEntry($cache_id, $value)
    {
        $queryBuilder = $this->getQueryBuilderForTable('tx_realurl_uniqalias');
        $queryBuilder->update('tx_realurl_uniqalias')
            ->set('value_alias', $value)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter((int)$cache_id, \PDO::PARAM_INT)
                )
            )
            ->execute();
    }

    /**
     * Will look for submitted unique alias entries to save
     *
     * @return    void
     */
    public function edit_save_uniqAlias()
    {
        if (GeneralUtility::_POST('_edit_save')) {
            $editArray = GeneralUtility::_POST('edit');
            foreach ($editArray as $cache_id => $value) {
                $this->editUniqAliasEntry($cache_id, trim($value));
            }
        }
    }

    /*****************************
     *
     * Configuration view:
     *
     *****************************/

    /**
     * Shows configuration of the extension.
     *
     * @return    string        HTML
     */
    public function configView()
    {
        // Initialize array browser:
        $arrayBrowser = GeneralUtility::makeInstance('TYPO3\\CMS\\Lowlevel\\Utility\\ArrayBrowser');
        /** @var \TYPO3\CMS\Lowlevel\Utility\ArrayBrowser $arrayBrowser */
        $arrayBrowser->expAll = true;
        $arrayBrowser->fixedLgd = false;
        $arrayBrowser->dontLinkVar = true;

        // Create the display code:
        $theVar = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['realurl'];
        $tree = $arrayBrowser->tree($theVar, '', '');

        $tree = '<hr/>
		<b>$GLOBALS[\'TYPO3_CONF_VARS\'][\'EXTCONF\'][\'realurl\']</b>
		<br/>
		<span class="nobr">' . $tree . '</span>';

        return $tree;
    }

    /*****************************
     *
     * Log view:
     *
     *****************************/

    /**
     * View error log
     *
     * @return    string        HTML
     */
    public function logView()
    {
        $cmd = GeneralUtility::_GP('cmd');
        if ($cmd === 'deleteAll') {
            $this->getQueryBuilderForTable('tx_realurl_errorlog')
                ->delete('tx_realurl_errorlog')
                ->execute();
        }

        $list = $this->getQueryBuilderForTable('tx_realurl_errorlog')
            ->select('*')
            ->from('tx_realurl_errorlog')
            ->addOrderBy('counter', 'DESC')
            ->addOrderBy('tstamp', 'DESC')
            ->setMaxResults(250)
            ->execute()
            ->fetchAll();

        if (is_array($list)) {
            $output = '';
            $cc = 0;
            $hostCacheName = [];
            foreach ($list as $rec) {
                $host = '';
                if ((int)$rec['rootpage_id'] !== 0) {
                    if (isset($hostCacheName[$rec['rootpage_id']])) {
                        $host = $hostCacheName[$rec['rootpage_id']];
                    } else {
                        $hostCacheName[$rec['rootpage_id']] = $host = $this->getHostName($rec['rootpage_id']);
                    }
                }

                // Add data:
                $tCells = [];
                $tCells[] = '<td>' . $rec['counter'] . '</td>';
                $tCells[] = '<td>' . BackendUtility::dateTimeAge($rec['tstamp']) . '</td>';
                $tCells[] = '<td><a href="' . htmlspecialchars($host . '/' . $rec['url']) . '" target="_blank">' . ($host ? $host . '/' : '') . htmlspecialchars($rec['url']) . '</a></td>';
                $tCells[] = '<td>' . htmlspecialchars($rec['error']) . '</td>';
                $tCells[] = '<td>' .
                    ($rec['last_referer'] ? '<a href="' . htmlspecialchars($rec['last_referer']) . '" target="_blank">' . htmlspecialchars($rec['last_referer']) . '</a>' : '&nbsp;') .
                    '</td>';
                $tCells[] = '<td>' . BackendUtility::datetime($rec['cr_date']) . '</td>';

                // Compile Row:
                $output .= '<tr class="bgColor' . ($cc % 2 ? '-20' : '-10') . '">' . implode('', $tCells) . '</tr>';
                $cc++;
            }
            // Create header:
            $tCells = [];
            $tCells[] = '<td>Count</td>';
            $tCells[] = '<td>Last time</td>';
            $tCells[] = '<td>URL</td>';
            $tCells[] = '<td>Error</td>';
            $tCells[] = '<td>Last Referer</td>';
            $tCells[] = '<td>First time</td>';

            $output = '
				<tr class="bgColor5 tableheader">
					' . implode('
					', $tCells) . '
				</tr>' . $output;

            // Compile final table and return:
            $output = '<br/>
				<a href="' . $this->linkSelf('&cmd=deleteAll') . '">' .
                $this->getIcon('actions-delete', 'Delete All') .
                ' Flush log</a>
            <br><br>
			<table class="table table-striped table-bordered table-condensed table-hover" id="tx-realurl-pathcacheTable">' . $output . '
			</table>';

            return $output;
        }
    }

    public function getHostName($rootpage_id)
    {
        foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['realurl'] as $host => $config) {
            if ($host != '_DEFAULT') {
                $hostName = $host;
                while ($config !== false && !is_array($config)) {
                    $host = $config;
                    $config = (isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['realurl'][$host]) ? $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['realurl'][$host] : false);
                }
                if (is_array($config) && isset($config['pagePath']) && isset($config['pagePath']['rootpage_id']) && $config['pagePath']['rootpage_id'] == $rootpage_id) {
                    return 'http://' . $hostName;
                }
            }
        }
        return '';
    }

    /**
     * Redirect view
     *
     * @return    string        HTML
     * @deprecated
     */
    public function redirectView()
    {
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplatePathAndFilename(
            'EXT:realurl/Resources/Private/Templates/RedirectView.html'
        );
        return $view->render();
    }

    /**
     * Obtains amount of results per page for the given view.
     *
     * @param string $view
     * @return int
     */
    protected function getResultsPerPage($view)
    {
        $tsConfig = BackendUtility::getModTSconfig($this->pObj->id,
            'tx_realurl.' . $view . '.pagebrowser.resultsPerPage');
        $resultsPerPage = $tsConfig['value'];
        return \TYPO3\CMS\Core\Utility\MathUtility::canBeInterpretedAsInteger($resultsPerPage) ? intval($resultsPerPage) : \Tx\Realurl\ViewHelpers\PageBrowserViewHelper::RESULTS_PER_PAGE_DEFAULT;
    }

    /**
     * @param string $icon
     * @param string $title
     * @return string
     */
    protected function getIcon($icon, $title = '')
    {
        $icon = $this->iconFactory->getIcon($icon, \TYPO3\CMS\Core\Imaging\Icon::SIZE_SMALL)->render();
        if (!empty($title)) {
            $icon = '<span title="' . htmlspecialchars($title) . '">' . $icon . '</span>';
        }
        return $icon;
    }

    /**
     * @param string $table
     * @return QueryBuilder
     */
    protected function getQueryBuilderForTable(string $table): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
    }
}
