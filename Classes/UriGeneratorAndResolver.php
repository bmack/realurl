<?php
namespace Tx\Realurl;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2004 Martin Poelstra (martin@beryllium.net)
 *  (c) 2005-2010 Dmitry Dulepov (dmitry@typo3.org)
 *  All rights reserved
 *
 *  This script is part of the Typo3 project. The Typo3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
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

use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendGroupRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class for translating page ids to/from path strings (Speaking URLs)
 *
 * @author Martin Poelstra <martin@beryllium.net>
 * @author Kasper Skaarhoj <kasper@typo3.com>
 * @author Dmitry Dulepov <dmitry@typo3.org>
 */
class UriGeneratorAndResolver implements SingletonInterface
{

    /**
     * PageRepository object for finding rootline on the fly
     *
     * @var	\TYPO3\CMS\Frontend\Page\PageRepository
     */
    protected $sysPage;

    /**
     * Reference to parent object
     *
     * @var \Tx\Realurl\Hooks\UrlRewritingHook
     */
    protected $pObj;

    /**
     * Class configuration
     *
     * @var array $conf
     */
    protected $conf;

    /**
     * Configuration for the current domain
     *
     * @var array
     */
    protected $extConf;

    /**
     * Main function, called for both encoding and deconding of URLs.
     * Based on the "mode" key in the $params array it branches out to either decode or encode functions.
     *
     * @param array $params Parameters passed from parent object, "tx_realurl". Some values are passed by reference! (paramKeyValues, pathParts and pObj)
     * @param \Tx\Realurl\Hooks\UrlRewritingHook $parent Copy of parent object. Not used.
     * @return mixed Depends on branching.
     */
    public function main(array $params, \Tx\Realurl\Hooks\UrlRewritingHook $parent)
    {
        // Setting internal variables
        $this->pObj = $parent;
        $this->conf = $params['conf'];
        $this->extConf = $this->pObj->getConfiguration();

        // Branching out based on type
        $result = false;
        switch ((string)$params['mode']) {
            case 'encode':
                $this->IDtoPagePath($params['paramKeyValues'], $params['pathParts']);
                $result = null;
                break;
            case 'decode':
                $result = $this->pagePathtoID($params['pathParts']);
                break;
        }
        return $result;
    }

    /*******************************
     *
     * "path" ID-to-URL methods
     *
     ******************************/

    /**
     * Retrieve the page path for the given page-id.
     * If the page is a shortcut to another page, it returns the page path to the shortcutted page.
     * MP get variables are also encoded with the page id.
     *
     * @param array $paramKeyValues GETvar parameters containing eg. "id" key with the page id/alias (passed by reference)
     * @param array $pathParts Path parts array (passed by reference)
     * @return void
     * @see encodeSpURL_pathFromId()
     */
    protected function IDtoPagePath(array &$paramKeyValues, &$pathParts)
    {
        $pageId = $paramKeyValues['id'];
        unset($paramKeyValues['id']);

        $mpvar = (string)$paramKeyValues['MP'];

        unset($paramKeyValues['MP']);

        // Convert a page-alias to a page-id if needed
        $pageId = $this->resolveAlias($pageId);
        $pageId = $this->resolveShortcuts($pageId, $mpvar);
        if ($pageId) {
            // Set error if applicable.
            if ($this->isExcludedPage($pageId)) {
                $this->pObj->setEncodeError();
            } else {
                $lang = $this->getLanguageVar($paramKeyValues);
                $cachedPagePath = $this->getPagePathFromCache($pageId, $lang, $mpvar);

                if ($cachedPagePath !== false) {
                    $pagePath = $cachedPagePath;
                } else {
                    $pagePath = $this->createPagePathAndUpdateURLCache($pageId,
                        $mpvar, $lang, $cachedPagePath);
                }

                // Set error if applicable.
                if ($pagePath === '__ERROR') {
                    $this->pObj->setEncodeError();
                } else {
                    $this->mergeWithPathParts($pathParts, $pagePath);
                }
            }
        }
    }

    /**
     * If page id is not numeric, try to resolve it from alias.
     *
     * @param int|string $pageId
     * @return mixed
     */
    private function resolveAlias($pageId)
    {
        if (!\TYPO3\CMS\Core\Utility\MathUtility::canBeInterpretedAsInteger($pageId)) {
            $pageId = $GLOBALS['TSFE']->sys_page->getPageIdFromAlias($pageId);
        }
        return $pageId;
    }

    /**
     * Checks if the page should be excluded from processing.
     *
     * @param int $pageId
     * @return bool
     */
    protected function isExcludedPage($pageId)
    {
        return $this->conf['excludePageIds'] && GeneralUtility::inList($this->conf['excludePageIds'], $pageId);
    }

    /**
     * Merges the path with existing path parts and creates an array of path
     * segments.
     *
     * @param array $pathParts
     * @param string $pagePath
     * @return void
     */
    protected function mergeWithPathParts(array &$pathParts, $pagePath)
    {
        if (strlen($pagePath)) {
            $pagePathParts = explode('/', $pagePath);
            $pathParts = array_merge($pathParts, $pagePathParts);
        }
    }

    /**
     * Resolves shortcuts if necessary and returns the final destination page id.
     *
     * @param int $pageId
     * @param string $mpvar
     * @return mixed false if not found or int
     */
    protected function resolveShortcuts($pageId, &$mpvar)
    {
        $disableGroupAccessCheck = true;
        $loopCount = 20; // Max 20 shortcuts, to prevent an endless loop
        while ($pageId > 0 && $loopCount > 0) {
            $loopCount--;

            $page = $GLOBALS['TSFE']->sys_page->getPage($pageId, $disableGroupAccessCheck);
            if (!$page) {
                $pageId = false;
                break;
            }

            if (!$this->conf['dontResolveShortcuts'] && $page['doktype'] == 4) {
                // Shortcut
                $pageId = $this->resolveShortcut($page, $disableGroupAccessCheck, array(), $mpvar);
            } else {
                $pageId = $page['uid'];
                break;
            }
            $disableGroupAccessCheck = ($GLOBALS['TSFE']->config['config']['typolinkLinkAccessRestrictedPages'] ? true : false);
        }
        return $pageId;
    }

    /**
     * Retireves page path from cache.
     *
     * @param int $pageid
     * @param int $lang
     * @param string $mpvar
     * @return mixed Page path (string) or false if not found
     */
    private function getPagePathFromCache($pageid, $lang, $mpvar)
    {
        $result = false;
        if (!$this->conf['disablePathCache']) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('tx_realurl_pathcache');
            $cachedPagePath = $queryBuilder
                ->select('pagepath')
                ->from('tx_realurl_pathcache')
                ->where(
                    $queryBuilder->expr()->eq('page_id', $queryBuilder->createNamedParameter($pageid, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('language_id', $queryBuilder->createNamedParameter($lang, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('rootpage_id', $queryBuilder->createNamedParameter($this->conf['rootpage_id'], \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('mpvar', $queryBuilder->createNamedParameter($mpvar)),
                    $queryBuilder->expr()->eq('expire', 0)
                )
                ->setMaxResults(1)
                ->execute()
                ->fetch();
            if (is_array($cachedPagePath)) {
                $result = $cachedPagePath['pagepath'];
            }
        }
        return $result;
    }

    /**
     * Creates the path and inserts into the path cache (if enabled).
     *
     * @param int $id Page id
     * @param string $mpvar MP variable string
     * @param int $lang Language uid
     * @param string $cachedPagePath If set, then a new entry will be inserted ONLY if it is different from $cachedPagePath
     * @return string The page path
     */
    protected function createPagePathAndUpdateURLCache($id, $mpvar, $lang, $cachedPagePath = '')
    {
        $pagePathRec = $this->getPagePathRec($id, $mpvar, $lang);
        if (!$pagePathRec) {
            return '__ERROR';
        }

        $this->updateURLCache($id, $cachedPagePath, $pagePathRec['pagepath'],
            $pagePathRec['langID'], $pagePathRec['rootpage_id'], $mpvar);

        return $pagePathRec['pagepath'];
    }

    /**
     * Adds a new entry to the path cache.
     *
     * @param int $pageId
     * @param int $cachedPagePath
     * @param int $pagePath
     * @param int $langId
     * @param int $rootPageId
     * @param string $mpvar
     * @return void
     */
    private function updateURLCache($pageId, $cachedPagePath, $pagePath, $langId, $rootPageId, $mpvar)
    {
        $canCachePaths = !$this->conf['disablePathCache'] && !$this->pObj->isBEUserLoggedIn();
        $newPathDiffers = ((string)$pagePath !== (string)$cachedPagePath);
        if ($canCachePaths && $newPathDiffers) {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tx_realurl_pathcache');

            $connection->beginTransaction();

            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder->where(
                $queryBuilder->expr()->eq('page_id', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('language_id', $queryBuilder->createNamedParameter($langId, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('rootpage_id', $queryBuilder->createNamedParameter($rootPageId, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('mpvar', $queryBuilder->createNamedParameter($mpvar))
            );
            $this->removeExpiredPathCacheEntries($connection);
            $this->setExpirationOnOldPathCacheEntries(clone $queryBuilder, $pagePath);
            $this->addNewPagePathEntry(clone $queryBuilder, $pagePath, $pageId, $mpvar, $langId, $rootPageId);

            $connection->commit();
        }
    }

    /**
     * Obtains a page path record.
     *
     * @param int $id
     * @param string $mpvar
     * @param int $lang
     * @return mixed array(pagepath,langID,rootpage_id) if successful, false otherwise
     */
    protected function getPagePathRec($id, $mpvar, $lang)
    {
        static $IDtoPagePathCache = array();

        $cacheKey = $id . '.' . $mpvar . '.' . $lang;
        if (isset($IDtoPagePathCache[$cacheKey])) {
            $pagePathRec = $IDtoPagePathCache[$cacheKey];
        } else {
            $pagePathRec = $this->IDtoPagePathThroughOverride($id, $mpvar, $lang);
            if (!$pagePathRec) {
                // Build the new page path, in the correct language
                $pagePathRec = $this->IDtoPagePathSegments($id, $mpvar, $lang);
            }
            $IDtoPagePathCache[$cacheKey] = $pagePathRec;
        }

        return $pagePathRec;
    }

    /**
     * Checks if the page has a path to override.
     *
     * @param int $id
     * @param string $mpvar
     * @param int $lang
     * @return array
     */
    protected function IDtoPagePathThroughOverride($id, /** @noinspection PhpUnusedParameterInspection */ $mpvar, $lang)
    {
        $result = false;
        $page = $this->getPage($id, $lang);
        if ($page['tx_realurl_pathoverride']) {
            if ($page['tx_realurl_pathsegment']) {
                $result = array(
                    'pagepath' => trim($page['tx_realurl_pathsegment'], '/'),
                    'langID' => intval($lang),
                    // TODO Might be better to fetch root line here to process mount
                    // points and inner subdomains correctly.
                    'rootpage_id' => intval($this->conf['rootpage_id'])
                );
            } else {
                $message = sprintf('Path override is set for page=%d (language=%d) but no segment defined!',
                    $id, $lang);
                GeneralUtility::sysLog($message, 'realurl', 3);
                $this->pObj->devLog($message, false, 2);
            }
        }
        return $result;
    }

    /**
     * Obtains a page and its translation (if necessary). The reason to use this
     * function instead of $GLOBALS['TSFE']->sys_page->getPage() is that
     * $GLOBALS['TSFE']->sys_page->getPage() always applies a language overlay
     * (even if we have a different language id).
     *
     * @param int $pageId
     * @param int $languageId
     * @return mixed Page row or false if not found
     */
    protected function getPage($pageId, $languageId)
    {
        // Note: we do not use $GLOBALS['TSFE']->sys_page->where_groupAccess here
        // because we will not come here unless typolinkLinkAccessRestrictedPages
        // was active in 'config' or 'typolink'
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeByType(FrontendGroupRestriction::class);
        $row = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('sys_language_uid', 0),
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT))
            )
            ->execute()
            ->fetch();
        if (is_array($row) && $languageId > 0) {
            $row = $GLOBALS['TSFE']->sys_page->getPageOverlay($row, $languageId);
        }
        return $row;
    }

    /**
     * Adds a new entry to the path cache or revitalizes existing ones
     *
     * @param QueryBuilder $queryBuilder
     * @param string $currentPagePath
     * @param int $pageId
     * @param string $mpvar
     * @param int $langId
     * @param int $rootPageId
     * @return void
     */
    protected function addNewPagePathEntry(QueryBuilder $queryBuilder, $currentPagePath, $pageId, $mpvar, $langId, $rootPageId)
    {
        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq('pagepath', $queryBuilder->createNamedParameter($currentPagePath))
        );
        $revitalizationQueryBuilder = clone $queryBuilder;

        $revitalizationCount = $revitalizationQueryBuilder->count('*')
            ->from('tx_realurl_pathcache')
            ->andWhere(
                $revitalizationQueryBuilder->expr()->neq('expire', 0)
            )
            ->execute()
            ->fetchColumn();
        if ($revitalizationCount > 0) {
            $queryBuilder->update('tx_realurl_pathcache', ['expire' => 0]);
        } else {
            $queryBuilderWithoutExpiration = clone $queryBuilder;
            $createCount = $queryBuilderWithoutExpiration->count('*')
                ->from('tx_realurl_pathcache')
                ->andWhere(
                    $queryBuilderWithoutExpiration->expr()->eq('expire', 0)
                )
                ->execute()
                ->fetchColumn();
            if ($createCount === 0) {
                $insertArray = array(
                    'page_id' => $pageId,
                    'language_id' => $langId,
                    'pagepath' => $currentPagePath,
                    'expire' => 0,
                    'rootpage_id' => $rootPageId,
                    'mpvar' => $mpvar
                );
                $queryBuilder->insert('tx_realurl_pathcache')->values($insertArray);
            }
        }
    }

    /**
     * Sets expiration time for the old path cache entries
     *
     * @param QueryBuilder $queryBuilder
     * @param string $currentPagePath
     * @return void
     */
    protected function setExpirationOnOldPathCacheEntries(QueryBuilder $queryBuilder, $currentPagePath)
    {
        $expireDays = (isset($this->conf['expireDays']) ? $this->conf['expireDays'] : 60) * 24 * 3600;
        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq('expire', 0),
            $queryBuilder->expr()->neq('pagepath', $queryBuilder->createNamedParameter($currentPagePath))
        );
        $queryBuilder->update('tx_realurl_pathcache')->set('expire', $this->makeExpirationTime($expireDays));
    }

    /**
     * Removes all expired path cache entries
     *
     * @param Connection $connection
     * @return void
     */
    protected function removeExpiredPathCacheEntries(Connection $connection)
    {
        $lastCleanUpFileName = PATH_site . 'typo3temp/realurl_last_clean_up';
        $lastCleanUpTime = @filemtime($lastCleanUpFileName);
        if ($lastCleanUpTime === false || (time() - $lastCleanUpTime >= 6*60*60)) {
            touch($lastCleanUpFileName);
            GeneralUtility::fixPermissions($lastCleanUpFileName);
            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder
                ->delete('tx_realurl_pathcache')
                ->where(
                    $queryBuilder->expr()->gt('expire', 0),
                    $queryBuilder->expr()->lt('expire', $queryBuilder->createNamedParameter($this->makeExpirationTime(), \PDO::PARAM_INT))
                )
                ->execute();
        }
    }

    /**
     * Fetch the page path (in the correct language)
     * Return it in an array like:
     *   array(
     *     'pagepath' => 'product_omschrijving/another_page_title/',
     *     'langID' => '2',
     *   );
     *
     * @param int $id Page ID
     * @param string $mpvar MP variable string
     * @param int $langID Language id
     * @return array The page path etc.
     */
    protected function IDtoPagePathSegments($id, $mpvar, $langID)
    {
        $result = false;

        // Get rootLine for current site (overlaid with any language overlay records).
        $this->createSysPageIfNecessary();
        $this->sysPage->sys_language_uid = $langID;
        $rootLine = $this->sysPage->getRootLine($id, $mpvar);
        $numberOfRootlineEntries = count($rootLine);
        $newRootLine = array();
        $rootFound = false;
        if (!$GLOBALS['TSFE']->tmpl->rootLine) {
            $GLOBALS['TSFE']->tmpl->start($GLOBALS['TSFE']->rootLine);
        }
        // Pass #1 -- check if linking a page in subdomain inside main domain
        $innerSubDomain = false;
        for ($i = $numberOfRootlineEntries - 1; $i >= 0; $i--) {
            if ($rootLine[$i]['is_siteroot']) {
                $this->pObj->devLog('Found siteroot in the rootline for id=' . $id);
                $rootFound = true;
                $innerSubDomain = true;
                for (; $i < $numberOfRootlineEntries; $i++) {
                    $newRootLine[] = $rootLine[$i];
                }
                break;
            }
        }
        if (!$rootFound) {
            // Pass #2 -- check normal page
            $this->pObj->devLog('Starting to walk rootline for id=' . $id . ' from index=' . $i, $rootLine);
            for ($i = 0; $i < $numberOfRootlineEntries; $i++) {
                if ($GLOBALS['TSFE']->tmpl->rootLine[0]['uid'] == $rootLine[$i]['uid']) {
                    $this->pObj->devLog('Found rootline', array('uid' => $id, 'rootline start pid' => $rootLine[$i]['uid']));
                    $rootFound = true;
                    for (; $i < $numberOfRootlineEntries; $i++) {
                        $newRootLine[] = $rootLine[$i];
                    }
                    break;
                }
            }
        }
        if ($rootFound) {
            // Translate the rootline to a valid path (rootline contains localized titles at this point!)
            $pagePath = $this->rootLineToPath($newRootLine, $langID);
            $this->pObj->devLog('Got page path', array('uid' => $id, 'pagepath' => $pagePath));
            $rootPageId = $this->conf['rootpage_id'];
            if ($innerSubDomain) {
                $parts = parse_url($pagePath);
                $this->pObj->devLog('$innerSubDomain=true, showing page path parts', $parts);
                if ($parts['host'] == '') {
                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                        ->getQueryBuilderForTable('sys_domain');
                    $queryBuilder->getRestrictions()
                        ->removeAll()
                        ->add(GeneralUtility::makeInstance(HiddenRestriction::class));
                    foreach ($newRootLine as $rl) {
                        $rows = $queryBuilder
                            ->select('domainName')
                            ->from('sys_domain')
                            ->where(
                                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($rl['uid'])),
                                $queryBuilder->expr()->eq('redirectTo', '""')
                            )
                            ->orderBy('sorting')
                            ->execute()
                            ->fetchAll();
                        if (count($rows)) {
                            $domain = $rows[0]['domainName'];
                            $this->pObj->devLog('Found domain', $domain);
                            $rootPageId = $rl['uid'];
                        }
                    }
                }
            }
            $result = array(
                    'pagepath' => $pagePath,
                    'langID' => intval($langID),
                    'rootpage_id' => intval($rootPageId),
                );
        }

        return $result;
    }

    /**
     * Build a virtual path for a page, like "products/product_1/features/"
     * The path is language dependant.
     * There is also a function $TSFE->sysPage->getPathFromRootline, but that one can only be used for a visual
     * indication of the path in the backend, not for a real page path.
     * Note also that the for-loop starts with 1 so the first page is stripped off. This is (in most cases) the
     * root of the website (which is 'handled' by the domainname).
     *
     * @param array $rl Rootline array for the current website (rootLine from TSFE->tmpl->rootLine but with modified localization according to language of the URL)
     * @param int $lang Language identifier (as in sys_languages)
     * @return string Path for the page, eg.
     * @see IDtoPagePathSegments()
     */
    protected function rootLineToPath($rl, $lang)
    {
        $paths = array();
        array_shift($rl); // Ignore the first path, as this is the root of the website
        $c = count($rl);
        $stopUsingCache = false;
        $this->pObj->devLog('rootLineToPath starts searching', array('rootline size' => count($rl)));
        for ($i = 1; $i <= $c; $i++) {
            $page = array_shift($rl);

            // First, check for cached path of this page
            $cachedPagePath = false;
            if (!$page['tx_realurl_exclude'] && !$stopUsingCache && !$this->conf['disablePathCache']) {

                // Using pathq2 index!
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable('tx_realurl_pathcache');
                $cachedPagePath = $queryBuilder
                    ->select('pagepath')
                    ->from('tx_realurl_pathcache')
                    ->where(
                        $queryBuilder->expr()->eq('page_id', $queryBuilder->createNamedParameter($page['uid'], \PDO::PARAM_INT)),
                        $queryBuilder->expr()->eq('language_id', $queryBuilder->createNamedParameter($lang, \PDO::PARAM_INT)),
                        $queryBuilder->expr()->eq('rootpage_id', $queryBuilder->createNamedParameter($this->conf['rootpage_id'], \PDO::PARAM_INT)),
                        $queryBuilder->expr()->eq('mpvar', $queryBuilder->createNamedParameter($page['_MP_PARAM'], \PDO::PARAM_INT)),
                        $queryBuilder->expr()->eq('expire', 0)
                    )
                    ->setMaxResults(1)
                    ->execute()
                    ->fetch();
                if (is_array($cachedPagePath)) {
                    $lastPath = implode('/', $paths);
                    $this->pObj->devLog('rootLineToPath found path', $lastPath);
                    if ($cachedPagePath != false && substr($cachedPagePath['pagepath'], 0, strlen($lastPath)) != $lastPath) {
                        // Oops. Cached path does not start from already generated path.
                        // It means that path was mapped from a parallel mount point.
                        // We cannot not rely on cache any more. Stop using it.
                        $cachedPagePath = false;
                        $stopUsingCache = true;
                        $this->pObj->devLog('rootLineToPath stops searching');
                    }
                }
            }

            // If a cached path was found for the page it will be inserted as the base of the new path, overriding anything build prior to this
            if ($cachedPagePath) {
                $paths = array();
                $paths[$i] = $cachedPagePath['pagepath'];
            } else {
                // Building up the path from page title etc.
                if (!$page['tx_realurl_exclude'] || count($rl) == 0) {
                    // List of "pages" fields to traverse for a "directory title" in the speaking URL (only from RootLine!!)
                    $segTitleFieldArray = GeneralUtility::trimExplode(',', $this->conf['segTitleFieldList'] ? $this->conf['segTitleFieldList'] : TX_REALURL_SEGTITLEFIELDLIST_DEFAULT, 1);
                    $theTitle = '';
                    foreach ($segTitleFieldArray as $fieldName) {
                        if (isset($page[$fieldName]) && $page[$fieldName] !== '') {
                            $theTitle = $page[$fieldName];
                            break;
                        }
                    }

                    $paths[$i] = $this->encodeTitle($theTitle);
                }
            }
        }

        return implode('/', $paths);
    }

    /*******************************
     *
     * URL-to-ID methods
     *
     ******************************/

    /**
     * Convert a page path to an ID.
     *
     * @param array $pathParts Array of segments from virtual path
     * @return int Page ID
     * @see decodeSpURL_idFromPath()
     */
    protected function pagePathtoID(&$pathParts)
    {
        $row = $postVar = false;
        $copy_pathParts = array();

        // If pagePath cache is not disabled, look for entry
        if (!$this->conf['disablePathCache']) {

            // Work from outside-in to look up path in cache
            $postVar = false;
            $copy_pathParts = $pathParts;
            $charset = $GLOBALS['TYPO3_CONF_VARS']['BE']['forceCharset'] ? $GLOBALS['TYPO3_CONF_VARS']['BE']['forceCharset'] : $GLOBALS['TSFE']->defaultCharSet;
            foreach ($copy_pathParts as $key => $value) {
                $copy_pathParts[$key] = mb_strtolower($value, $charset ?: 'utf-8');
            }
            while (count($copy_pathParts)) {
                // Using pathq1 index!
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable('tx_realurl_pathcache');
                $row = $queryBuilder
                    ->select('tx_realurl_pathcache.*')
                    ->from('tx_realurl_pathcache')
                    ->join(
                        'tx_realurl_pathcache',
                        'pages',
                        'pages',
                        $queryBuilder->expr()->eq('tx_realurl_pathcache.page_id', $queryBuilder->quoteIdentifier('pages.uid'))
                     )
                    ->where(
                        $queryBuilder->expr()->eq('pages.deleted', 0),
                        $queryBuilder->expr()->eq('pages.sys_language_uid', 0),
                        $queryBuilder->expr()->eq('rootpage_id', $queryBuilder->createNamedParameter($this->conf['rootpage_id'], \PDO::PARAM_INT)),
                        $queryBuilder->expr()->eq('pagepath', $queryBuilder->createNamedParameter(implode('/', $copy_pathParts)))
                    )
                    ->orderBy('expire')
                    ->setMaxResults(1)
                    ->execute()
                    ->fetch();
                // This lookup does not include language and MP var since those are supposed to be fully reflected in the built url!
                if (is_array($row)) {
                    break;
                }

                // If no row was found, we simply pop off one element of the path and try again until there are no more elements in the array - which means we didn't find a match!
                $postVar = array_pop($copy_pathParts);
            }
        }

        // It could be that entry point to a page but it is not in the cache. If we popped
        // any items from path parts, we need to check if they are defined as postSetVars or
        // fixedPostVars on this page. This does not guarantie 100% success. For example,
        // if path to page is /hello/world/how/are/you and hello/world found in cache and
        // there is a postVar 'how' on this page, the check below will not work. But it is still
        // better than nothing.
        if ($row && $postVar) {
            $postVars = $this->pObj->getPostVarSetConfig($row['page_id'], 'postVarSets');
            if (!is_array($postVars) || !isset($postVars[$postVar])) {
                // Check fixed
                $postVars = $this->pObj->getPostVarSetConfig($row['page_id'], 'fixedPostVars');
                if (!is_array($postVars) || !isset($postVars[$postVar])) {
                    // Not a postVar, so page most likely in not in cache. Clear row.
                    // TODO It would be great to update cache in this case but usually TYPO3 is not
                    // complitely initialized at this place. So we do not do it...
                    $row = false;
                }
            }
        }

        // Process row if found
        if ($row) { // We found it in the cache

            // Check for expiration. We can get one of three
            //   1. expire = 0
            //   2. expire <= time()
            //   3. expire > time()
            // 1 is permanent, we do not process it. 2 is expired, we look for permanent or non-expired
            // (in this order!) entry for the same page od and redirect to corresponding path. 3 - same as
            // 1 but means that entry is going to expire eventually, nothing to do for us yet.
            if ($row['expire'] > 0) {
                $this->pObj->devLog('pagePathToId found row', $row);
                // 'expire' in the query is only for logging
                // Using pathq2 index!

                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable('tx_realurl_pathcache');
                $newEntry = $queryBuilder
                    ->select('pagepath', 'expire')
                    ->from('tx_realurl_pathcache')
                    ->where(
                        $queryBuilder->expr()->eq('page_id', $queryBuilder->createNamedParameter($row['page_id'], \PDO::PARAM_INT)),
                        $queryBuilder->expr()->eq('language_id', $queryBuilder->createNamedParameter($row['language_id'], \PDO::PARAM_INT)),
                        $queryBuilder->expr()->orX(
                            $queryBuilder->expr()->eq('expire', 0),
                            $queryBuilder->expr()->lt('expire', $queryBuilder->createNamedParameter($row['expire'], \PDO::PARAM_INT))
                        )
                    )
                    ->orderBy('expire')
                    ->setMaxResults(1)
                    ->execute()
                    ->fetch();
                $this->pObj->devLog('pagePathToId searched for new entry', $newEntry);

                // Redirect to new path immediately if it is found
                if ($newEntry) {
                    // Replace path-segments with new ones
                    $originalDirs = $this->pObj->dirParts; // All original
                    $cp_pathParts = $pathParts;
                    // Popping of pages of original dirs (as many as are remaining in $pathParts)
                    for ($a = 0; $a < count($pathParts); $a++) {
                        array_pop($originalDirs); // Finding all preVars here
                    }
                    for ($a = 0; $a < count($copy_pathParts); $a++) {
                        array_shift($cp_pathParts); // Finding all postVars here
                    }
                    $newPathSegments = explode('/', $newEntry['pagepath']); // Split new pagepath into segments.
                    $newUrlSegments = array_merge($originalDirs, $newPathSegments, $cp_pathParts); // Merge those segments.
                    $this->pObj->appendFilePart($newUrlSegments);
                    $redirectUrl = implode('/', $newUrlSegments);

                    header('HTTP/1.1 301 TYPO3 RealURL Redirect A' . __LINE__);
                    header('Location: ' . GeneralUtility::locationHeaderUrl($redirectUrl));
                    exit();
                }
                $this->pObj->disableDecodeCache = true;    // Do not cache this!
            }

            // Unshift the number of segments that must have defined the page
            $cc = count($copy_pathParts);
            for ($a = 0; $a < $cc; $a++) {
                array_shift($pathParts);
            }

            // Assume we can use this info at first
            $id = $row['page_id'];
            $GET_VARS = $row['mpvar'] ? array('MP' => $row['mpvar']) : '';
        } else {
            // Find it
            list($id, $GET_VARS) = $this->findIDByURL($pathParts);
        }

        // Return found ID
        return array($id, $GET_VARS);
    }

    /**
     * Search recursively for the URL in the page tree and return the ID of the path ("manual" id resolve)
     *
     * @param array $urlParts Path parts, passed by reference.
     * @return array Info array, currently with "id" set to the ID.
     */
    protected function findIDByURL(array &$urlParts)
    {
        $id = 0;
        $GET_VARS = '';
        $startPid = $this->getRootPid();
        if ($startPid && count($urlParts)) {
            list($id) = $this->findIDByPathOverride($startPid, $urlParts);
            if ($id != 0) {
                $startPid = $id;
            }
            list($id, $mpvar) = $this->findIDBySegment($startPid, '', $urlParts);
            if ($mpvar) {
                $GET_VARS = array('MP' => $mpvar);
            }
        }

        return array(intval($id ?: $startPid), $GET_VARS);
    }

    /**
     * Obtains root page id for the current request.
     *
     * @return int
     */
    protected function getRootPid()
    {
        if ($this->conf['rootpage_id']) { // Take PID from rootpage_id if any:
            $startPid = intval($this->conf['rootpage_id']);
        } else {
            $startPid = $this->pObj->findRootPageId();
        }
        return intval($startPid);
    }

    /**
     * Attempts to find the page inside the root page that has a path override
     * that fits into the passed segments.
     *
     * @param int $rootPid
     * @param array $urlParts
     * @return array Key 0 is pid (or 0), key 2 is empty string
     */
    protected function findIDByPathOverride($rootPid, array &$urlParts)
    {
        $pageInfo = array(0, '');
        $extraUrlSegments = array();
        while (count($urlParts) > 0) {
            // Search for the path inside the root page
            $url = implode('/', $urlParts);
            $pageInfo = $this->findPageByPath($rootPid, $url);
            if ($pageInfo[0]) {
                break;
            }
            // Not found, try smaller segment
            array_unshift($extraUrlSegments, array_pop($urlParts));
        }
        $urlParts = $extraUrlSegments;
        return $pageInfo;
    }

    /**
     * Attempts to find the page inside the root page that has the given path.
     *
     * @param int $rootPid
     * @param string $url
     * @return array Key 0 is pid (or 0), key 2 is empty string
     */
    protected function findPageByPath($rootPid, $url)
    {
        $pages = $this->fetchPagesForPath($url);
        foreach ($pages as $key => $page) {
            if (!$this->isAnyChildOf($page['pid'], $rootPid)) {
                unset($pages[$key]);
            }
        }
        if (count($pages) > 1) {
            $idList = array();
            foreach ($pages as $page) {
                $idList[] = $page['uid'];
            }
            // No need for hsc() because TSFE does that
            $this->pObj->decodeSpURL_throw404(sprintf(
                'Multiple pages exist for path "%s": %s',
                $url, implode(', ', $idList)));
        }
        reset($pages);
        $page = current($pages);
        return array($page['uid'], '');
    }

    /**
     * Checks if the the page is any child of the root page.
     *
     * @param int $pid
     * @param int $rootPid
     * @return bool
     */
    protected function isAnyChildOf($pid, $rootPid)
    {
        $this->createSysPageIfNecessary();
        $rootLine = $this->sysPage->getRootLine($pid);
        foreach ($rootLine as $page) {
            if ($page['uid'] == $rootPid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetches a list of pages (uid,pid) for path. The priority of search is:
     * - pages
     * - pages_language_overlay
     *
     * @param string $url
     * @return array
     */
    protected function fetchPagesForPath($url)
    {
        $pagesOverlays = [];
        $language = intval($this->pObj->getDetectedLanguage());
        if ($language > 0) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll();
            $pagesOverlayParentIds = $queryBuilder
                ->select('pagetranslation.l10n_parent AS pid')
                ->from('pages')
                ->join(
                    'pagetranslation',
                    'pages',
                    'pages',
                    $queryBuilder->expr()->eq('pagetranslation.l10n_parent', $queryBuilder->quoteIdentifier('pages.uid'))
                )
                ->where(
                    $queryBuilder->expr()->eq('pagetranslation.hidden', 0),
                    $queryBuilder->expr()->eq('pagetranslation.deleted', 0),
                    $queryBuilder->expr()->eq('pages.hidden', 0),
                    $queryBuilder->expr()->eq('pages.deleted', 0),
                    $queryBuilder->expr()->eq('pages.tx_realurl_pathoverride', 1),
                    $queryBuilder->expr()->eq('pagetranslation.sys_language_uid', $queryBuilder->createNamedParameter($language, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('pagetranslation.tx_realurl_pathsegment', $queryBuilder->createNamedParameter($url))
                )
                ->execute()
                ->fetchAll();

            if (count($pagesOverlayParentIds) > 0) {
                foreach ($pagesOverlayParentIds as $pagesOverlayParentId) {
                    $pagesOverlays[] = (int)$pagesOverlayParentId['pid'];
                }
            }
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class))
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder
            ->select('uid', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('sys_language_uid', 0),
                $queryBuilder->expr()->eq('tx_realurl_pathsegment', $queryBuilder->createNamedParameter($url))
            );

        if (count($pagesOverlays)) {
            $queryBuilder->orWhere(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($pagesOverlays, Connection::PARAM_INT_ARRAY))
            );
        }
        return $queryBuilder->execute()->fetchAll();
    }

    /**
     * Recursively search the subpages of $pid for the first part of $urlParts
     *
     * @param int $startPid Page id in which to search subpages matching first part of urlParts
     * @param string $mpvar MP variable string
     * @param array $urlParts Segments of the virtual path (passed by reference; items removed)
     * @param array|string $currentIdMp Array with the current pid/mpvar to return if no processing is done.
     * @param bool $foundUID
     * @return array With resolved id and $mpvar
     */
    protected function findIDBySegment($startPid, $mpvar, array &$urlParts, $currentIdMp = '', $foundUID = false)
    {

        // Creating currentIdMp variable if not set
        if (!is_array($currentIdMp)) {
            $currentIdMp = array($startPid, $mpvar, $foundUID);
        }

        // No more urlparts? Return what we have.
        if (count($urlParts) == 0) {
            return $currentIdMp;
        }

        // Get the title we need to find now
        $segment = array_shift($urlParts);

        // Perform search
        list($uid, $row, $exclude, $possibleMatch) = $this->findPageBySegmentAndPid($startPid, $segment);

        // If a title was found...
        if ($uid) {
            return $this->processFoundPage($row, $mpvar, $urlParts, true);
        } elseif (count($exclude)) {
            // There were excluded pages, we have to process those!
            foreach ($exclude as $row) {
                $urlPartsCopy = $urlParts;
                array_unshift($urlPartsCopy, $segment);
                $result = $this->processFoundPage($row, $mpvar, $urlPartsCopy, false);
                if ($result[2]) {
                    $urlParts = $urlPartsCopy;
                    return $result;
                }
            }
        }

            // the possible "exclude in URL segment" match must be checked if no other results in
            // deeper tree branches were found, because we want to access this page also
            // + Books <-- excluded in URL (= possibleMatch)
            //   - TYPO3
            //   - ExtJS
        if (count($possibleMatch) > 0) {
            return $this->processFoundPage($possibleMatch, $mpvar, $urlParts, true);
        }

        // No title, so we reached the end of the id identifying part of the path and now put back the current non-matched title segment before we return the PID
        array_unshift($urlParts, $segment);
        return $currentIdMp;
    }

    /**
     * Process title search result. This is executed both when title is found and
     * when excluded segment is found
     *
     * @param array $row Row to process
     * @param array $mpvar MP var
     * @param array $urlParts URL segments
     * @param bool $foundUID
     * @return array Resolved id and mpvar
     * @see findPageBySegment()
     */
    protected function processFoundPage($row, $mpvar, array &$urlParts, $foundUID)
    {
        $uid = $row['uid'];
        // Set base currentIdMp for next level
        $currentIdMp = array( $uid, $mpvar, $foundUID);

        // Modify values if it was a mount point
        if (is_array($row['_IS_MOUNTPOINT'])) {
            $mpvar .= ($mpvar ? ',' : '') . $row['_IS_MOUNTPOINT']['MPvar'];
            if ($row['_IS_MOUNTPOINT']['overlay']) {
                $currentIdMp[1] = $mpvar; // Change mpvar for the currentIdMp variable.
            } else {
                $uid = $row['_IS_MOUNTPOINT']['mount_pid'];
            }
        }

        // Yep, go search for the next subpage
        return $this->findIDBySegment($uid, $mpvar, $urlParts, $currentIdMp, $foundUID);
    }

    /**
     * Search for a title in a certain PID
     *
     * @param int $searchPid Page id in which to search subpages matching title
     * @param string $title Title to search for
     * @return array First entry is uid, second entry is the row selected, including information about the page as a mount point.
     * @see findPageBySegment()
     */
    protected function findPageBySegmentAndPid($searchPid, $title)
    {

        // List of "pages" fields to traverse for a "directory title" in the speaking URL (only from RootLine!!)
        $segTitleFieldList = $this->conf['segTitleFieldList'] ?: TX_REALURL_SEGTITLEFIELDLIST_DEFAULT;
        $segTitleFieldArray = GeneralUtility::trimExplode(',', $segTitleFieldList, true);
        $selects = array_merge(['uid', 'pid', 'doktype', 'mount_pid', 'mount_pid_ol', 'tx_realurl_exclude'], $segTitleFieldArray);
        $selects = array_unique($selects);

        // page select object - used to analyse mount points.
        $sys_page = GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Page\\PageRepository');
        /** @var \TYPO3\CMS\Frontend\Page\PageRepository $sys_page */

        // Build an array with encoded values from the segTitleFieldArray of the subpages
        // First we find field values from the default language
        // Pages are selected in menu order and if duplicate titles are found the first takes precedence!
        $titles = array(); // array(title => uid);
        $exclude = array();
        $uidTrack = array();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $result = $queryBuilder
            ->select(...$selects)
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($searchPid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', 0),
                $queryBuilder->expr()->neq('doktype', 255)
            )
            ->orderBy('sorting')
            ->execute();
        while (false != ($row = $result->fetch())) {
            // Mount points
            $mount_info = $sys_page->getMountPointInfo($row['uid'], $row);
            if (is_array($mount_info)) {
                // There is a valid mount point.
                if ($mount_info['overlay']) {
                    // Overlay mode: Substitute WHOLE record
                    $queryBuilderMountPoints = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
                    $queryBuilderMountPoints->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

                    $mp_row = $queryBuilderMountPoints
                        ->select($selects)
                        ->from('pages')
                        ->where(
                            $queryBuilderMountPoints->expr()->eq('uid', $queryBuilderMountPoints->createNamedParameter($mount_info['mount_pid'], \PDO::PARAM_INT)),
                            $queryBuilderMountPoints->expr()->eq('sys_language_uid', 0),
                            $queryBuilderMountPoints->expr()->neq('doktype', 255)
                        )
                        ->execute()
                        ->fetch();
                    if (is_array($mp_row)) {
                        $row = $mp_row;
                    } else {
                        unset($row); // If the mount point could not be fetched, unset the row
                    }
                }
                $row['_IS_MOUNTPOINT'] = $mount_info;
            }

            // Collect titles from selected row
            if (is_array($row)) {
                if ($row['tx_realurl_exclude']) {
                    // segment is excluded
                    $exclude[] = $row;
                }
                // Process titles. Note that excluded segments are also searched
                // otherwise they will never be found
                $uidTrack[$row['uid']] = $row;
                foreach ($segTitleFieldArray as $fieldName) {
                    if (isset($row[$fieldName]) && $row[$fieldName] !== '') {
                        $encodedTitle = $this->encodeTitle($row[$fieldName]);
                        if (!isset($titles[$fieldName][$encodedTitle])) {
                            $titles[$fieldName][$encodedTitle] = $row['uid'];
                        }
                    }
                }
            }
        }
        // We have to search the language overlay too, if: a) the language isn't the default (0), b) if it's not set (-1)
        $uidTrackKeys = array_keys($uidTrack);
        $language = $this->pObj->getDetectedLanguage();
        if ($language != 0) {
            $overlaidFields = GeneralUtility::trimExplode(',', TX_REALURL_SEGTITLEFIELDLIST_PLO, true);
            foreach ($uidTrackKeys as $l_id) {
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages_language_overlay');
                $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

                $queryBuilder
                    ->select($overlaidFields)
                    ->from('pages_language_overlay')
                    ->where(
                        $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($l_id, \PDO::PARAM_INT))
                    );
                if ($language > 0) {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($language, \PDO::PARAM_INT))
                    );
                }
                $result = $queryBuilder->execute();
                while (false != ($row = $result->fetch())) {
                    foreach ($segTitleFieldArray as $fieldName) {
                        if ($row[$fieldName]) {
                            $encodedTitle = $this->encodeTitle($row[$fieldName]);
                            if (!isset($titles[$fieldName][$encodedTitle])) {
                                $titles[$fieldName][$encodedTitle] = $l_id;
                            }
                        }
                    }
                }
            }
        }

        // Merge titles
        $segTitleFieldArray = array_reverse($segTitleFieldArray); // To observe the priority order...
        $allTitles = array();
        foreach ($segTitleFieldArray as $fieldName) {
            if (is_array($titles[$fieldName])) {
                $allTitles = $titles[$fieldName] + $allTitles;
            }
        }

        // Return
        $encodedTitle = $this->encodeTitle($title);
        $possibleMatch = array();
        if (isset($allTitles[$encodedTitle])) {
            if (!$uidTrack[$allTitles[$encodedTitle]]['tx_realurl_exclude']) {
                return array($allTitles[$encodedTitle], $uidTrack[$allTitles[$encodedTitle]], false, array());
            }
            $possibleMatch = $uidTrack[$allTitles[$encodedTitle]];
        }
        return array(false, false, $exclude, $possibleMatch);
    }

    /*******************************
     *
     * Helper functions
     *
     ******************************/

    /**
     * Convert a title to something that can be used in an page path:
     * - Convert spaces to underscores
     * - Convert non A-Z characters to ASCII equivalents
     * - Convert some special things like the 'ae'-character
     * - Strip off all other symbols
     * Works with the character set defined as "forceCharset"
     *
     * WARNING!!! The signature or visibility of this function may change at any moment!
     *
     * @param string $title Input title to clean
     * @return string Encoded title, passed through rawurlencode() = ready to put in the URL.
     * @see rootLineToPath()
     */
    public function encodeTitle($title)
    {
        // Fetch character set
        $charset = $GLOBALS['TYPO3_CONF_VARS']['BE']['forceCharset'] ? $GLOBALS['TYPO3_CONF_VARS']['BE']['forceCharset'] : $GLOBALS['TSFE']->defaultCharSet;

        // Convert to lowercase
        $processedTitle = mb_strtolower($title, $charset ?: 'utf-8');

        // Strip tags
        $processedTitle = strip_tags($processedTitle);

        // Convert some special tokens to the space character
        $space = isset($this->conf['spaceCharacter']) ? $this->conf['spaceCharacter'] : '_';
        $processedTitle = preg_replace('/[ \-+_]+/', $space, $processedTitle); // convert spaces

        // Convert extended letters to ascii equivalents
        $charsetConverter = GeneralUtility::makeInstance(CharsetConverter::class);
        $processedTitle = $charsetConverter->specCharsToASCII($charset, $processedTitle);

        // Strip the rest
        if ($this->extConf['init']['enableAllUnicodeLetters']) {
            // Warning: slow!!!
            $processedTitle = preg_replace('/[^\p{L}0-9' . ($space ? preg_quote($space) : '') . ']/u', '', $processedTitle);
        } else {
            $processedTitle = preg_replace('/[^a-zA-Z0-9' . ($space ? preg_quote($space) : '') . ']/', '', $processedTitle);
        }
        $processedTitle = preg_replace('/\\' . $space . '{2,}/', $space, $processedTitle); // Convert multiple 'spaces' to a single one
        $processedTitle = trim($processedTitle, $space);

        if ($this->conf['encodeTitle_userProc']) {
            $encodingConfiguration = array('strtolower' => true, 'spaceCharacter' => $this->conf['spaceCharacter']);
            $params = array('pObj' => &$this, 'title' => $title, 'processedTitle' => $processedTitle, 'encodingConfiguration' => $encodingConfiguration);
            $processedTitle = GeneralUtility::callUserFunction($this->conf['encodeTitle_userProc'], $params, $this);
        }

        // Return encoded URL
        return rawurlencode(strtolower($processedTitle));
    }

    /**
     * Makes expiration timestamp for SQL queries (rounding to next day, as we cannot use UNIX_TIMESTAMP())
     *
     * @param int $offsetFromNow Offset to expiration
     * @return int Expiration time stamp
     */
    protected function makeExpirationTime($offsetFromNow = 0)
    {
        $date = getdate(time() + $offsetFromNow);
        return mktime(0, 0, 0, $date['mon'], $date['mday'], $date['year']);
    }

    /**
     * Gets the value of current language. Defaults to value
     * taken from the system configuration.
     *
     * @param array $urlParameters
     * @return int Current language or system default
     */
    protected function getLanguageVar(array $urlParameters)
    {
        // Get the default language from the TSFE
        $lang = intval($GLOBALS['TSFE']->config['config']['sys_language_uid']);

        // Setting the language variable based on GETvar in URL which has been configured to carry the language uid
        if ($this->conf['languageGetVar']) {
            if (isset($urlParameters[$this->conf['languageGetVar']])) {
                $lang = intval($urlParameters[$this->conf['languageGetVar']]);
            } elseif (isset($this->pObj->orig_paramKeyValues[$this->conf['languageGetVar']])) {
                $lang = intval($this->pObj->orig_paramKeyValues[$this->conf['languageGetVar']]);
            }
        }
        // Might be excepted (like you should for CJK cases which does not translate to ASCII equivalents)
        if (isset($this->conf['languageExceptionUids']) && GeneralUtility::inList($this->conf['languageExceptionUids'], $lang)) {
            $lang = 0;
        }

        return $lang;
    }

    /**
     * Resolves shortcut to the page
     *
     * @param array $page Page record
     * @param bool $disableGroupAccessCheck Flag for getPage()
     * @param array $log Internal log
     * @param string|null $mpvar
     * @return int Found page id
     */
    protected function resolveShortcut($page, $disableGroupAccessCheck, $log = array(), &$mpvar = null)
    {
        if (isset($log[$page['uid']])) {
            // loop detected!
            return $page['uid'];
        }
        $log[$page['uid']] = '';
        $pageid = $page['uid'];
        if ($page['shortcut_mode'] == 0) {
            // Jumps to a certain page
            if ($page['shortcut']) {
                $pageid = intval($page['shortcut']);
                $page = $GLOBALS['TSFE']->sys_page->getPage($pageid, $disableGroupAccessCheck);
                if ($page && $page['doktype'] == 4) {
                    $mpvar = '';
                    $pageid = $this->resolveShortcut($page, $disableGroupAccessCheck, $log, $mpvar);
                }
            }
        } elseif ($page['shortcut_mode'] == 1) {
            // Jumps to the first subpage
            $rows = $GLOBALS['TSFE']->sys_page->getMenu($page['uid']);
            if (count($rows) > 0) {
                reset($rows);
                $row = current($rows);
                $pageid = ($row['doktype'] == 4 ? $this->resolveShortcut($row, $disableGroupAccessCheck, $log, $mpvar) : $row['uid']);
            }

            if (isset($row['_MP_PARAM'])) {
                if ($mpvar) {
                    $mpvar .= ',';
                }

                $mpvar .= $row['_MP_PARAM'];
            }
        } elseif ($page['shortcut_mode'] == 3) {
            // Jumps to the parent page
            $page = $GLOBALS['TSFE']->sys_page->getPage($page['pid'], $disableGroupAccessCheck);
            $pageid = $page['uid'];
            if ($page && $page['doktype'] == 4) {
                $pageid = $this->resolveShortcut($page, $disableGroupAccessCheck, $log, $mpvar);
            }
        }
        return $pageid;
    }

    /**
     * Creates $this->sysPage if it does not exist yet
     *
     * @return void
     */
    protected function createSysPageIfNecessary()
    {
        if (!is_object($this->sysPage)) {
            $this->sysPage = GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Page\\PageRepository');
            $this->sysPage->init($GLOBALS['TSFE']->showHiddenPage || $this->pObj->isBEUserLoggedIn());
        }
    }
}
