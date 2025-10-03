<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Causal\Oidc\Service;

use Causal\Oidc\Event\GetAuthorizationUrlEvent;
use Causal\Oidc\Factory\OAuthProviderFactoryInterface;
use Causal\Oidc\OidcConfiguration;
use GuzzleHttp\RequestOptions;
use League\OAuth2\Client\Grant\AuthorizationCode;
use League\OAuth2\Client\Grant\ClientCredentials;
use League\OAuth2\Client\Grant\Password;
use League\OAuth2\Client\Grant\RefreshToken;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class OAuthService.
 */
class OAuthService
{
    protected ?AbstractProvider $provider = null;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        protected OidcConfiguration $settings
    ) {}

    /**
     * Returns the authorization URL.
     *
     * @param ServerRequestInterface|null $request
     * @param array $options
     * @return string
     */
    public function getAuthorizationUrl(?ServerRequestInterface $request, array $options = []): string
    {
        $event = $this->eventDispatcher->dispatch(new GetAuthorizationUrlEvent($request, $this->settings, $options));
        $options = $event->options;
        return $this->getProvider()->getAuthorizationUrl($options);
    }

    /**
     * Returns the state generated for us.
     *
     * @return string
     * @see getAuthorizationUrl()
     */
    public function getState(): string
    {
        return $this->getProvider()->getState();
    }

    /**
     * Returns an AccessToken using either authorization code grant or resource owner password
     * credentials grant.
     *
     * @param string $codeOrUsername Either a code or the username (if password is provided)
     * @param string|null $password Optional parameter if authenticating with authorization code grant
     * @param string|null $codeVerifier Code verifier for PKCE
     * @return AccessToken
     * @throws IdentityProviderException
     */
    public function getAccessToken(
        string $codeOrUsername,
        #[\SensitiveParameter]
        ?string $password = null,
        #[\SensitiveParameter]
        ?string $codeVerifier = null
    ): AccessToken {
        if ($password === null) {
            $options = [
                'code' => $codeOrUsername,
            ];
            if ($codeVerifier !== null) {
                $options['code_verifier'] = $codeVerifier;
            }
            $grant = new AuthorizationCode();
        } else {
            $options = [
                'username' => $codeOrUsername,
                'password' => $password,
            ];
            $grant = new Password();
        }
        return $this->getProvider()->getAccessToken($grant, $options);
    }

    /**
     * @throws IdentityProviderException
     */
    public function getAccessTokenForClient(): AccessTokenInterface
    {
        return $this->getProvider()->getAccessToken(new ClientCredentials());
    }

    /**
     * Returns an AccessToken using request path authentication.
     *
     * This non-standard behaviour is described on
     * https://docs.wso2.com/display/IS530/Try+Password+Grant
     *
     * @param string $username
     * @param string $password
     * @return AccessToken|null
     * @throws IdentityProviderException
     */
    public function getAccessTokenWithRequestPathAuthentication(string $username, #[\SensitiveParameter] string $password): ?AccessToken
    {
        $url = $this->settings->endpointAuthorize . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->settings->oidcClientKey,
            'scope' => $this->settings->oidcClientScopes,
            'redirect_uri' => $this->getRedirectUrl(),
        ]);

        $result = GeneralUtility::makeInstance(RequestFactory::class)->request(
            'GET',
            $url,
            [
                RequestOptions::AUTH => [$username, $password],
                RequestOptions::ALLOW_REDIRECTS => false,
            ]
        );

        if ($result->getStatusCode() < 300 || $result->getStatusCode() >= 400) {
            throw new RuntimeException('Request failed', 1510049345);
        }

        if ($result->getHeader('Location')) {
            $targetUrl = $result->getHeader('Location')[0];
            $query = parse_url($targetUrl, PHP_URL_QUERY);
            parse_str($query, $queryParams);
            if (isset($queryParams['code'])) {
                return $this->getAccessToken($queryParams['code']);
            }
        }

        return null;
    }

    /**
     * Returns the resource owner.
     *
     * @param AccessToken $token
     * @return ResourceOwnerInterface
     * @throws IdentityProviderException May be thrown by provider
     */
    public function getResourceOwner(AccessToken $token): ResourceOwnerInterface
    {
        return $this->getProvider()->getResourceOwner($token);
    }

    /**
     * Revokes the access token.
     *
     * @param AccessToken $token
     * @return bool
     * @throws IdentityProviderException
     */
    public function revokeToken(AccessToken $token): bool
    {
        if (!$this->settings->endpointRevoke) {
            return false;
        }

        $provider = $this->getProvider();
        $request = $provider->getRequest(
            AbstractProvider::METHOD_POST,
            $this->settings->endpointRevoke,
            [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->settings->oidcClientKey . ':' . $this->settings->oidcClientSecret),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => 'token=' . $token->getToken(),
            ]
        );

        $response = $provider->getParsedResponse($request);
        // TODO error handling?

        return true;
    }

    protected function getProvider(): AbstractProvider
    {
        if ($this->provider === null) {
            if (!is_a($this->settings->oauthProviderFactory, OAuthProviderFactoryInterface::class, true)) {
                throw new RuntimeException('OAuth provider factory class must implement the OAuthProviderFactoryInterface', 1652689564769);
            }

            $settings = $this->settings;
            $settings->oidcRedirectUri = $this->getRedirectUrl();

            $factory = GeneralUtility::makeInstance($this->settings->oauthProviderFactory);
            $this->provider = $factory->create($settings);
        }

        return $this->provider;
    }

    public function getFreshAccessToken(string $serializedToken): ?AccessToken
    {
        $options = json_decode($serializedToken, true);
        if (empty($serializedToken) || empty($options)) {
            // Invalid token
            return null;
        }
        $accessToken = new AccessToken($options);

        if ($accessToken->hasExpired()) {
            try {
                $newAccessToken = $this->getProvider()->getAccessToken(new RefreshToken(), [
                    'refresh_token' => $accessToken->getRefreshToken(),
                ]);

                // TODO
            } catch (IdentityProviderException $e) {
                // TODO: log problem
                return null;
            }
        }

        return $accessToken;
    }

    protected function getRedirectUrl(): string
    {
        return $this->settings->oidcRedirectUri ?: GeneralUtility::getIndpEnv('TYPO3_SITE_URL');
    }
}
