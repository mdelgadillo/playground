<?php

/**
 * CustomAuthCodeGrant.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Common\Auth\OpenIDConnect\Grant;

use DateInterval;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\FHIR\SMART\SMARTLaunchToken;
use OpenEMR\RestControllers\AuthorizationController;
use Psr\Http\Message\ServerRequestInterface;

class CustomAuthCodeGrant extends AuthCodeGrant
{
    /**
     * @var string The expected 'aud' query parameter to validate a JWT grant against
     */
    private $expectedAudience;

    public function __construct(AuthCodeRepositoryInterface $authCodeRepository, RefreshTokenRepositoryInterface $refreshTokenRepository, DateInterval $authCodeTTL, $expectedAudience)
    {
        parent::__construct($authCodeRepository, $refreshTokenRepository, $authCodeTTL);
        $this->expectedAudience = $expectedAudience;
    }

    public function validateAuthorizationRequest(ServerRequestInterface $request)
    {
        // This function will force audience check if using launch scenario (ie. SMART).
        //  In non-launch scenario, it will only check audience if it has been provided in the request.
        $audience = $this->getQueryStringParameter(
            'aud',
            $request
        );
        $launch = $this->getQueryStringParameter(
            'launch',
            $request
        );

        // let's validate the aud param (if it exists)
        //  (note that this check is forced below if using launch scenario; so it is skipped here in the launch scenario)
        if (!empty($audience) && empty($launch)) {
            if (!in_array($audience, $this->expectedAudience)) {
                (new SystemLogger())->errorLogCaller("Aud parameter did not match authorized server in non-launch scenario", ['audience' => $audience, 'expected' => $this->expectedAudience]);
                throw OAuthServerException::invalidRequest("aud", "Aud parameter did not match authorized server");
            }
        } else if (empty($audience) && empty($launch)) {
            (new SystemLogger())->debug("Aud parameter not provided (and non-launch scenario), so not validating aud (audience)");
        }

        // let's validate the launch param
        if (!empty($launch)) {
            if (!in_array($audience, $this->expectedAudience)) {
                (new SystemLogger())->errorLogCaller("Aud parameter did not match authorized server in launch scenario", ['audience' => $audience, 'expected' => $this->expectedAudience]);
                throw OAuthServerException::invalidRequest("aud", "Aud parameter did not match authorized server");
            }
            try {
                $launchToken = SMARTLaunchToken::deserializeToken($launch);
                if (empty($launchToken)) {
                    throw OAuthServerException::invalidRequest("launch", "launch parameter was incorrectly formatted or did not originate from this server");
                }
            } catch (\Exception $exception) {
                (new SystemLogger())->errorLogCaller("Failed to deserialize launch token", ['launch' => $launch, 'message' => $exception->getMessage(), 'trace' => $exception->getTraceAsString()]);
                throw OAuthServerException::invalidRequest('launch', "launch parameter was incorrectly formatted or did not originate from this server");
            }
        }
        return parent::validateAuthorizationRequest($request);
    }
}
