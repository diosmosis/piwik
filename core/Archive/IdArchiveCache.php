<?php
/**
 * Copyright (C) InnoCraft Ltd - All rights reserved.
 *
 * NOTICE:  All information contained herein is, and remains the property of InnoCraft Ltd.
 * The intellectual and technical concepts contained herein are protected by trade secret or copyright law.
 * Redistribution of this information or reproduction of this material is strictly forbidden
 * unless prior written permission is obtained from InnoCraft Ltd.
 *
 * You shall use this code only in accordance with the license agreement obtained from InnoCraft Ltd.
 *
 * @link https://www.innocraft.com/
 * @license For license details see https://www.innocraft.com/license
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

    public function has($idSite, $dateRange, $doneFlag)
    {
        return isset($this->idArchives[$idSite][$dateRange][$doneFlag]);
    }

    /**
     * Returns true if the archive for $idSite, $dateRange and $doneFlag has been queried,
     * AND has visits.
     *
     * @return bool
     */
    public function hasNonEmpty($idSite, $dateRange, $doneFlag)
    {
        return !empty($this->idArchives[$idSite][$dateRange][$doneFlag]);
    }

    public function get($idSite, $dateRange, $doneFlag)
    {
        return $this->idArchives[$idSite][$dateRange][$doneFlag];
    }

    public function set($idSite, $dateRange, $doneFlag, $idArchive)
    {
        $this->idArchives[$idSite][$dateRange][$doneFlag] = $idArchive;
    }
}