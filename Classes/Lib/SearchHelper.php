<?php

namespace Tpwd\KeSearch\Lib;

/***************************************************************
 *  Copyright notice
 *  (c) 2014 Christian Bülter
 *  All rights reserved
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Tpwd\KeSearch\Domain\Repository\CategoryRepository;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * helper functions
 * must be used used statically!
 * Example:
 * $this->extConf = tx_kesearch_helper::getExtConf();
 */
class SearchHelper
{
    public const PI_VARS = ['sword', 'sortByField', 'sortByDir', 'page', 'resetFilters', 'filter'];
    public static $systemCategoryPrefix = 'syscat';

    /**
     * get extension configuration for ke_search
     * and make it possible to override it with page ts setup
     * @return array
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public static function getExtConf()
    {
        /** @var ExtensionConfiguration $extensionConfigurationApi */
        $extensionConfigurationApi = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $extConf = $extensionConfigurationApi->get('ke_search');

        // Set the "tagChar"
        // sphinx has problems with # in query string.
        // so you we need to change the default char # against something else.
        // MySQL has problems also with #
        // but we wrap # with " and it works.
        $keSearchPremiumIsLoaded = ExtensionManagementUtility::isLoaded('ke_search_premium');
        if ($keSearchPremiumIsLoaded) {
            $extConfPremium = SearchHelper::getExtConfPremium();
            $extConf['prePostTagChar'] = $extConfPremium['prePostTagChar'];
        } else {
            $extConf['prePostTagChar'] = '#';
        }
        $extConf['multiplyValueToTitle'] = ($extConf['multiplyValueToTitle']) ? $extConf['multiplyValueToTitle'] : 1;
        $extConf['searchWordLength'] = ($extConf['searchWordLength']) ? $extConf['searchWordLength'] : 4;

        // override extConf with TS Setup
        if (is_array($GLOBALS['TSFE']->tmpl->setup['ke_search.']['extconf.']['override.'] ?? null)
            && count($GLOBALS['TSFE']->tmpl->setup['ke_search.']['extconf.']['override.'])) {
            foreach ($GLOBALS['TSFE']->tmpl->setup['ke_search.']['extconf.']['override.'] as $key => $value) {
                $extConf[$key] = $value;
            }
        }

        return $extConf;
    }

    /**
     * get extension configuration for ke_search_premium
     * and make it possible to override it with page ts setup
     * @return array
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public static function getExtConfPremium()
    {
        /** @var ExtensionConfiguration $extensionConfigurationApi */
        $extensionConfigurationApi = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $keSearchPremiumIsLoaded = ExtensionManagementUtility::isLoaded('ke_search_premium');
        if ($keSearchPremiumIsLoaded) {
            $extConfPremium = $extensionConfigurationApi->get('ke_search_premium');
            if (!$extConfPremium['prePostTagChar']) {
                $extConfPremium['prePostTagChar'] = '_';
            }
        } else {
            $extConfPremium = [];
        }

        // override extConfPremium with TS Setup
        if (is_array($GLOBALS['TSFE']->tmpl->setup['ke_search_premium.']['extconf.']['override.'] ?? null)
            && count($GLOBALS['TSFE']->tmpl->setup['ke_search_premium.']['extconf.']['override.'])) {
            foreach ($GLOBALS['TSFE']->tmpl->setup['ke_search_premium.']['extconf.']['override.'] as $key => $value) {
                $extConfPremium[$key] = $value;
            }
        }

        return $extConfPremium;
    }

    /**
     * returns the list of assigned categories to a certain record in a certain table
     * @param int $uid
     * @param string $table
     * @return array
     */
    public static function getCategories(int $uid, string $table): array
    {
        $categoryData = [
            'uid_list' => [],
            'title_list' => [],
        ];

        if ($uid && $table) {
            /** @var CategoryRepository $categoryRepository */
            $categoryRepository = GeneralUtility::makeInstance(CategoryRepository::class);
            $categoryRecords = $categoryRepository->findAssignedToRecord($table, $uid);
            if (!empty($categoryRecords)) {
                foreach ($categoryRecords as $cat) {
                    $categoryData['uid_list'][] = $cat['uid'];
                    $categoryData['title_list'][] = $cat['title'];
                }
            }
        }

        return $categoryData;
    }

    /**
     * Adds a tag to a given list of comma-separated tags.
     * Does not add the tag if it is already in the list.
     *
     * @param string $tagToAdd Tag without the "prePostTagChar" (normally #)
     * @param string $tags
     * @return string
     */
    public static function addTag(string $tagToAdd, $tags='')
    {
        if ($tagToAdd) {
            $extConf = SearchHelper::getExtConf();
            $tagToAdd = $extConf['prePostTagChar'] . $tagToAdd . $extConf['prePostTagChar'];
            $tagArray = GeneralUtility::trimExplode(',', $tags);
            if (!in_array($tagToAdd, $tagArray)) {
                if (strlen($tags)) {
                    $tags .= ',';
                }
                $tags .= $tagToAdd;
            }
        }
        return $tags;
    }

    /**
     * creates tags from an array of strings
     * removes non-alphanumeric characters
     *
     * @param string|null $tags comma-list of tags, new tags will be added to this
     * @param array $tagTitles Array of Titles (eg. categories)
     */
    public static function makeTags(&$tags, array $tagTitles)
    {
        if (is_array($tagTitles) && count($tagTitles)) {
            $tags = $tags ?? '';
            $extConf = SearchHelper::getExtConf();

            foreach ($tagTitles as $title) {
                $tag = preg_replace('/[^A-Za-z0-9]/', '', $title);
                if (!empty($tags)) {
                    $tags .= ',';
                }
                $tags .= $extConf['prePostTagChar'] . $tag . $extConf['prePostTagChar'];
            }
        }
    }

    /**
     * finds the system categories for $uid in $tablename, creates
     * tags like "syscat123" ("syscat" + category uid).
     *
     * @param string|null $tags
     * @param int $uid
     * @param string $tablename
     */
    public static function makeSystemCategoryTags(&$tags, int $uid, string $tablename)
    {
        $tags = $tags ?? '';
        $categories = SearchHelper::getCategories($uid, $tablename);
        if (count($categories['uid_list'])) {
            foreach ($categories['uid_list'] as $category_uid) {
                SearchHelper::makeTags($tags, [SearchHelper::createTagnameFromSystemCategoryUid($category_uid)]);
            }
        }
    }

    /**
     * creates tags like "syscat123" ("syscat" + category uid).
     *
     * @param int $uid
     * @return string
     */
    public static function createTagnameFromSystemCategoryUid(int $uid)
    {
        return SearchHelper::$systemCategoryPrefix . $uid;
    }

    /**
     * renders a link to a search result
     *
     * @param array $resultRow
     * @param string $targetDefault
     * @param string $targetFiles
     * @return array
     */
    public static function getResultLinkConfiguration(array $resultRow, $targetDefault = '', $targetFiles = '')
    {
        $linkConf = [];

        list($type) = explode(':', $resultRow['type']);

        switch ($type) {
            case 'file':
                // render a link for files
                // If an orig_uid is given, we use FAL and we can use the API. Otherwise, we just have a plain file path.
                if ($resultRow['orig_uid']) {
                    if (SearchHelper::getFile($resultRow['orig_uid'])) {
                        $linkConf['parameter'] = 't3://file?uid=' . $resultRow['orig_uid'];
                    }
                } else {
                    $filePath = $resultRow['directory'] . $resultRow['title'];
                    if (file_exists($filePath)) {
                        $linkConf['parameter'] = rawurlencode(PathUtility::stripPathSitePrefix($filePath));
                    }
                }
                $linkConf['fileTarget'] = $targetFiles;
                break;

            case 'external':
                // render a link for external results (provided by eg. ke_search_premium)
                $linkConf['parameter'] = $resultRow['params'];
                $linkConf['additionalParams'] = '';
                $extConfPremium = SearchHelper::getExtConfPremium();
                $linkConf['extTarget'] = ($extConfPremium['apiExternalResultTarget'] ?? '') ?: '_blank';
                break;

            default:
                // render a link for page targets
                // if params are filled, add them to the link generation process
                if (!empty($resultRow['params'])) {
                    $linkConf['additionalParams'] = $resultRow['params'];
                }
                $linkConf['parameter'] = $resultRow['targetpid'];
                $linkConf['target'] = $targetDefault;
                break;
        }

        return $linkConf;
    }

    /**
     * @param int $uid
     * @return File|null
     */
    public static function getFile(int $uid)
    {
        try {
            /** @var ResourceFactory $resourceFactory */
            $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
            $fileObject = $resourceFactory->getFileObject($uid);
        } catch (FileDoesNotExistException $e) {
            $fileObject = null;
        }

        return $fileObject;
    }

    /**
     * Explode flattened piVars to multi-dimensional array, eg.
     * tx_kesearch_pi1[filter_3]=example --> tx_kesearch_pi1[filter][3]=example
     * tx_kesearch_pi1[filter_3_1]=example --> tx_kesearch_pi1[filter][3][1]=example
     *
     * @param array $piVars
     * @param string $additionalAllowedPiVars comma-separated list
     * @return array
     */
    public static function explodePiVars(array $piVars, string $additionalAllowedPiVars = ''): array
    {
        foreach ($piVars as $key => $value) {
            if (strstr($key, '_')) {
                $newKeys = explode('_', $key, 2);
                if (strstr($newKeys[1], '_')) {
                    $newKeys2 = explode('_', $newKeys[1], 2);
                    $piVars[$newKeys[0]][$newKeys2[0]][$newKeys2[1]] = $value;
                } else {
                    $piVars[$newKeys[0]][$newKeys[1]] = $value;
                }
            }
        }
        foreach ($piVars as $key => $value) {
            if (!in_array($key, self::getAllowedPiVars($additionalAllowedPiVars)) || empty($piVars[$key])) {
                unset($piVars[$key]);
            }
        }
        return $piVars;
    }

    /**
     * Creates a link to the search result on the given page, flattens the piVars, resets given filters.
     * If linkText is given, it renders a full a-tag, otherwise only the URL.
     *
     * @param int $parameter target page
     * @param array $piVars
     * @param array $resetFilters filters to reset
     * @param string $linkText
     * @return string
     */
    public static function searchLink(int $parameter, array $piVars=[], $resetFilters=[], $linkText = ''): string
    {
        // If no cObj is available we cannot render the link.
        // This might be the case if the current request is headless (ke_search_premium feature).
        if (!$GLOBALS['TSFE']->cObj) {
            return '';
        }

        // Prepare link configuration
        $keepPiVars = self::PI_VARS;
        $linkconf = [
            'parameter' => $parameter,
            'additionalParams' => '',
        ];
        unset($keepPiVars[array_search('filter', $keepPiVars)]);

        // If an alternative search word parameter is given, replace the default search word parameter
        $searchWordParameter = SearchHelper::getSearchWordParameter();
        if ($searchWordParameter != 'tx_kesearch_pi1[sword]' && isset($piVars['sword'])) {
            $linkconf['additionalParams'] .= '&' . $searchWordParameter . '=' . $piVars['sword'];
            unset($piVars['sword']);
        }

        // Compile the link parameters
        foreach ($keepPiVars as $piVarKey) {
            if (!empty($piVars[$piVarKey])) {
                if (!is_string($piVars[$piVarKey])) {
                    $piVars[$piVarKey] = '';
                }
                $linkconf['additionalParams'] .= '&tx_kesearch_pi1[' . $piVarKey . ']=' . urlencode($piVars[$piVarKey]);
            }
        }

        // Flatten the filter parameters
        if (is_array($piVars['filter'] ?? null) && count($piVars['filter'])) {
            foreach ($piVars['filter'] as $filterUid => $filterValue) {
                if (!in_array($filterUid, $resetFilters)) {
                    if (!is_array($piVars['filter'][$filterUid])) {
                        $linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter_' . $filterUid . ']=' . $filterValue;
                    } else {
                        foreach ($piVars['filter'][$filterUid] as $filterOptionUid => $filterOptionValue) {
                            $linkconf['additionalParams'] .= '&tx_kesearch_pi1[filter_' . $filterUid . '_' . $filterOptionUid . ']=' . $filterOptionValue;
                        }
                    }
                }
            }
        }

        // Build the link
        if (empty($linkText)) {
            return $GLOBALS['TSFE']->cObj->typoLink_URL($linkconf);
        }
        return $GLOBALS['TSFE']->cObj->typoLink($linkText, $linkconf);
    }

    /**
     * @param $filterOptionRecord
     * @return string
     * @throws \TYPO3\CMS\Core\Exception\SiteNotFoundException
     */
    public static function createFilterOptionSlug($filterOptionRecord): string
    {
        /** @var SlugHelper $slugHelper */
        $slugHelper = GeneralUtility::makeInstance(
            SlugHelper::class,
            'tx_kesearch_filteroptions',
            'slug',
            $GLOBALS['TCA']['tx_kesearch_filteroptions']['columns']['slug']['config']
        );

        $slug = $slugHelper->generate($filterOptionRecord, $filterOptionRecord['pid']);

        $state = RecordStateFactory::forName('tx_kesearch_filteroptions')
            ->fromArray($filterOptionRecord, $filterOptionRecord['pid'], $filterOptionRecord['uid']);
        if (!$slugHelper->isUniqueInSite($slug, $state)) {
            $slug = $slugHelper->buildSlugForUniqueInSite($slug, $state);
        }

        return $slug;
    }

    /**
     * Returns the timestamp when the indexer has been started.
     * Returns 0 if the indexer is not running.
     *
     * @return int
     */
    public static function getIndexerStartTime(): int
    {
        $registry = GeneralUtility::makeInstance(Registry::class);
        $indexerStartTime = $registry->get('tx_kesearch', 'startTimeOfIndexer');
        return $indexerStartTime ?: 0;
    }

    /**
     * Returns the timstamp when the indexer hast been started the last time.
     * Returns 0 if the indexer has never been started.
     *
     * @return int
     */
    public static function getIndexerLastRunTime(): int
    {
        $registry = GeneralUtility::makeInstance(Registry::class);
        $lastRun = $registry->get('tx_kesearch', 'lastRun');
        if (!empty($lastRun) && !empty($lastRun['startTime'])) {
            $lastRunStartTime = $lastRun['startTime'];
        } else {
            $lastRunStartTime = 0;
        }
        return $lastRunStartTime;
    }

    /**
     * @param int $timestamp
     * @return string
     */
    public static function formatTimestamp(int $timestamp): string
    {
        return date(
            LocalizationUtility::translate('backend.date.format.day', 'ke_search')
            . ', ' . LocalizationUtility::translate('backend.date.format.time', 'ke_search'),
            $timestamp
        );
    }

    /**
     * @param $additionalAllowedPiVars
     * @return array
     */
    public static function getAllowedPiVars($additionalAllowedPiVars = ''): array
    {
        return array_merge(self::PI_VARS, GeneralUtility::trimExplode(',', $additionalAllowedPiVars));
    }

    /**
     * @return string
     */
    public static function getSearchWordParameter(): string
    {
        return htmlspecialchars($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_kesearch_pi1.']['searchWordParameter'] ?? 'tx_kesearch_pi1[sword]');
    }
}
