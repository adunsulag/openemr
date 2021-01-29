<?php

/**
 * CustomClientCredentialsGrant.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

/**
 * Custom Password Grant
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2020 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Common\Auth\OpenIDConnect\Grant;

use DateInterval;
use GuzzleHttp\Exception\RequestException;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\CannotDecodeContent;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha384;
use Lcobucci\JWT\Token\InvalidTokenStructure;
use Lcobucci\JWT\Token\UnsupportedHeaderFound;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\ValidAt;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\RequestEvent;
use OpenEMR\Common\Auth\OpenIDConnect\Entities\ClientEntity;
use OpenEMR\Common\Auth\OpenIDConnect\JWT\JsonWebKeySet;
use OpenEMR\Common\Auth\OpenIDConnect\JWT\RsaSha384Signer;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\TrustedUserService;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class CustomClientCredentialsGrant extends ClientCredentialsGrant
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * The http client that retrieves JWK URIs
     * @var ClientInterface
     */
    private $httpClient;

    private $authTokenUrl;

    /**
     * @var TrustedUserService
     */
    private $trustedUserService;

    const OAUTH_JWT_CLIENT_ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    public function __construct($authTokenUrl)
    {
        $this->logger = new SystemLogger(); // default if we don't have one.
        // not sure the symmetric signer we even need for anything here...
        $this->jwtConfiguration = Configuration::forSymmetricSigner(
            new Sha384(),
            InMemory::plainText('')
        );
        $this->authTokenUrl = $authTokenUrl;
        $this->trustedUserService = new TrustedUserService();
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setHttpClient(ClientInterface $client)
    {
        $this->httpClient = $client;
    }

    public function getHttpClient(): ClientInterface
    {
        return $this->httpClient;
    }

    /**
     * @param DateInterval $accessTokenTTL
     * @param ClientEntityInterface $client
     * @param string|null $userIdentifier
     * @param array $scopes
     * @return \League\OAuth2\Server\Entities\AccessTokenEntityInterface
     * @throws OAuthServerException
     * @throws \League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException
     */
    protected function issueAccessToken(DateInterval $accessTokenTTL, ClientEntityInterface $client, $userIdentifier, array $scopes = [])
    {
        $accessToken = parent::issueAccessToken($accessTokenTTL, $client, $userIdentifier, $scopes); // TODO: Change the autogenerated stub

        // gotta remove since binary and will break json_encode (not used for password granttype, so ok to remove)
        unset($_SESSION['csrf_private_key']);

        $session_cache = json_encode($_SESSION, JSON_THROW_ON_ERROR);
        // if we ever decide to allow Client Credentials to be tied to a specific user we would change this
        $userId = null;
        $code = null; // code is used only in authorization_code grant types

        $scopeList = [];

        foreach ($scopes as $scope) {
            if ($scope instanceof ScopeEntityInterface) {
                $scopeList[] = $scope->getIdentifier();
            }
        }

        // we can't get past the api dispatcher without having a trusted user.
        $this->trustedUserService->saveTrustedUser(
            $client->getIdentifier(),
            $userId,
            implode(" ", $scopeList),
            0,
            $code,
            $session_cache,
            $this->getIdentifier()
        );

        return $accessToken;
    }

    /**
     * Gets the client credentials from the request from the request body or
     * the Http Basic Authorization header
     *
     * @param ServerRequestInterface $request
     *
     * @return array
     */
    protected function getClientCredentials(ServerRequestInterface $request)
    {
        $this->logger->debug("CustomClientCredentialsGrant->getClientCredentials() inside request");
        // @see https://tools.ietf.org/html/rfc7523#section-2.2
        $assertionType = $this->getRequestParameter('client_assertion_type', $request, null);
        if ($assertionType === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer') {
            $this->logger->debug("CustomClientCredentialsGrant->getClientCredentials() client_assertion_type of jwt-bearer.  Attempting to retrieve client id");
            $jwtToken = $this->getJWTFromRequest($request);
            // see if we can grab the client from here.

            try {
                // we skip validation so that we can grab our client id, from there we can hit the database to find our
                // JWK to validate against.
                $configuration = Configuration::forUnsecuredSigner(
                // You may also override the JOSE encoder/decoder if needed by providing extra arguments here
                );

                $token = $configuration->parser()->parse($jwtToken);

                assert($token instanceof Plain);
                $claims = $token->claims(); // Retrieves the token claims
                if ($claims->has('sub')) { // no subject means invalid client...
                    $this->logger->debug("CustomClientCredentialsGrant->getClientCredentials() jwt token parsed.  Client id is ", [$claims->get('sub')]);
                    return [$claims->get('sub')];
                }
            } catch (CannotDecodeContent | InvalidTokenStructure | UnsupportedHeaderFound $exception) {
                $this->logger->error(
                    "CustomClientCredentialsGrant->getClientCredentials() failed to parse token",
                    ['exceptionMessage' => $exception->getMessage()]
                );
                throw OAuthServerException::invalidClient($request);
            }
        } else {
            throw OAuthServerException::invalidRequest("client_assertion_type", "assertion type is not supported");
        }
        return null;
    }

    /**
     * Validate the client.
     *
     * @param ServerRequestInterface $request
     *
     * @throws OAuthServerException
     *
     * @return ClientEntityInterface
     */
    protected function validateClient(ServerRequestInterface $request)
    {
        // skip everything else for now.
        list($clientId) = $this->getClientCredentials($request);


        // grab the client
        $client = $this->getClientEntityOrFail($clientId, $request);

        // TODO: @adunsulag check with @bradymiller or @sjpadgett on whether this belongs in ClientRepository since we
        // are type checking it against ClientEntity.  Currently all the JWK validation stuff is centralized in this
        // grant... but knowledge of the client entity is inside the ClientRepository, either way I don't like the
        // class cohesion problems this creates.
        if (!($client instanceof ClientEntity)) {
            $this->logger->error("CustomClientCredentialsGrant->validateClient() client returned was not a valid ClientEntity ", ['client' => $clientId]);
            throw OAuthServerException::invalidClient($request);
        }

        // validate everything to do with the JWT...

        // @see https://tools.ietf.org/html/rfc7523#section-3

        // 1. iss claim required && must match iss URI of sender application
        // 2.B sub claim required && must match client id
        // 3. aud claim required && must match this oauth2 server.  Token endpoint may be used.  This value needs to
        // be communicated out of band to registering applications (put in SMART App registration page
        // 4. exp claim required && must be > (current time - skew time[60seconds]).
        //      OPTIONAL choice reject extended period exp claim.  OpenEMR choice set to 24 hours
        // 5. nbf claim if sent must be < (current time) or REJECT
        // 6. iat claim if sent may be rejected.  OpenEMR choice set to 5 minutes
        // 7. jti claim represents id of the web token, may be stored and checked against to prevent replay attacks
        // 8. has other claims
        // 9. MAC signature verification or REJECT
        // MAC signature needs to use ES384 or RS384 signature verification @see https://tools.ietf.org/html/rfc7518
        //
        // 10. Reject any other invalid JWT per RFC 7519

        // @see https://tools.ietf.org/html/rfc7523#section-3.2
        // IF ERROR set "error" parameter to "invalid_client" use "error_description" or "error_uri" to provide error
        // information

        try {
            // http client required for fetching jwks from the jwks uri and makes unit testing easier
            $jsonWebKeySet = new JsonWebKeySet($this->getHttpClient(), $client->getJwksUri(), $client->getJwks());


            $configuration = Configuration::forUnsecuredSigner();
            $configuration->setValidationConstraints(
                // we only allow 1 minute drift
                new ValidAt(new SystemClock(new \DateTimeZone(\date_default_timezone_get())), new \DateInterval('P1M')),
                new SignedWith(new RsaSha384Signer(), $jsonWebKeySet),
                new IssuedBy($client->getIdentifier()),
                new PermittedFor($this->authTokenUrl) // allowed audience
            );

            // Attempt to parse and validate the JWT
            $jwt = $this->getJWTFromRequest($request);
            // issuer = issue URI of sender application so redirectUri
            // subject claim
            $token = $configuration->parser()->parse($jwt);
            $this->logger->debug("Token parsed", ['claims' => $token->claims()->all(), 'headers' => $token->headers()->all(), 'signature' => $token->signature()->toString()]);
//

            $constraints = $configuration->validationConstraints();

            try {
                $configuration->validator()->assert($token, ...$constraints);
            } catch (RequiredConstraintsViolated $exception) {
                $this->logger->error(
                    "CustomClientCredentialsGrant->validateClient() jwt failed required constraints",
                    ['client' => $clientId, 'exceptionMessage' => $exception->getMessage()]
                );
                // ONC Inferno server refuses to allow a 401 HTTP status code to pass their test suite and requires
                // a 400 HTTP status code, despite the SMART spec specifically stating that invalid_client w/ 401 is
                // the response https://hl7.org/fhir/uv/bulkdata/authorization/index.html#signature-verification
                // so we force this to be a 400 exception
                // TODO: @adunsulag is there an update to inferno that fixes this issue?
                $exception = new OAuthServerException('Client authentication failed', 4, 'invalid_client', 400);
                $exception->setServerRequest($request);
                throw $exception;
            }
        } catch (CannotDecodeContent | InvalidTokenStructure | UnsupportedHeaderFound $exception) {
            $this->logger->error(
                "CustomClientCredentialsGrant->validateClient() failed to parse token",
                ['client' => $clientId, 'exceptionMessage' => $exception->getMessage()]
            );
            throw OAuthServerException::invalidClient($request);
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error(
                "CustomClientCredentialsGrant->validateClient() failed to retrieve jwk for client",
                ['client' => $clientId, 'exceptionMessage' => $exception->getMessage()]
            );
            throw OAuthServerException::invalidClient($request);
        }

        if ($this->clientRepository->validateClient($clientId, null, $this->getIdentifier()) === false) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidClient($request);
        }

        // If a redirect URI is provided ensure it matches what is pre-registered
        $redirectUri = $this->getRequestParameter('redirect_uri', $request, null);

        if ($redirectUri !== null) {
            $this->validateRedirectUri($redirectUri, $client, $request);
        }

        return $client;
    }

    private function getJWTFromRequest(ServerRequestInterface $request)
    {
        return $this->getRequestParameter('client_assertion', $request, null);
    }
}
