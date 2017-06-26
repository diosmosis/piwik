<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Archive;

/**
 * Returns list of archive IDs for the site, periods and segment we are querying with.
 * Archive IDs are indexed by done flag, period and idSite, ie:
 *
 * array(
 *     '1' => array(
 *         '2010-01-01' => ['done.Referrers' => 1],
 *         '2010-01-02' => ['done.Referrers' => 1],
 *     ),
 *     '2' => array(
 *         '2010-01-01' => ['done.VisitsSummary' => 3],
 *         '2010-01-02' => ['done.VisitsSummary' => 4],
 *     ),
 * )
 *
 * or,
 *
 * array(
 *     '1' => array(
 *         '2010-01-01' => ['done.all' => 1],
 *         '2010-01-02' => ['done.Goals' => 2],
 *     ),
 * )
 *
 * If an archive has no metrics, the value will be false-y.
 *
 * @return array
 */
class IdArchiveCache
{
    /**
     * @var array
     */
    private $idArchives = [];

    public function has($idSite, $dateRange, $segmentHash, $plugin)
    {
        return isset($this->idArchives[$idSite][$dateRange][$segmentHash][$plugin]);
    }

    /**
     * Returns true if the archive for $idSite, $dateRange and $doneFlag has been queried,
     * AND has visits.
     *
     * @return bool
     */
    public function hasNonEmpty($idSite, $dateRange, $segmentHash, $plugin)
    {
        return !empty($this->idArchives[$idSite][$dateRange][$segmentHash][$plugin]);
    }

    public function get($idSite, $dateRange, $segmentHash, $plugin)
    {
        return $this->idArchives[$idSite][$dateRange][$segmentHash][$plugin];
    }

    public function set($idSite, $dateRange, $segmentHash, $plugin, $idArchive)
    {
        $this->idArchives[$idSite][$dateRange][$segmentHash][$plugin] = $idArchive;
    }

    public function flushBySite(array $idSites)
    {
        foreach ($idSites as $idSite) {
            unset($this->idArchives[$idSite]);
        }
    }

    public function flushAll()
    {
        $this->idArchives = [];
    }
}