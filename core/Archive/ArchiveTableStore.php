<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Archive;
use Piwik\Archive;
use Piwik\DataAccess\ArchiveSelector;

/**
 * TODO
 * @package Piwik\Archive
 */
class ArchiveTableStore
{
    const NUMERIC_RECORD_TYPE = 'numeric';

    /**
     * @param Parameters $keyParams
     * @param string[] $recordNames
     * @param string $recordType
     * @return array
     */
    public function fetchMultiple(Parameters $keyParams, array $recordNames, $recordType)
    {
        $plugins = $this->getRequestedPlugins($recordNames);

        $archiveIds = ArchiveSelector::getArchiveIds(
            $keyParams->getIdSites(), $keyParams->getPeriods(), $keyParams->getSegment(), $plugins);
        if (empty($archiveIds)) {
            return [];
        }

        return ArchiveSelector::getArchiveData($archiveIds, $recordNames, $recordType, $idSubtable = null);
    }

    public function fetchMultipleByIdArchive($idArchives, array $recordNames, $recordType)
    {
        return ArchiveSelector::getArchiveData($idArchives, $recordNames, $recordType, $idSubtable = null);
    }

    /**
     * Puts data into the cache.
     *
     * If a cache entry with the given id already exists, its data will be replaced.
     *
     * @param string $id The cache id.
     * @param mixed $data The cache entry/data.
     *
     * @return bool TRUE if the entry was successfully stored in the cache, FALSE otherwise.
     */
    public function save($id, $data)
    {
        // TODO: Implement save() method.
    }

    /**
     * Deletes a cache entry.
     *
     * @param string $id The cache id.
     *
     * @return bool TRUE if the cache entry was successfully deleted, FALSE otherwise.
     *              Deleting a non-existing entry is considered successful.
     */
    public function delete($id)
    {
        // TODO: Implement delete() method.
    }

    /**
     * Returns the list of plugins that archive the given reports.
     *
     * @param array $archiveNames
     * @return array
     */
    private function getRequestedPlugins($archiveNames)
    {
        $result = array_map([Archive::class, 'getPluginForReport'], $archiveNames);
        return array_unique($result);
    }
}