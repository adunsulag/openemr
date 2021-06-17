<?php

/**
 * UuidRegistry class
 *
 *    Generic support for UUID creation and use. Goal is to support:
 *     1. uuid for fhir resources
 *     2. uuid for future offsite support (using Timestamp-first COMB Codec for uuid,
 *        so can use for primary keys)
 *     3. uuid for couchdb docid
 *     4. other future use cases.
 *    The construct accepts an associative array in order to allow easy addition of
 *    fields and new sql columns in the uuid_registry table.
 *
 *    When add support for a new table uuid, need to add it to the populateAllMissingUuids function.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Gerhard Brink <gjdbrink@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Gerhard Brink <gjdbrink@gmail.com>
 * @copyright Copyright (c) 2020 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Common\Uuid;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use Ramsey\Uuid\Codec\TimestampFirstCombCodec;
use Ramsey\Uuid\Generator\CombGenerator;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\Uuid;

class UuidRegistry
{

    // Maximum tries to create a unique uuid before failing (this should never happen)
    const MAX_TRIES = 100;
    const UUID_MAX_BATCH_COUNT = 1000;

    private $table_name;      // table to check if uuid has already been used in
    private $table_id;        // the label of the column in above table that is used for id (defaults to 'id')
    private $table_vertical;  // false or array. if table is vertical, will store the critical columns (uuid set for matching columns)
    private $disable_tracker; // disable check and storage of uuid in the main uuid_registry table
    private $couchdb;         // blank or string (documents or ccda label for now, which represents the tables that hold the doc ids).
    private $document_drive;  // set to true if this is for labeling a document saved to drive
    private $mapped;          // set to true if this was mapped in uuid_mapping table

    public function __construct($associations = [])
    {
        $this->table_name = $associations['table_name'] ?? '';
        if (!empty($this->table_name)) {
            $this->table_id = $associations['table_id'] ?? 'id';
        } else {
            $this->table_id = '';
        }
        $this->table_vertical = $associations['table_vertical'] ?? false;
        $this->disable_tracker = $associations['disable_tracker'] ?? false;
        $this->couchdb = $associations['couchdb'] ?? '';
        if (!empty($associations['document_drive']) && $associations['document_drive'] === true) {
            $this->document_drive = 1;
        } else {
            $this->document_drive = 0;
        }
        if (!empty($associations['mapped']) && $associations['mapped'] === true) {
            $this->mapped = 1;
        } else {
            $this->mapped = 0;
        }
    }

    /**
     * @return string
     */
    public function createUuid()
    {
        $isUnique = false;
        $i = 0;
        while (!$isUnique) {
            $i++;
            if ($i > 1) {
                // There was a uuid creation collision, so need to try again.
                error_log("OpenEMR Warning: There was a collision when creating a unique UUID. This is try number " . $i . ". Will try again.");
            }
            if ($i > self::MAX_TRIES) {
                // This error should never happen. If so, then the random generation of the
                //  OS is compromised and no use continuing to run OpenEMR.
                error_log("OpenEMR Error: Unable to create a unique UUID");
                exit;
            }

            // Create uuid using the Timestamp-first COMB Codec, so can use for primary keys
            //  (since first part is timestamp, it is naturally ordered; the rest is from uuid4, so is random)
            //  reference:
            //    https://uuid.ramsey.dev/en/latest/customize/timestamp-first-comb-codec.html#customize-timestamp-first-comb-codec
            $factory = new UuidFactory();
            $codec = new TimestampFirstCombCodec($factory->getUuidBuilder());
            $factory->setCodec($codec);
            $factory->setRandomGenerator(new CombGenerator(
                $factory->getRandomGenerator(),
                $factory->getNumberConverter()
            ));
            $timestampFirstComb = $factory->uuid4();
            $uuid = $timestampFirstComb->getBytes();

            /** temp debug stuff
            error_log(bin2hex($uuid)); // log hex uuid
            error_log(bin2hex($timestampFirstComb->getBytes())); // log hex uuid
            error_log($timestampFirstComb->toString()); // log string uuid
            $test_uuid = (\Ramsey\Uuid\Uuid::fromBytes($uuid))->toString(); // convert byte uuid to string and log below
            error_log($test_uuid);
            error_log(bin2hex((\Ramsey\Uuid\Uuid::fromString($test_uuid))->getBytes())); // convert string uuid to byte and log hex
             */

            // Check to ensure uuid is unique in uuid_registry (unless $this->disable_tracker is set to true)
            if (!$this->disable_tracker) {
                $checkUniqueRegistry = sqlQueryNoLog("SELECT * FROM `uuid_registry` WHERE `uuid` = ?", [$uuid]);
            }
            if (empty($checkUniqueRegistry)) {
                if (!empty($this->table_name)) {
                    // If using $this->table_name, then ensure uuid is unique in that table
                    $checkUniqueTable = sqlQueryNoLog("SELECT * FROM `" . $this->table_name . "` WHERE `uuid` = ?", [$uuid]);
                    if (empty($checkUniqueTable)) {
                        $isUnique = true;
                    }
                } elseif ($this->document_drive === 1) {
                    // If using for document labeling on drive, then ensure drive_uuid is unique in documents table
                    $checkUniqueTable = sqlQueryNoLog("SELECT * FROM `documents` WHERE `drive_uuid` = ?", [$uuid]);
                    if (empty($checkUniqueTable)) {
                        $isUnique = true;
                    }
                } else {
                    $isUnique = true;
                }
            }
        }

        // Insert the uuid into uuid_registry (unless $this->disable_tracker is set to true)
        if (!$this->disable_tracker) {
            if (!$this->table_vertical) {
                sqlQueryNoLog("INSERT INTO `uuid_registry` (`uuid`, `table_name`, `table_id`, `couchdb`, `document_drive`, `mapped`, `created`) VALUES (?, ?, ?, ?, ?, ?, NOW())", [$uuid, $this->table_name, $this->table_id, $this->couchdb, $this->document_drive, $this->mapped]);
            } else {
                sqlQueryNoLog("INSERT INTO `uuid_registry` (`uuid`, `table_name`, `table_id`, `table_vertical`, `couchdb`, `document_drive`, `mapped`, `created`) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())", [$uuid, $this->table_name, $this->table_id, json_encode($this->table_vertical), $this->couchdb, $this->document_drive, $this->mapped]);
            }
        }

        // Return the uuid
        return $uuid;
    }

    // Generic function to update all missing uuids (to be primarily used in service that is run intermittently; should not use anywhere else)
    // When add support for a new table uuid, need to add it here
    //  Will log by default
    public static function populateAllMissingUuids($log = true)
    {
        $logEntryComment = '';

        // Update for tables (alphabetically ordered):
        //  ccda
        //  drugs (with custom id drug_id)
        //  facility
        //  facility_user_ids (vertical table)
        //  form_encounter
        //  immunizations
        //  insurance_companies
        //  insurance_data
        //  lists
        //  patient_data
        //  prescriptions
        //  procedure_order (with custom id procedure_order_id)
        //  procedure_result (with custom id procedure_result_id)
        //  users
        self::appendPopulateLog('ccda', (new UuidRegistry(['table_name' => 'ccda']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('drugs', (new UuidRegistry(['table_name' => 'drugs', 'table_id' => 'drug_id']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('facility', (new UuidRegistry(['table_name' => 'facility']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('facility_user_ids', (new UuidRegistry(['table_name' => 'facility_user_ids', 'table_vertical' => ['uid', 'facility_id']]))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('form_encounter', (new UuidRegistry(['table_name' => 'form_encounter']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('immunizations', (new UuidRegistry(['table_name' => 'immunizations']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('insurance_companies', (new UuidRegistry(['table_name' => 'insurance_companies']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('insurance_data', (new UuidRegistry(['table_name' => 'insurance_data']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('lists', (new UuidRegistry(['table_name' => 'lists']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('patient_data', (new UuidRegistry(['table_name' => 'patient_data']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('prescriptions', (new UuidRegistry(['table_name' => 'prescriptions']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('procedure_order', (new UuidRegistry(['table_name' => 'procedure_order', 'table_id' => 'procedure_order_id']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('procedure_result', (new UuidRegistry(['table_name' => 'procedure_result', 'table_id' => 'procedure_result_id']))->createMissingUuids(), $logEntryComment);
        self::appendPopulateLog('users', (new UuidRegistry(['table_name' => 'users']))->createMissingUuids(), $logEntryComment);

        // log it
        if ($log && !empty($logEntryComment)) {
            $logEntryComment = rtrim($logEntryComment, ', ');
            EventAuditLogger::instance()->newEvent('uuid', '', '', 1, 'Automatic uuid service creation: ' . $logEntryComment);
        }
    }

    // Helper function for above populateAllMissingUuids function
    private static function appendPopulateLog($table, $count, &$logEntry)
    {
        if ($count > 0) {
            $logEntry .= 'added ' . $count . ' uuids to ' . $table . ', ';
        }
    }

    public function createMissingUuids()
    {
        try {
            sqlBeginTrans();
            $counter = 0;

            // we split the loop so we aren't doing a condition inside each one.
            if ($this->table_vertical) {
                do {
                    $count = $this->createMissingUuidsForVerticalTable();
                    $counter += $count;
                } while ($count > 0);
            } else {
                do {
                    $count = $this->createMissingUuidsForTableWithId();
                    $counter += $count;
                } while ($count > 0);
            }
            sqlCommitTrans();
        } catch (Exception $exception) {
            sqlRollbackTrans();
            throw $exception;
        }
    }


    // Generic function to see if there are missing uuids in a sql table (table needs an `id` column to work)
    public function tableNeedsUuidCreation()
    {
        // Empty should be NULL, but to be safe also checking for empty and null bytes
        $resultSet = sqlQueryNoLog("SELECT count(`" . $this->table_id . "`) as `total` FROM `" . $this->table_name . "` WHERE `uuid` IS NULL OR `uuid` = '' OR `uuid` = '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'");
        if ($resultSet['total'] > 0) {
            return true;
        }
        return false;
    }

    /**
     * Converts a UUID byte value to a string representation
     * @return the UUID string value
     */
    public static function uuidToString($uuidBytes)
    {
        return Uuid::fromBytes($uuidBytes)->toString();
    }

    /**
     * Converts a UUID string to a bytes representation
     * @return the UUID bytes value
     */
    public static function uuidToBytes($uuidString)
    {
        return Uuid::fromString($uuidString)->getBytes();
    }

    /**
     * Check if UUID String is Valid
     * @return boolean
     */
    public static function isValidStringUUID($uuidString)
    {
        return (Uuid::isValid($uuidString));
    }

    /**
     * Check if UUID Brinary is Empty
     * @return boolean
     */
    public static function isEmptyBinaryUUID($uuidString)
    {
        return (empty($uuidString) || ($uuidString == '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'));
    }

    /**
     * Returns a batch of generated uuids that do NOT exist in the system.  The number of records generated is determined
     * by the limit.
     * @param int $limit
     * @return array
     */
    public function getUnusedUuidBatch($limit = 10)
    {
        if ($limit <= 0) {
            return [];
        }
        $uuids = $this->getUUIDBatch($limit);
        $dbUUIDs = [];

        if (!$this->disable_tracker) {
            $sqlColumns = array_map(function ($u) {
                return '`uuid` = ?';
            }, $uuids);
            $sqlWhere = implode(" OR ", $sqlColumns);
            $dbUUIDs = QueryUtils::fetchRecordsNoLog("SELECT `uuid` FROM `uuid_registry` WHERE " . $sqlWhere, $uuids);
        }
        if (empty($dbUUIDs)) {
            if (!empty($this->table_name)) {
                $sqlColumns = array_map(function ($u) {
                    return '`uuid` = ?';
                }, $uuids);
                $sqlWhere = implode(" OR ", $sqlColumns);
                // If using $this->table_name, then ensure uuid is unique in that table
                $dbUUIDs =  QueryUtils::fetchRecordsNoLog("SELECT `uuid` FROM `" . $this->table_name . "` WHERE " . $sqlWhere, $uuids);
            } elseif ($this->document_drive === 1) {
                $sqlColumns = array_map(function ($u) {
                    return '`uuid` = ?';
                }, $uuids);
                $sqlWhere = implode(" OR ", $sqlColumns);
                // If using for document labeling on drive, then ensure drive_uuid is unique in documents table
                $dbUUIDs = QueryUtils::fetchRecordsNoLog("SELECT `drive_uuid` FROM `documents` WHERE " . $sqlWhere, $uuids);
            }
        }

        $count = count($dbUUIDs);

        if ($count <= 0) {
            return $uuids;
        }
        $newGenLimit = $limit;
        if ($count < $limit) {
            $newGenLimit = $limit - $count;
        }
        // generate some new uuids since we had duplicates... which should never happen... but we have this here in
        // case we do
        $outstanding = $this->getUnusedUuidBatch($newGenLimit);
        return array_merge($dbUUIDs, $outstanding);
    }

    private function createMissingUuidsForTableWithId()
    {
        $counter = 0;
        $count = $this->getTableCountWithMissingUuids();
        if ($count > 0) {
            // loop through in batches of 1000
            // generate min(1000, $count)
            // generate bulk insert statement
            $gen_count = min($count, self::UUID_MAX_BATCH_COUNT);
            $batchUUids = $this->getUnusedUuidBatch($gen_count);
            $ids = QueryUtils::fetchRecords("SELECT " . $this->table_id . " FROM `" . $this->table_name . "` WHERE `uuid` IS NULL OR `uuid` = '' OR `uuid` = '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0' LIMIT " . $gen_count);
            $this->insertUuidsIntoRegistry($batchUUids);
            for ($i = 0; $i < $gen_count; $i++) {
                // do single updates
                sqlStatementNoLog("UPDATE `" . $this->table_name . "` SET `uuid` = ? WHERE `" . $this->table_id . "` = ?", [$batchUUids[$i], $ids[$i][$this->table_id]]);
                $counter++;
            }
        }
        return $counter;
    }

    private function getTableCountWithMissingUuids()
    {
        $result = QueryUtils::fetchRecordsNoLog("SELECT count(*) AS cnt FROM `" . $this->table_name . "` WHERE `uuid` IS NULL OR `uuid` = '' OR `uuid` = '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0'", []);
        // loop through batches of 1000
        $count = $result[0]['cnt'];
        return $count;
    }

    /**
     * Populates any missing uuids for a table that has no single unique column and instead uses a composite key to
     * represent the table uniqueness.
     * @return int
     */
    private function createMissingUuidsForVerticalTable()
    {
        $counter = 0;
        $count = $this->getTableCountWithMissingUuids();
        if ($count > 0) {
            // grab the records
            $escapedColumns = array_map(function ($col) {
                return "`$col`";
            }, array_merge(['uuid'], $this->table_vertical));
            $gen_count = min($count, self::UUID_MAX_BATCH_COUNT);
            $sqlFetch = "SELECT " . implode(",", $escapedColumns) . " FROM `" . $this->table_name
                . "` WHERE `uuid` IS NULL OR `uuid` = '' OR `uuid` = '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0' LIMIT "
                . $gen_count;
            $batchUUids = $this->getUnusedUuidBatch($gen_count);
            $results = QueryUtils::fetchRecordsNoLog($sqlFetch, []);
            // these absolutely should match but we will use the minimum of the results just to be safe and not exceed
            // our array bounds
            $resultCount = count($results);
            $sqlUpdate = "UPDATE `" . $this->table_name . "` SET `uuid` = ? WHERE " .
                implode(" AND ", array_map(function ($col) {
                    return "`$col` = ? ";
                }, $this->table_vertical));

            // simpler to go functional then a bunch of nested loops, drops down to c also which will be more performant
            // would like to drop the anonymous function, but we'll have to benchmark it to see the diff.
            $mapper = function ($i, &$results, &$columns) {
                return array_map(function ($col) use ($i, &$results) {
                    return $results[$i][$col];
                }, $columns);
            };
            for ($i = 0; $i < $resultCount; $i++) {
                $mappedValues = $mapper($i, $results, $this->table_vertical);
                $bindValues = array_merge([$batchUUids[$i]], $mappedValues);
                sqlStatementNoLog($sqlUpdate, $bindValues, true);
                $counter++;
            }
        }
        return $counter;
    }

    /**
     * Given a batch of UUIDs it inserts them into the uuid registry.
     * @param $batchUuids
     */
    public function insertUuidsIntoRegistry(&$batchUuids)
    {
        $count = count($batchUuids);
        $sql = "INSERT INTO `uuid_registry` (`uuid`, `table_name`, `table_id`, `table_vertical`, `couchdb`, `document_drive`, `mapped`, `created`) VALUES ";
        $columns = [];
        $bind = [];
        $json_vertical = !empty($this->table_vertical) ? json_encode($this->table_vertical) : "";
        for ($i = 0; $i < $count; $i++) {
            $columns[] = "(?, ?, ?, ?, ?, ?, ?, NOW())";
            $bind[] = $batchUuids[$i];
            $bind[] = $this->table_name;
            $bind[] = $this->table_id;
            $bind[] = $json_vertical;
            $bind[] = $this->couchdb;
            $bind[] = $this->document_drive;
            $bind[] = $this->mapped;
        }
        $sql .= implode(",", $columns);
        QueryUtils::sqlStatementThrowException($sql, $bind);
    }


    /**
     * Returns an array of generated unique universal identifiers up to the passed in limit.
     * @param int $limit
     * @return array
     */
    private function getUUIDBatch($limit = 10)
    {
        $uuids = [];
        // Create uuid using the Timestamp-first COMB Codec, so can use for primary keys
        //  (since first part is timestamp, it is naturally ordered; the rest is from uuid4, so is random)
        //  reference:
        //    https://uuid.ramsey.dev/en/latest/customize/timestamp-first-comb-codec.html#customize-timestamp-first-comb-codec
        $factory = new UuidFactory();
        $codec = new TimestampFirstCombCodec($factory->getUuidBuilder());
        $factory->setCodec($codec);
        $factory->setRandomGenerator(new CombGenerator(
            $factory->getRandomGenerator(),
            $factory->getNumberConverter()
        ));
        for ($i = 0; $i < $limit; $i++) {
            $timestampFirstComb = $factory->uuid4();
            $uuids[] = $timestampFirstComb->getBytes();
        }
        return $uuids;
    }
}
