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


use Piwik\Archive;
use Piwik\ArchiveProcessor\Parameters as ArchiveProcessorParameters;
use Piwik\ArchiveProcessor\Rules;
use Piwik\DataAccess\ArchiveSelector;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\DataAccess\Model;
use Piwik\Db;
use Piwik\Db\BatchInsert;

class RelationalArchiveDataStore implements ArchiveDataStore
{
    public function getArchiveIds(Parameters $params, array $dataNames)
    {
        $plugins = array_map([Archive::class, 'getPluginForReport'], $dataNames);
        $plugins = array_unique($plugins);

        // TODO: if the archives already exist in the cache, we don't need to re-query
        return ArchiveSelector::getArchiveIds(
            $params->getIdSites(), $params->getPeriods(), $params->getSegment(), $plugins);
    }

    public function getArchiveIdAndVisits(ArchiveProcessorParameters $params, $minDatetimeArchiveProcessedUTC)
    {
        return ArchiveSelector::getArchiveIdAndVisits($params, $minDatetimeArchiveProcessedUTC);
    }

    public function getArchiveData(array $archiveIds, array $dataNames, $archiveDataType, $idSubtable = null)
    {
        return ArchiveSelector::getArchiveData($archiveIds, $dataNames, $archiveDataType, $idSubtable);
    }

    public function setNumericRecord(ArchiveProcessorParameters $params, $idArchive, $name, $value)
    {
        $tableName = ArchiveTableCreator::getNumericTable($params->getPeriod()->getDateStart());
        $fields = $this->getInsertFields();
        $record = $this->getInsertRecordBind($params, $idArchive);

        $this->getModel()->insertRecord($tableName, $fields, $record, $name, $value);
    }

    public function setBlobRecord(ArchiveProcessorParameters $params, $idArchive, $name, $values)
    {
        if (is_array($values)) {
            $clean = array();

            if (isset($values[0])) {
                // we always store the root table in a single blob for fast access
                $clean[] = array($name, $this->compress($values[0]));
                unset($values[0]);
            }

            if (!empty($values)) {
                // we move all subtables into chunks
                $chunk  = new Chunk();
                $chunks = $chunk->moveArchiveBlobsIntoChunks($name, $values);
                foreach ($chunks as $index => $subtables) {
                    $clean[] = array($index, $this->compress(serialize($subtables)));
                }
            }

            $this->insertBulkBlobRecords($params, $idArchive, $clean);
            return;
        }

        $values = $this->compress($values);
        $this->insertSingleBlobRecord($params, $idArchive, $name, $values);
    }

    public function startArchive(ArchiveProcessorParameters $params)
    {
        $numericTable = ArchiveTableCreator::getNumericTable($params->getPeriod()->getDateStart());

        $idArchive = $this->getModel()->allocateNewArchiveId($numericTable);
        $this->logArchiveStatusAsIncomplete($params, $idArchive);
        return $idArchive;
    }

    public function finishArchive(ArchiveProcessorParameters $params, $idArchive, $status)
    {
        $idSite = $params->getSite()->getId();
        $period = $params->getPeriod();

        $numericTable = ArchiveTableCreator::getNumericTable($period->getDateStart());
        $doneFlag = Rules::getDoneStringFlagFor([$idSite], $params->getSegment(), $period->getLabel(),
            $params->getRequestedPlugin());

        $this->getModel()->deletePreviousArchiveStatus($numericTable, $idArchive, $doneFlag);

        $this->setNumericRecord($params, $idArchive, $doneFlag, $status);
    }

    public function areArchiveIdsEqual($lhsIdArchive, $rhsIdArchive)
    {
        return $lhsIdArchive == $rhsIdArchive;
    }

    protected function logArchiveStatusAsIncomplete(ArchiveProcessorParameters $params, $idArchive)
    {
        // TODO: constants belong here
        $doneFlag = Rules::getDoneStringFlagFor([$params->getSite()->getId()], $params->getSegment(),
            $params->getPeriod()->getLabel(), $params->getRequestedPlugin());

        $this->setNumericRecord($params, $idArchive, $doneFlag, ArchiveWriter::DONE_ERROR);
    }

    private function insertSingleBlobRecord(ArchiveProcessorParameters $params, $idArchive, $name, $values)
    {
        $tableName = ArchiveTableCreator::getBlobTable($params->getPeriod()->getDateStart());
        $fields = $this->getInsertFields();
        $record = $this->getInsertRecordBind($params, $idArchive);

        $this->getModel()->insertRecord($tableName, $fields, $record, $name, $values);
    }

    protected function insertBulkBlobRecords(ArchiveProcessorParameters $params, $idArchive, $records)
    {
        // Using standard plain INSERT if there is only one record to insert
        if ($DEBUG_DO_NOT_USE_BULK_INSERT = false
            || count($records) == 1
        ) {
            foreach ($records as $record) {
                $this->insertSingleBlobRecord($params, $idArchive, $record[0], $record[1]);
            }

            return true;
        }

        $bindSql = $this->getInsertRecordBind($params, $idArchive);
        $values  = array();

        foreach ($records as $record) {
            // don't record zero
            if (empty($record[1])) {
                continue;
            }

            $bind     = $bindSql;
            $bind[]   = $record[0]; // name
            $bind[]   = $record[1]; // value
            $values[] = $bind;
        }

        if (empty($values)) {
            return true;
        }

        $tableName = ArchiveTableCreator::getBlobTable($params->getPeriod()->getDateStart());
        $fields    = $this->getInsertFields();

        BatchInsert::tableInsertBatch($tableName, $fields, $values, $throwException = false, $charset = 'latin1');

        return true;
    }

    private function getInsertFields()
    {
        return [
            'idarchive',
            'idsite',
            'date1',
            'date2',
            'period',
            'ts_archived',
            'name',
            'value',
        ];
    }

    private function getInsertRecordBind(ArchiveProcessorParameters $params, $idArchive)
    {
        $period = $params->getPeriod();
        return [
            $idArchive,
            $params->getSite()->getId(),
            $period->getDateStart()->toString('Y-m-d'),
            $period->getDateEnd()->toString('Y-m-d'),
            $period->getId(),
            date("Y-m-d H:i:s"),
        ];
    }

    private function getModel()
    {
        return new Model(); // TODO: do we need this class & the model? or should the methods used in Model be in this class?
    }

    protected function compress($data)
    {
        if (Db::get()->hasBlobDataType()) {
            return gzcompress($data);
        }

        return $data;
    }
}
