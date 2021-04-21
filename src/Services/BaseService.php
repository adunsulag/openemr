<?php

/**
 * Standard Services Base class
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2020 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\Search\ISearchField;
use OpenEMR\Services\Search\SearchFieldStatementResolver;
use OpenEMR\Validators\ProcessingResult;
use Particle\Validator\Exception\InvalidValueException;
use Psr\Log\LoggerInterface;

require_once(__DIR__  . '/../../custom/code_types.inc.php');

class BaseService
{
    /**
     * Passed in data should be vetted and fully qualified from calling service class
     * Expect to see some search helpers here as well.
     */
    private $table;
    private $fields;
    private $autoIncrements;

    /**
     * @var SystemLogger
     */
    private $logger;

    private const PREFIXES = array(
        'eq' => "=",
        'ne' => "!=",
        'gt' => ">",
        'lt' => "<",
        'ge' => ">=",
        'le' => "<=",
        'sa' => "",
        'eb' => "",
        'ap' => ""
    );

    /**
     * Default constructor.
     */
    public function __construct($table)
    {
        $this->table = $table;
        $this->fields = sqlListFields($table);
        $this->autoIncrements = self::getAutoIncrements($table);
        $this->setLogger(new SystemLogger());
    }

    /**
     * Get the name of our base database table
     *
     * @return mixed
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * Get the fields/column-names on the database table
     *
     * @return array
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * queryFields
     * Build SQL Query for Selecting Fields
     *
     * @param array $map
     * @return array
     */
    public function queryFields($map = null, $data = null)
    {
        if ($data == null || $data == "*" || $data == "all") {
            $value = "*";
        } else {
            $value = implode(", ", $data);
        }
        $sql = "SELECT $value from $this->table";
        return $this->selectHelper($sql, $map);
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * buildInsertColumns
     * Build an insert set and bindings
     *
     * @param array $passed_in
     * @return array
     */
    protected function buildInsertColumns($passed_in = array())
    {
        $keyset = '';
        $bind = array();
        $result = array();

        foreach ($passed_in as $key => $value) {
            // Ensure auto's not passed in.
            if (in_array($key, array_column($this->autoIncrements, 'Field'))) {
                continue;
            }
            // include existing columns
            if (!in_array($key, $this->fields)) {
                continue;
            }
            if ($value == 'YYYY-MM-DD' || $value == 'MM/DD/YYYY') {
                $value = "";
            }
            if ($value === null || $value === false) {
                $value = "";
            }
            $keyset .= ($keyset) ? ", `$key` = ? " : "`$key` = ? ";
            $bind[] = ($value === null || $value === false) ? '' : $value;
        }

        $result['set'] = $keyset;
        $result['bind'] = $bind;

        return $result;
    }

    /**
     * buildUpdateColumns
     * Build an update set and bindings
     *
     * @param array $passed_in
     * @return array
     */
    protected function buildUpdateColumns($passed_in = array())
    {
        $keyset = '';
        $bind = array();
        $result = array();

        foreach ($passed_in as $key => $value) {
            if (in_array($key, array_column($this->autoIncrements, 'Field'))) {
                continue;
            }
            // exclude uuid columns
            if ($key == 'uuid') {
                continue;
            }
            // exclude pid columns
            if ($key == 'pid') {
                continue;
            }
            if (!in_array($key, $this->fields)) {
                // placeholder. could be for where clauses
                $bind[] = ($value == 'NULL') ? "" : $value;
                continue;
            }
            if ($value == 'YYYY-MM-DD' || $value == 'MM/DD/YYYY') {
                $value = "";
            }
            if ($value === null || $value === false) {
                // in case unwanted values passed in.
                continue;
            }
            $keyset .= ($keyset) ? ", `$key` = ? " : "`$key` = ? ";
            $bind[] = ($value == 'NULL') ? "" : $value;
        }

        $result['set'] = $keyset;
        $result['bind'] = $bind;

        return $result;
    }

    /**
     * @param $table
     * @return array
     */
    private static function getAutoIncrements($table)
    {
        $results = array();
        $rtn = sqlStatementNoLog(
            "SHOW COLUMNS FROM $table Where extra Like ?",
            array('%auto_increment%')
        );
        while ($row = sqlFetchArray($rtn)) {
            array_push($results, $row);
        }

        return $results;
    }

    /**
     * Shared getter for SQL selects.
     * Shared from original OpenEMR\Common\Utils\QueryUtils
     *
     * @param $sqlUpToFromStatement - The sql string up to (and including) the FROM line.
     * @param $map                  - Query information (where clause(s), join clause(s), order, data, etc).
     * @return array of associative arrays | one associative array.
     */
    public static function selectHelper($sqlUpToFromStatement, $map)
    {
        $where = isset($map["where"]) ? $map["where"] : null;
        $data = isset($map["data"]) ? $map["data"] : null;
        $join = isset($map["join"]) ? $map["join"] : null;
        $order = isset($map["order"]) ? $map["order"] : null;
        $limit = isset($map["limit"]) ? $map["limit"] : null;

        $sql = $sqlUpToFromStatement;

        $sql .= !empty($join) ? " " . $join : "";
        $sql .= !empty($where) ? " " . $where : "";
        $sql .= !empty($order) ? " " . $order : "";
        $sql .= !empty($limit) ? " LIMIT " . $limit : "";

        if (!empty($data)) {
            if (empty($limit) || $limit > 1) {
                $multipleResults = sqlStatement($sql, $data);
                $results = array();

                while ($row = sqlFetchArray($multipleResults)) {
                    array_push($results, $row);
                }

                return $results;
            }

            return sqlQuery($sql, $data);
        }

        if (empty($limit) || $limit > 1) {
            $multipleResults = sqlStatement($sql);
            $results = array();

            while ($row = sqlFetchArray($multipleResults)) {
                array_push($results, $row);
            }

            return $results;
        }

        return sqlQuery($sql);
    }

    /**
     * Build and Throw Invalid Value Exception
     *
     * @param $message              - The error message which will be displayed
     * @param $type                 - Type of Exception
     * @throws InvalidValueException
     */
    public static function throwException($message, $type = "Error")
    {
        throw new InvalidValueException($message, $type);
    }

    // Taken from -> https://stackoverflow.com/a/24401462
    /**
     * Validate Date and Time
     *
     * @param $dateString              - The Date string which is to be verified
     * @return bool
     */
    public static function isValidDate($dateString)
    {
        return (bool) strtotime($dateString);
    }

    /**
     * Check and Return SQl (AND | OR) Operators
     *
     * @param $condition              - Boolean to check AND | OR
     * @return string of (AND | OR) Operator
     */
    public static function sqlCondition($condition)
    {
        return (string) $condition ? ' AND ' : ' OR ';
    }


    /**
     * Fetch ID by UUID of Resource
     *
     * @param string $uuid              - UUID of Resource
     * @param string $table             - Table reffering to the ID field
     * @param string $field             - Identifier field
     * @return false if nothing found otherwise return ID
     */
    public static function getIdByUuid($uuid, $table, $field)
    {
        $sql = "SELECT $field from $table WHERE uuid = ?";
        $result = sqlQuery($sql, array($uuid));
        return $result[$field] ?? false;
    }

    /**
     * Fetch UUID by ID of Resource
     *
     * @param string $id                - ID of Resource
     * @param string $table             - Table reffering to the UUID field
     * @param string $field             - Identifier field
     * @return false if nothing found otherwise return UUID
     */
    public static function getUuidById($id, $table, $field)
    {
        $table = escape_table_name($table);
        $sql = "SELECT uuid from $table WHERE $field = ?";
        $result = sqlQuery($sql, array($id));
        return $result['uuid'] ?? false;
    }

    /**
     * Process DateTime as per FHIR Standard
     *
     * @param string $date             - DateTime String
     * @return array processed prefix with value
     */
    public static function processDateTime($date)
    {
        $processedDate = array();
        $result = substr($date, 0, 2);

        // Assign Default
        $processedDate['prefix'] = self::PREFIXES['eq'];
        $processedDate['value'] = $date;

        foreach (self::PREFIXES as $prefix => $value) {
            if ($prefix == $result) {
                $date = substr($date, 2);
                $processedDate['prefix'] = $value;
                $processedDate['value'] = $date;
                return $processedDate;
            }
        }

        return $processedDate;
    }

    /**
     * Generates New Primary Id
     *
     * @param string $idField                   - Name of Primary Id Field
     * @param string $table                     - Name of Table
     * @return string Generated Id
     */
    public function getFreshId($idField, $table)
    {
        $resultId = sqlQuery("SELECT MAX($idField)+1 AS $idField FROM $table");
        return $resultId[$idField] === null ? 1 : intval($resultId[$idField]);
    }

    /**
     * Filter all the Whitelisted Fields from the given Fields Array
     *
     * @param array $data                       - Fields passed by user
     * @param array $whitelistedFields          - Whitelisted Fields
     * @return array Filtered Data
     */
    public function filterData($data, $whitelistedFields)
    {
        return array_filter(
            $data,
            function ($key) use ($whitelistedFields) {
                return in_array($key, $whitelistedFields);
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Returns a list of patients matching the search criteria.
     * Search criteria is conveyed by array where key = field/column name, value is an ISearchField
     * If an empty array of search criteria is provided, all records are returned.
     *
     * The search will grab the intersection of all possible values if $isAndCondition is true, otherwise it returns
     * the union (logical OR) of the search.
     *
     * More complicated searches with various sub unions / intersections can be accomplished through a CompositeSearchField
     * that allows you to combine multiple search clauses on a single search field.
     *
     * @param ISearchField[] $search Hashmap of string => ISearchField where the key is the field name of the search field
     * @param bool $isAndCondition Whether to join each search field with a logical OR or a logical AND.
     * @return ProcessingResult The results of the search.
     */
    public function search($search, $isAndCondition = true)
    {
        $sqlBindArray = array();

        $selectFields = $this->getFields();

        $selectFields = array_combine($selectFields, $selectFields); // make it a dictionary so we can add/remove this.
        $from = [$this->getTable()];

        $sql = "SELECT " . implode(",", array_keys($selectFields)) . " FROM " . implode(",", $from);

        $whereClauses = array(
            'and' => []
        ,'or' => []
        );

        if (!empty($search)) {
            // make sure all the parameters are actual search fields and clean up any field that is a uuid
            foreach ($search as $key => $field) {
                if (!$field instanceof ISearchField) {
                    throw new \InvalidArgumentException("Method called with invalid parameter.  Expected SearchField object for parameter '" . $key . "'");
                }
                $whereType = $isAndCondition ? "and" : "or";

                $whereClauses[$whereType][] = SearchFieldStatementResolver::getStatementForSearchField($field);
            }
        }

        if (!(empty($whereClauses['or']) && empty($whereClauses['and']))) {
            $sql .= " WHERE ";
            $andClauses = [];
            foreach ($whereClauses['and'] as $clause) {
                $andClauses[] = $clause->getFragment();
                $sqlBindArray = array_merge($sqlBindArray, $clause->getBoundValues());
            }
            $sql = empty($andClauses) ? $sql : $sql . implode(" AND ", $andClauses);

            $orClauses = [];
            foreach ($whereClauses['or'] as $clause) {
                $orClauses[] = $clause->getFragment();
                $sqlBindArray = array_merge($sqlBindArray, $clause->getBoundValues());
            }
            $sql = empty($orClauses) ? $sql : $sql . "(" . implode(" OR ", $orClauses) . ")";
        }

//        $this->getLogger()->debug("Executing search sql statement ", ['sql' => $sql, 'bindArray' => $sqlBindArray]);

        $statementResults = sqlStatementThrowException($sql, $sqlBindArray);

        $processingResult = new ProcessingResult();
        while ($row = sqlFetchArray($statementResults)) {
            $row['uuid'] = UuidRegistry::uuidToString($row['uuid']);
            $processingResult->addData($row);
        }

        return $processingResult;
    }

    /**
     * Convert Diagnosis Codes String to Code:Description Array
     *
     * @param string $diagnosis                 - All Diagnosis Codes
     * @return array Array of Code as Key and Description as Value
     */
    protected function addCoding($diagnosis)
    {
        $diags = explode(";", $diagnosis);
        $diagnosis = array();
        foreach ($diags as $diag) {
            $codedesc = lookup_code_descriptions($diag);
            $code = explode(':', $diag)[1];
            $diagnosis[$code] = $codedesc;
        }
        return $diagnosis;
    }

    /**
     * Split IDs and Process the fields subsequently
     *
     * @param string $fields                    - All IDs sperated with | sign
     * @param string $table                     - Name of the table of targeted ID
     * @param string $primaryId                 - Name of Primary ID field
     * @return array Array UUIDs
     */
    protected function splitAndProcessMultipleFields($fields, $table, $primaryId = "id")
    {
        $fields = explode("|", $fields);
        $result = array();
        foreach ($fields as $field) {
            $data = sqlQuery("SELECT uuid
                    FROM $table WHERE $primaryId = ?", array($field));
            if ($data) {
                array_push($result, UuidRegistry::uuidToString($data['uuid']));
            }
        }
        return $result;
    }
}
