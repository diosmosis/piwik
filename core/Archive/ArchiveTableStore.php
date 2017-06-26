<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Archive;

use Piwik\ArchiveProcessor\Rules;
use Piwik\ArchiveProcessor\Parameters as ArchiveProcessorParams;
use Piwik\Cache\Transient;
use Piwik\DataAccess\ArchiveSelector;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\Date;
use Piwik\Log;
use Piwik\Metrics;
use Piwik\Period;
use Piwik\Site;

class ArchiveTableStore
{
    /**
     * @var IdArchiveCache
     */
    private $idArchiveCache;

    /**
     * @var ArchiveInvalidator
     */
    private $invalidator;

    /**
     * @var Transient
     */
    private $transientCache;

    public function __construct(IdArchiveCache $idArchiveCache, ArchiveInvalidator $invalidator,
                                Transient $transientCache)
    {
        $this->idArchiveCache = $idArchiveCache;
        $this->invalidator = $invalidator;
        $this->transientCache = $transientCache;
    }

    /**
     * Returns archive IDs for the sites, periods and archive names that are being
     * queried. This function will use the idarchive cache if it has the right data,
     * query archive tables for IDs w/o launching archiving, or launch archiving and
     * get the idarchive from ArchiveProcessor instances.
     *
     * @param string[] $archiveNames
     * @return array
     */
    public function getArchiveIds(Parameters $params, $archiveNames)
    {
        $plugins = $this->getRequestedPlugins($archiveNames);
        if (empty($plugins)) {
            return [];
        }

        $this->invalidatedReportsIfNeeded($params);

        // cache id archives for plugins we haven't processed yet
        if (!Rules::isArchivingDisabledFor($params->getIdSites(), $params->getSegment(), $params->getPeriodLabel())) {
            $this->cacheArchiveIdsAfterLaunching($params, $plugins);
        } else {
            $this->cacheArchiveIdsWithoutLaunching($params, $plugins);
        }

        return $this->getIdArchivesByMonth($params, $plugins);
    }

    public function getArchiveData(array $archiveIds, array $archiveNames, $archiveDataType, $idSubtable = null)
    {
        return ArchiveSelector::getArchiveData($archiveIds, $archiveNames, $archiveDataType, $idSubtable);
    }

    public function getArchiveIdAndVisits(ArchiveProcessorParams $params, $minDatetimeArchiveProcessedUTC)
    {
        return ArchiveSelector::getArchiveIdAndVisits($params, $minDatetimeArchiveProcessedUTC);
    }

    public function insertRecord(ArchiveProcessorParams $params, $idArchive, $column, $value)
    {
        $archiveWriter = new ArchiveWriter($params, $idArchive);
        $archiveWriter->insertRecord($column, $value);
    }

    public function insertBlobRecord(ArchiveProcessorParams $params, $idArchive, $name, $values)
    {
        $archiveWriter = new ArchiveWriter($params, $idArchive);
        $archiveWriter->insertBlobRecord($name, $values);
    }

    public function startArchive(ArchiveProcessorParams $params)
    {
        $archiveWriter = new ArchiveWriter($params);
        return $archiveWriter->initNewArchive();
    }

    public function finishArchive(ArchiveProcessorParams $params, $idArchive, $status)
    {
        $archiveWriter = new ArchiveWriter($params, $idArchive);
        $archiveWriter->finalizeArchive($status);
    }

    /**
     * Gets the IDs of the archives we're querying for and stores them in $this->archives.
     * This function will launch the archiving process for each period/site/plugin if
     * metrics/reports have not been calculated/archived already.
     *
     * @param Parameters $params
     * @param array $plugins List of plugin names to archive.
     */
    private function cacheArchiveIdsAfterLaunching(Parameters $params, $plugins)
    {
        // if Loader is going to process every plugin at once, just use Loader w/ the first plugin
        if (Rules::shouldProcessReportsAllPlugins($params->getIdSites(), $params->getSegment(), $params->getPeriodLabel())) {
            $plugins = [reset($plugins)];
        }

        $today = Date::today();

        foreach ($params->getPeriods() as $period) {
            $twoDaysBeforePeriod = $period->getDateStart()->subDay(2);
            $twoDaysAfterPeriod = $period->getDateEnd()->addDay(2);

            foreach ($params->getIdSites() as $idSite) {
                $site = new Site($idSite);

                // if the END of the period is BEFORE the website creation date
                // we already know there are no stats for this period
                // we add one day to make sure we don't miss the day of the website creation
                if ($twoDaysAfterPeriod->isEarlier($site->getCreationDate())) {
                    // TODO: use Logger
                    Log::debug("Archive site %s, %s (%s) skipped, archive is before the website was created.",
                        $idSite, $period->getLabel(), $period->getPrettyString());
                    continue;
                }

                // if the starting date is in the future we know there is no visiidsite = ?t
                if ($twoDaysBeforePeriod->isLater($today)) {
                    Log::debug("Archive site %s, %s (%s) skipped, archive is after today.",
                        $idSite, $period->getLabel(), $period->getPrettyString());
                    continue;
                }

                $this->prepareArchive($params, $plugins, $site, $period);
            }
        }
    }

    /**
     * Gets the IDs of the archives we're querying for and stores them in $this->archives.
     * This function will not launch the archiving process (and is thus much, much faster
     * than cacheArchiveIdsAfterLaunching).
     *
     * @param array $plugins List of plugin names from which data is being requested.
     */
    private function cacheArchiveIdsWithoutLaunching(Parameters $params, $plugins)
    {
        // TODO: if the archives already exist in the cache, we don't need to re-query
        $idarchivesByReport = ArchiveSelector::getArchiveIds(
            $params->getIdSites(), $params->getPeriods(), $params->getSegment(), $plugins);

        foreach ($idarchivesByReport as $doneFlag => $idarchivesByDate) {
            foreach ($idarchivesByDate as $dateRange => $idArchives) {
                foreach ($idArchives as $idSite => $idArchive) {
                    $this->idArchiveCache->set($idSite, $dateRange, $doneFlag, $idArchive);
                }
            }
        }
    }

    private function prepareArchive(Parameters $params, array $plugins, Site $site, Period $period)
    {
        $parameters = new \Piwik\ArchiveProcessor\Parameters($site, $period, $params->getSegment());
        $archiveLoader = new \Piwik\ArchiveProcessor\Loader($parameters, $this);

        $periodString = $period->getRangeString();

        $idSite = $site->getId();

        // process for each plugin as well
        foreach ($plugins as $plugin) {
            $doneFlag = $this->getDoneStringForPlugin($params, $plugin, [$idSite]);
            if ($this->idArchiveCache->has($idSite, $periodString, $doneFlag)) {
                continue;
            }

            $idArchive = $archiveLoader->prepareArchive($plugin);
            $this->idArchiveCache->set($idSite, $periodString, $doneFlag, $idArchive);
        }
    }

    private function getIdArchivesByMonth(Parameters $params, $plugins)
    {
        // get done flags of archives to look for. these done flags are used to access the idarchive cache.
        $doneFlags = array_map(function ($plugin) use ($params) {
            return $this->getDoneStringForPlugin($params, $plugin, $params->getIdSites());
        }, $plugins);
        $doneFlags[] = Rules::getDoneFlagArchiveContainsAllPlugins($params->getSegment());
        $doneFlags = array_unique($doneFlags);

        // order idarchives by the table month they belong to
        $idArchivesByMonth = array();

        foreach ($doneFlags as $doneFlag) {
            foreach ($params->getPeriods() as $period) {
                $dateRange = $period->getRangeString();
                foreach ($params->getIdSites() as $idSite) {
                    if ($this->idArchiveCache->hasNonEmpty($idSite, $dateRange, $doneFlag)) {
                        $idArchivesByMonth[$dateRange][] = $this->idArchiveCache->get($idSite, $dateRange, $doneFlag);
                    }
                }
            }
        }

        return $idArchivesByMonth;
    }

    private function getSiteIdsThatAreRequestedInThisArchiveButWereNotInvalidatedYet(Parameters $params)
    {
        $id = 'Archive.SiteIdsOfRememberedReportsInvalidated';

        if (!$this->transientCache->contains($id)) {
            $this->transientCache->save($id, array());
        }

        $siteIdsAlreadyHandled = $this->transientCache->fetch($id);
        $siteIdsRequested      = $params->getIdSites();

        foreach ($siteIdsRequested as $index => $siteIdRequested) {
            $siteIdRequested = (int) $siteIdRequested;

            if (in_array($siteIdRequested, $siteIdsAlreadyHandled)) {
                unset($siteIdsRequested[$index]); // was already handled previously, do not do it again
            } else {
                $siteIdsAlreadyHandled[] = $siteIdRequested; // we will handle this id this time
            }
        }

        $this->transientCache->save($id, $siteIdsAlreadyHandled);

        return $siteIdsRequested;
    }

    private function invalidatedReportsIfNeeded(Parameters $params)
    {
        $siteIdsRequested = $this->getSiteIdsThatAreRequestedInThisArchiveButWereNotInvalidatedYet($params);

        if (empty($siteIdsRequested)) {
            return; // all requested site ids were already handled
        }

        $sitesPerDays = $this->invalidator->getRememberedArchivedReportsThatShouldBeInvalidated();

        foreach ($sitesPerDays as $date => $siteIds) {
            if (empty($siteIds)) {
                continue;
            }

            $siteIdsToActuallyInvalidate = array_intersect($siteIds, $siteIdsRequested);

            if (empty($siteIdsToActuallyInvalidate)) {
                continue; // all site ids that should be handled are already handled
            }

            try {
                $this->invalidator->markArchivesAsInvalidated($siteIdsToActuallyInvalidate, array(Date::factory($date)), false);
            } catch (\Exception $e) {
                Site::clearCache();
                throw $e;
            }
        }

        Site::clearCache();
    }

    /**
     * Returns the list of plugins that archive the given reports.
     *
     * @param array $archiveNames
     * @return array
     */
    private function getRequestedPlugins($archiveNames)
    {
        $result = array();

        foreach ($archiveNames as $name) {
            $result[] = self::getPluginForReport($name);
        }

        return array_unique($result);
    }

    /**
     * Returns the name of the plugin that archives a given report.
     *
     * @param string $report Archive data name, eg, `'nb_visits'`, `'DevicesDetection_...'`, etc.
     * @return string Plugin name.
     * @throws \Exception If a plugin cannot be found or if the plugin for the report isn't
     *                    activated.
     */
    private static function getPluginForReport($report)
    {
        // Core metrics are always processed in Core, for the requested date/period/segment
        if (in_array($report, Metrics::getVisitsMetricNames())) {
            $report = 'VisitsSummary_CoreMetrics';
        } // Goal_* metrics are processed by the Goals plugin (HACK)
        elseif (strpos($report, 'Goal_') === 0) {
            $report = 'Goals_Metrics';
        } elseif (strrpos($report, '_returning') === strlen($report) - strlen('_returning')) { // HACK
            $report = 'VisitFrequency_Metrics';
        }

        $plugin = substr($report, 0, strpos($report, '_'));
        if (empty($plugin)
            || !\Piwik\Plugin\Manager::getInstance()->isPluginActivated($plugin)
        ) {
            throw new \Exception("Error: The report '$report' was requested but it is not available at this stage."
                . " (Plugin '$plugin' is not activated.)");
        }
        return $plugin;
    }

    /**
     * Returns the done string flag for a plugin using this instance's segment & periods.
     * @param Parameters $params
     * @param string $plugin
     * @param $idSites
     * @return string
     */
    private function getDoneStringForPlugin(Parameters $params, $plugin, $idSites)
    {
        return Rules::getDoneStringFlagFor($idSites, $params->getSegment(), $params->getPeriodLabel(), $plugin);
    }
}