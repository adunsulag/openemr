<?php

/**
 * EncounterServiceTest.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

/**
 * CarePlanServiceTest.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Tests\Services;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Services\EncounterService;
use OpenEMR\Tests\Fixtures\EncounterFixtureManager;
use PHPUnit\Framework\TestCase;
use OpenEMR\Common\Uuid\UuidRegistry;

class EncounterServiceTest extends TestCase
{
    /**
     * @var EncounterService
     */
    private $service;

    /**
     * @var EncounterFixtureManager
     */
    private $fixtureManager;

    protected function setUp(): void
    {
        $this->service = new EncounterService();
        $this->fixtureManager = new EncounterFixtureManager();
        $this->fixture = (array) $this->fixtureManager->getSingleFixture();
    }

    protected function tearDown(): void
    {
        $this->fixtureManager->removeFixtures();
    }

    /**
     * @cover ::getOne
     */
    public function testGetOne()
    {
        $this->fixtureManager->installFixtures();

        // attempt to verify the uuid surrogate key is working correctly
        $uuid = QueryUtils::fetchSingleValue("SELECT `uuid`,`encounter` FROM `form_encounter`", "uuid");
        $uuidString = UuidRegistry::uuidToString($uuid);
        // getOne
        $actualResult = $this->service->getEncounter($uuidString);
        $resultData = $actualResult->getData()[0];
        $this->assertNotNull($resultData);
    }
}
