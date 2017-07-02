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

// TODO: would be good if I didn't have to use both
use Piwik\ArchiveProcessor\Parameters as ArchiveProcessorParameters;

/**
 * "Archive data" in Piwik is cached aggregated data, like reports & metrics.
 * The class that implements this interface determines how that data is
 * queried/stored.
 *
 * Archive data is stored in a key value store, where the key includes the
 * site ID, period, segment and the name of the specific piece of data. The implementation
 * shipped with Piwik uses MySQL tables.
 *
 * ## Concepts
 *
 * **Archive**
 *
 * A single "archive" in Piwik is a group of related archived reports & metrics.
 * In the relational backend, there are two types of "archives":
 *
 * * a plugin specific archive, which would only store reports & metrics for a single
 *   plugin.
 * * an "all plugins" archive, which stores reports & metrics for all plugins
 *   together.
 *
 * All archives are specific to a site, period & segment.
 *
 * This concept may not be applicable to individual backend implementations of this
 * interface. A backend could only have a single type of archive.
 *
 * **Archive IDs**
 *
 * An archive ID is a value used to identify an "archive".
 *
 * The actual format of the archive ID is determined by the implementation of this
 * interface. The relational store uses integer IDs that correlate to the primary key
 * of the archive tables.
 *
 * The archive ID can be an string, array, or even an object, if desired.
 *
 * **Records & Metrics**
 *
 * There are two types of "archive data" in Piwik: records & metrics.
 *
 * A metric is single value, like "visits" or "actions".
 *
 * A record is a serialized DataTable that is used to generate reports. They differ from
 * reports, in that reports are processed in order to look pretty, while records are
 * optimized for space.
 *
 * **Archiving**
 *
 * The "archiving" process is the process that initiates the aggregation of log data
 * across one or more plugins, then caches the results in the configured archive
 * data store.
 *
 * {@see \Piwik\Plugin\Archiver} for more details
 */
interface ArchiveDataStore
{
    const NUMERIC = 'numeric';
    const BLOB = 'blob';

    /**
     * Queries the data store for all valid archives for the site, period & segment in
     * `$params` that contain data specified by `$dataNames`. This method must return
     * the archive IDs identifying those archives.
     *
     * @param Parameters $params
     * @param string[] $dataNames
     * @return array
     */
    function getArchiveIds(Parameters $params, array $dataNames);

    /**
     * Queries the data store for a single archive & value of the nb_visits & nb_visits_converted
     * metrics in that archive.
     *
     * @param ArchiveProcessorParameters $params Contains the site, period & segment to look for.
     * @param $minDatetimeArchiveProcessedUTC // TODO: is this needed?
     * @return array An array with three elements: the archive ID, the visits & the converted visits.
     */
    function getArchiveIdAndVisits(ArchiveProcessorParameters $params, $minDatetimeArchiveProcessedUTC);

    /**
     * Queries the data store for actual archived data in the given set of archive IDs.
     *
     * @param array $archiveIds The IDs of the archives to get data from.
     * @param string[] $dataNames The metric or record names to get.
     * @param string $archiveDataType either self::NUMERIC or self::BLOB
     * @param int|null|string $idSubtable null if the root blob should be loaded, an integer if a subtable
     *                                    should be loaded and 'all' if all subtables should be loaded.
     * @return array
     */
    function getArchiveData(array $archiveIds, array $dataNames, $archiveDataType, $idSubtable = null);

    /**
     * Sets a numeric value in the store. Whether this method results in inserting new data or updating
     * existing data, is up to the underlying implementation.
     *
     * //TODO: are $params necessary? (same for other insert methods)
     * @param ArchiveProcessorParameters $params Contains the site, period & segment of the archive to
     *                                           insert into.
     * @param mixed $idArchive The ID of the archive to insert this value into.
     * @param string $name The name of the data to set, eg, 'nb_visits'.
     * @param number $value The value to set.
     */
    function setNumericRecord(ArchiveProcessorParameters $params, $idArchive, $name, $value);

    /**
     * Sets a blob value in the store. Whether this method results in inserting new data or updating
     * existing data, is up to the underlying implementation.
     *
     * @param ArchiveProcessorParameters $params Contains the site, period & segment of the archive to
     *                                           insert into.
     * @param mixed $idArchive The ID of the archive to insert this value into.
     * @param string $name The name of the data to set, eg, 'Referrers_getKeyword'.
     * @param string|string[] $values The values (or single value) to set.
     */
    function setBlobRecord(ArchiveProcessorParameters $params, $idArchive, $name, $values);

    /**
     * Initializes a new archive in the data store and returns the ID of the new archive.
     *
     * @param ArchiveProcessorParameters $params Contains the site, period & segment of the archive to
     *                                           start.
     * @return mixed The ID of the new archive.
     */
    function startArchive(ArchiveProcessorParameters $params);

    /**
     * "Finishes" an archive. This is called after archiving for an individual plugin (or all plugins)
     * is done.
     *
     * What "finishing an archive" means is up to the implementation.
     *
     * @param ArchiveProcessorParameters $params Contains the site, period & segment of the archive to
     *                                           finish.
     * @param mixed $idArchive The ID of the archive to finish.
     * @param number $status TODO: archive status might only be for relational databases.
     */
    function finishArchive(ArchiveProcessorParameters $params, $idArchive, $status);

    /**
     * Compares two archive IDs for logical equality.
     *
     * @param mixed $lhsIdArchive
     * @param mixed $rhsIdArchive
     * @return bool
     */
    function areArchiveIdsEqual($lhsIdArchive, $rhsIdArchive);
}
