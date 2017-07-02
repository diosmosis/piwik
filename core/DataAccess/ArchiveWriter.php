<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\DataAccess;

use Exception;
use Piwik\Archive;
use Piwik\Archive\Chunk;
use Piwik\ArchiveProcessor\Rules;
use Piwik\ArchiveProcessor;
use Piwik\Container\StaticContainer;
use Piwik\Db;
use Piwik\Db\BatchInsert;
use Piwik\Period;

/**
 * This class is used to create a new Archive.
 * An Archive is a set of reports (numeric and data tables).
 * New data can be inserted in the archive with insertRecord/insertBulkRecords
 */
class ArchiveWriter
{
    /**
     * Flag stored at the end of the archiving
     *
     * @var int
     */
    const DONE_OK = 1;
    /**
     * Flag stored at the start of the archiving
     * When requesting an Archive, we make sure that non-finished archive are not considered valid
     *
     * @var int
     */
    const DONE_ERROR = 2;
    /**
     * Flag indicates the archive is over a period that is not finished, eg. the current day, current week, etc.
     * Archives flagged will be regularly purged from the DB.
     *
     * @var int
     */
    const DONE_OK_TEMPORARY = 3;

    /**
     * Flag indicated that archive is done but was marked as invalid later and needs to be re-processed during next archiving process
     *
     * @var int
     */
    const DONE_INVALIDATED = 4;

    protected $fields = array('idarchive',
        'idsite',
        'date1',
        'date2',
        'period',
        'ts_archived',
        'name',
        'value');

    public function __construct(ArchiveProcessor\Parameters $params, $isArchiveTemporary)
    {
        $this->params = $params;
        $this->idArchive = false;
        $this->idSite    = $params->getSite()->getId();
        $this->segment   = $params->getSegment();
        $this->period    = $params->getPeriod();

        $idSites = array($this->idSite);
        $this->doneFlag = Rules::getDoneStringFlagFor($idSites, $this->segment, $this->period->getLabel(), $params->getRequestedPlugin());
        $this->isArchiveTemporary = $isArchiveTemporary;

        $this->dateStart = $this->period->getDateStart();

        $this->archiveDataStore = StaticContainer::get(Archive\ArchiveDataStore::class);
    }

    /**
     * @param string $name
     * @param string|string[] $values  A blob string or an array of blob strings. If an array
     *                                 is used, the first element in the array will be inserted
     *                                 with the `$name` name. The others will be splitted into chunks. All subtables
     *                                 within one chunk will be serialized as an array where the index is the
     *                                 subtableId.
     */
    public function insertBlobRecord($name, $values)
    {
        $this->archiveDataStore->setBlobRecord($this->params, $this->getIdArchive(), $name, $values);
    }

    public function getIdArchive()
    {
        if ($this->idArchive === false) {
            throw new Exception("Must call initNewArchive() first");
        }

        return $this->idArchive;
    }

    public function initNewArchive()
    {
        $this->idArchive = $this->archiveDataStore->startArchive($this->params);
    }

    public function finalizeArchive()
    {
        $status = self::DONE_OK;

        if ($this->isArchiveTemporary) {
            $status = self::DONE_OK_TEMPORARY;
        }

        $this->archiveDataStore->finishArchive($this->params, $this->getIdArchive(), $status);
    }

    /**
     * Inserts a numeric record in the numeric table.
     *
     * @param string $name
     * @param mixed $value
     *
     * @return bool
     */
    public function insertRecord($name, $value)
    {
        if ($this->isRecordZero($value)) {
            return false;
        }

        $this->archiveDataStore->setNumericRecord($this->params, $this->getIdArchive(), $name, $value);

        return true;
    }

    protected function getTableNumeric()
    {
        return ArchiveTableCreator::getNumericTable($this->dateStart);
    }

    protected function isRecordZero($value)
    {
        return ($value === '0' || $value === false || $value === 0 || $value === 0.0);
    }
}
