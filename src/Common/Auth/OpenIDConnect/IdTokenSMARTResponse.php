<?php

/**
 * Handles extra claims required for SMART on FHIR requests
 * @see http://hl7.org/fhir/smart-app-launch/scopes-and-launch-context/index.html
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2020 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Common\Auth\OpenIDConnect;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\PatientService;
use OpenIDConnectServer\IdTokenResponse;
use OpenIDConnectServer\Repositories\IdentityProviderInterface;
use OpenIDConnectServer\ClaimExtractor;
use Psr\Log\LoggerInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;

class IdTokenSMARTResponse extends IdTokenResponse
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        IdentityProviderInterface $identityProvider,
        ClaimExtractor $claimExtractor
    ) {
        $this->logger = SystemLogger::instance();
        parent::__construct($identityProvider, $claimExtractor);
    }

    protected function getExtraParams(AccessTokenEntityInterface $accessToken)
    {
        $extraParams = parent::getExtraParams($accessToken);
        $this->logger->debug("IdTokenSMARTResponse->getExtraParams() params from parent ", ["params" => $extraParams]);

        if ($this->isLaunchPatientRequest($accessToken->getScopes())) {
            // for testing purposes we are going to return just the first patient we find for our launch context...
            // what we need to do is have a patient selector and return the selected patient as part of the OAUTH
            // sequence.
            $patientService = new PatientService();
            $patients = $patientService->getAll();
            $patientsList = $patients->getData();
            if (!empty($patientsList)) {
                $this->logger->debug("patients found", ['patients' => $patientsList]);
                $extraParams['patient'] = $patientsList[0]['uuid'];
            }
        }

        $this->logger->debug("IdTokenSMARTResponse->getExtraParams() final params", ["params" => $extraParams]);
        return $extraParams;
    }

    /**
     * @param ScopeEntityInterface[] $scopes
     * @return bool
     */
    private function isLaunchPatientRequest($scopes)
    {
        // Verify scope and make sure openid exists.
        $valid  = false;

        foreach ($scopes as $scope) {
            if ($scope->getIdentifier() === 'launch/patient') {
                $valid = true;
                break;
            }
        }

        return $valid;
    }
}
