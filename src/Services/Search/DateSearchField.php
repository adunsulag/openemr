<?php

/**
 * DateSearchField.php  Holds the DateSearchField class which is represents a date/datetime search field on a piece of
 * data contained in the OpenEMR system.  The search field will take in an array of values that are in the ISO8601 format
 * and parse them.  Fuzzy matching is supporting from left to right matching (in order of specificity ie a fuzzy search on
 * month must be preceeded by year).  If a time component is specified both hours and minutes are required with seconds
 * being optional.  Currently Timezone parsing is not supported.
 *
 * In order to support fuzzy matching (equality, greater than, greater than or equal to, etc) each value being searched
 * on is converted into a DatePeriod with a start and end date component.
 *
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\Search;

use OpenEMR\Services\Search\SearchFieldType;

class DateSearchField extends BasicSearchField
{
    /**
     * The field being searched is a date (year, month, day) field
     */
    const DATE_TYPE_DATE = 'date';

    /**
     * The field being searched on has both a date component as well as a time (Hour, minute, second) component.
     */
    const DATE_TYPE_DATETIME = 'datetime';

    // This regex pattern was adopted from the Asymmetrik/node-fhir-server-mongo project.  It can be seen here
    // https://github.com/Asymmetrik/node-fhir-server-mongo/blob/104037de07a1a1a49adb3de45beb1ae1283f67bb/src/utils/querybuilder.util.js#L273
    // This line is licensed under the MIT license and was last accessed on April 20th 2021
    private const COMPARATOR_MATCH = "/^(\D{2})?(\d{4})(-\d{2})?(-\d{2})?(?:(T\d{2}:\d{2})(:\d{2})?)?(Z|(\+|-)(\d{2}):(\d{2}))?$/";

    /**
     * The different types of dates that are available
     */
    const DATE_TYPES = [self::DATE_TYPE_DATE, self::DATE_TYPE_DATETIME];

    /**
     * @var Tracks the type of search date this is.  Must be a value contined in the DATE_TYPES constant
     */
    private $dateType;

    /**
     * DateSearchField constructor.  Constructs the object and parses all values into valid SearchFieldComparableValue objects
     * that can be used by OpenEMR services to perform searches.
     * @param $field
     * @param $values
     * @param string $dateType
     * @param bool $isAnd
     */
    public function __construct($field, $values, $dateType = self::DATE_TYPE_DATETIME, $isAnd = true)
    {
        $this->setDateType($dateType);

        $modifier = null;
        parent::__construct($field, SearchFieldType::DATE, $field, $values, $modifier, $isAnd);
    }

    public function getDateType()
    {
        return $this->dateType;
    }

    public function setDateType($dateType)
    {
        if (array_search($dateType, self::DATE_TYPES) === false) {
            throw new \InvalidArgumentException("Invalid date type found '$dateType'");
        }
        $this->dateType = $dateType;
    }

    public function setValues(array $values)
    {
        // need to parse for comparators
        $convertedFields = [];

        foreach ($values as $value) {
            if ($value instanceof SearchFieldComparableValue) {
                $convertedFields[] = $value;
                continue;
            }

            $convertedFields[] = $this->createDateComparableValue($value);
        }
        parent::setValues($convertedFields);
    }

    /**
     * @return SearchFieldComparableValue[]
     */
    public function getValues()
    {
        return parent::getValues(); // TODO: Change the autogenerated stub
    }


    /**
     * @param $value
     * @return SearchFieldComparableValue The created comparable value that will be used for querying against this search field.
     * @throws \InvalidArgumentException if the date format is not a valid ISO8610 format or if the date format does not follow FHIR spec.
     */
    private function createDateComparableValue($value): SearchFieldComparableValue
    {

        // we can't use something like DateTime::createFromFormat to conform with ISO8601 (xml date format)
        // unfortunately php will fill in the date with the current date which does not conform to spec.
        // spec requires that we fill in missing values with the lowest bounds of missing parameters.

        if (preg_match(self::COMPARATOR_MATCH, $value, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw new \InvalidArgumentException("Date format invalid must match ISO8610 format and values SHALL be populated from left to right");
        }
        if (empty($matches[2])) {
            throw new \InvalidArgumentException("Date format requires the year to be specified");
        }

        if (!empty($matches[5]) && empty($matches[6])) {
            throw new \InvalidArgumentException("Date format requires minutes to be specified if an hour is provided");
        }

        $comparator = $matches[1] ?? SearchComparator::EQUALS;
        if (!SearchComparator::isValidComparator($comparator)) {
            // TODO: adunsulag should we make this a specific Search Exception so we can report back an Operation Outcome?
            throw new \InvalidArgumentException("Invalid comparator found for value " . $value);
        }

        $lowerBoundRange = ['y' => $matches[2], 'm' => 1, 'd' => 1, 'H' => 0, 'i' => 0, 's' => 0];
        $upperBoundRange = ['y' => $matches[2], 'm' => 12, 'd' => 31, 'H' => 23, 'i' => 59, 's' => 59];

        // month
        if ($matches[3] != '') {
            $month = intval(substr($matches[3], 1));
            $month = min([max([$month, 1]), 12]);
            $lowerBoundRange['m'] = $month;
            $upperBoundRange['m'] = $month;
        }

        // day
        if ($matches[4] != '') {
            $day = intval(substr($matches[4], 1));
            $day = min([max([$day, 1]), cal_days_in_month(CAL_GREGORIAN, $upperBoundRange['m'], $upperBoundRange['y'])]);
            $lowerBoundRange['d'] = $day;
            $upperBoundRange['d'] = $day;
        } else {
            $upperBoundRange['d'] = cal_days_in_month(CAL_GREGORIAN, $upperBoundRange['m'], $upperBoundRange['y']);
        }

        // hour:minutes
        if ($matches[5] != '') {
            $parts = explode(":", $matches[5]);
            // remove the T and grab the value for the hour
            $hour = intval(substr($parts[0], 1));
            // hours: 0 <= hours <= 23
            $hour = min([max([$hour, 0]), 23]);
            $minutes = intval($parts[1]);
            // minutes: 0 <= minutes <= 60
            $minutes = min([max([$minutes, 0]), 59]);

            $lowerBoundRange['H'] = $hour;
            $upperBoundRange['H'] = $hour;
            $lowerBoundRange['i'] = $minutes;
            $upperBoundRange['i'] = $minutes;
        }

        // seconds
        if ($matches[5] != '') {
            // remove the ':'
            $seconds = intval(substr($matches[5], 1));
            $seconds = min([max([$seconds, 0]), 59]);
            $lowerBoundRange['s'] = $seconds;
            $upperBoundRange['s'] = $seconds;
        }

        $startRange = $this->createDateTimeFromArray($lowerBoundRange);
        $endRange = $this->createDateTimeFromArray($upperBoundRange);

        // not sure if the date period lazy creates the interval traversal or not so we will go with the maximum interval
        // we can think of.  We just are leveraging an existing PHP object that represents a pair of start/end dates
        $datePeriod = new \DatePeriod($startRange, new \DateInterval('P1Y'), $endRange);

        // TODO: adunsulag figure out how to handle timezones here...

        return new SearchFieldComparableValue($datePeriod, $comparator);
    }

    private function createDateTimeFromArray(array $datetime)
    {
        // Not sure how we want to handle timezone
        // we create a DateTime object as not all search fields are a DateTime so we go as precise as we can
        // and let the services go more imprecise if needed.
        $stringDate = sprintf("%d-%02d-%02d %02d:%02d:%02d", $datetime['y'], $datetime['m'], $datetime['d'], $datetime['H'], $datetime['i'], $datetime['s']);
        // 'n' & 'j' don't have leading zeros
        $dateValue = \DateTime::createFromFormat("Y-m-d H:i:s", $stringDate);
        return $dateValue;
    }
}
