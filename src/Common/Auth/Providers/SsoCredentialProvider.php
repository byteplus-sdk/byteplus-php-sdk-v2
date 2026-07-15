<?php

namespace Byteplus\Common\Auth\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Byteplus\Common\ApiException;

/**
 * @internal
 *
 * This class is only used as an internal delegate from {@see CLIConfigCredentialProvider}
 * when the CLI config profile `mode` is `sso`. It is not part of the public credential
 * provider API and its constructor signature, behavior, and stability are subject to
 * change without notice. Do not instantiate this class directly.
 */
class SsoCredentialProvider extends Provider
{
    const PROVIDER_NAME = 'SsoCredentialProvider';
    const DEFAULT_REGION = 'ap-southeast-1';
    const DEFAULT_HTTP_TIMEOUT = 30;
    const DEFAULT_MAX_RETRIES = 3;
    const DEFAULT_RETRY_INTERVAL = 1;
    const OAUTH_BASE_URL_TEMPLATE = 'https://cloudidentity-oauth.%s.bytepluses.com';
    const PORTAL_BASE_URL_TEMPLATE = 'https://cloudidentity-portal.%s.bytepluses.com';
    const PORTAL_ACCESS_TOKEN_HEADER = 'x-bd-cloudidentity-bearer-token';

    private $profileData;
    private $profileName;
    private $config;
    private $cacheDir;
    private $cachedCredentials;
    private $expirationTime;
    private $tokenCache;

    public function __construct($profileData, $profileName, $config, $cacheDir)
    {
        $this->profileData = $profileData;
        $this->profileName = $profileName;
        $this->config = $config;
        $this->cacheDir = $cacheDir;
        $this->expirationTime = null;
        $this->tokenCache = null;
    }

    public function getCredentials()
    {
        if ($this->cachedCredentials !== null
            && ($this->expirationTime === null || time() < $this->expirationTime - 60)) {
            return $this->cachedCredentials;
        }

        $this->cachedCredentials = $this->retrieve();
        return $this->cachedCredentials;
    }

    private function retrieve()
    {
        // Fast path: use cached STS credentials from the CLI profile if still valid.
        $fastResult = $this->useStsCredentialsIfValid();
        if ($fastResult !== null) {
            return $fastResult;
        }

        list($sessionName, $startURL, $region) = $this->resolveSsoSession();
        $tokenPath = $this->resolveTokenCachePath($startURL, $sessionName);
        if ($this->tokenCache === null) {
            $this->tokenCache = $this->loadTokenCache($tokenPath);
        }

        $accessToken = isset($this->tokenCache['access_token']) && is_string($this->tokenCache['access_token'])
            ? trim($this->tokenCache['access_token']) : '';
        if ($accessToken === '') {
            throw new ApiException(
                self::PROVIDER_NAME . ": sso token cache file {$tokenPath} did not contain access_token; please run 'bp sso login' to re-authenticate"
            );
        }

        if ($this->isTokenExpired($this->tokenCache)) {
            $accessToken = $this->refreshAccessToken($this->tokenCache, $tokenPath, $region);
        }

        return $this->getRoleCredentials($accessToken, $region);
    }

    private function useStsCredentialsIfValid()
    {
        $expTimestamp = isset($this->profileData['sts-expiration']) ? (int) $this->profileData['sts-expiration'] : 0;
        if ($expTimestamp <= 0) {
            return null;
        }

        $expTime = $this->unixTimestampToSeconds($expTimestamp);
        if (time() >= $expTime) {
            return null;
        }

        $ak = isset($this->profileData['access-key']) ? trim($this->profileData['access-key']) : '';
        $sk = isset($this->profileData['secret-key']) ? trim($this->profileData['secret-key']) : '';
        $sessionToken = isset($this->profileData['session-token']) ? trim($this->profileData['session-token']) : '';

        if ($ak === '' || $sk === '') {
            return null;
        }

        $this->expirationTime = $expTime;

        return [
            'AccessKeyId' => $ak,
            'SecretAccessKey' => $sk,
            'SessionToken' => $sessionToken,
            'ProviderName' => self::PROVIDER_NAME,
        ];
    }

    private function resolveSsoSession()
    {
        $sessionName = isset($this->profileData['sso-session-name']) ? trim($this->profileData['sso-session-name']) : '';
        if ($sessionName === '') {
            throw new ApiException(
                self::PROVIDER_NAME . ": profile '{$this->profileName}' did not contain sso-session-name"
            );
        }

        $ssoSessions = isset($this->config['sso-session']) ? $this->config['sso-session'] : null;
        if (!is_array($ssoSessions) || empty($ssoSessions)) {
            throw new ApiException(
                self::PROVIDER_NAME . ': config did not contain any sso-session sections'
            );
        }

        if (!isset($ssoSessions[$sessionName])) {
            throw new ApiException(
                self::PROVIDER_NAME . ": sso-session '{$sessionName}' not found in config"
            );
        }

        $session = $ssoSessions[$sessionName];
        $startURL = isset($session['start-url']) ? trim($session['start-url']) : '';
        if ($startURL === '') {
            throw new ApiException(
                self::PROVIDER_NAME . ": sso-session '{$sessionName}' did not contain start-url"
            );
        }

        $region = isset($session['region']) ? trim($session['region']) : '';
        if ($region === '') {
            $region = self::DEFAULT_REGION;
        }

        return [$sessionName, $startURL, $region];
    }

    private function resolveTokenCachePath($startURL, $sessionName)
    {
        if ($this->cacheDir === '') {
            throw new ApiException(
                self::PROVIDER_NAME . ': could not resolve SSO cache directory'
            );
        }

        $fileName = $this->tokenCacheFileName($startURL, $sessionName);
        $tokenPath = $this->cacheDir . DIRECTORY_SEPARATOR . $fileName;

        if ($this->tokenCache === null && !file_exists($tokenPath)) {
            throw new ApiException(
                self::PROVIDER_NAME . ": sso token cache file {$tokenPath} does not exist; please run 'bp sso login' to re-authenticate"
            );
        }

        return $tokenPath;
    }

    private function tokenCacheFileName($startURL, $sessionName)
    {
        $payload = json_encode([
            'start_url' => $startURL,
            'session_name' => $sessionName,
        ], JSON_UNESCAPED_SLASHES);
        return sha1($payload) . '.json';
    }

    private function loadTokenCache($tokenPath)
    {
        $content = @file_get_contents($tokenPath);
        if ($content === false) {
            throw new ApiException(
                self::PROVIDER_NAME . ": failed to read sso token cache file {$tokenPath}; please run 'bp sso login' to re-authenticate"
            );
        }

        if (trim($content) === '') {
            throw new ApiException(
                self::PROVIDER_NAME . ": sso token cache file {$tokenPath} was empty; please run 'bp sso login' to re-authenticate"
            );
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new ApiException(
                self::PROVIDER_NAME . ": failed to parse sso token cache file {$tokenPath}; please run 'bp sso login' to re-authenticate"
            );
        }

        return $data;
    }

    private function isTokenExpired($tokenCache)
    {
        $expiresAt = isset($tokenCache['expires_at']) && is_string($tokenCache['expires_at'])
            ? trim($tokenCache['expires_at']) : '';
        if ($expiresAt === '') {
            return false;
        }

        $ts = strtotime($expiresAt);
        if ($ts === false) {
            throw new ApiException(
                self::PROVIDER_NAME . ": failed to parse expires_at: {$expiresAt}; please run 'bp sso login' to re-authenticate"
            );
        }

        return time() > $ts;
    }

    private function refreshAccessToken(&$tokenCache, $tokenPath, $region)
    {
        $refreshToken = isset($tokenCache['refresh_token']) && is_string($tokenCache['refresh_token'])
            ? trim($tokenCache['refresh_token']) : '';
        if ($refreshToken === '') {
            throw new ApiException(
                self::PROVIDER_NAME . ": sso token cache file {$tokenPath} did not contain refresh_token; please run 'bp sso login' to re-authenticate"
            );
        }

        // Check if refresh token (client_secret) is expired
        $clientSecretExpiresAt = isset($tokenCache['client_secret_expires_at']) ? (int) $tokenCache['client_secret_expires_at'] : 0;
        if ($clientSecretExpiresAt > 0
            && time() >= $this->unixTimestampToSeconds($clientSecretExpiresAt)) {
            throw new ApiException(
                self::PROVIDER_NAME . ": refresh token in {$tokenPath} has expired; please run 'bp sso login' to re-authenticate"
            );
        }

        $clientId = isset($tokenCache['client_id']) && is_string($tokenCache['client_id'])
            ? trim($tokenCache['client_id']) : '';
        $clientSecret = isset($tokenCache['client_secret']) && is_string($tokenCache['client_secret'])
            ? trim($tokenCache['client_secret']) : '';
        if ($clientId === '' || $clientSecret === '') {
            throw new ApiException(
                self::PROVIDER_NAME . ": sso token cache file {$tokenPath} did not contain client_id/client_secret; please run 'bp sso login' to re-authenticate"
            );
        }

        $oauthURL = sprintf(self::OAUTH_BASE_URL_TEMPLATE, $region) . '/token';
        $requestBody = json_encode([
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        $responseBody = $this->doPost($oauthURL, $requestBody);
        $response = json_decode($responseBody, true);
        if (!is_array($response)) {
            throw new ApiException(
                self::PROVIDER_NAME . ": failed to parse OAuth token refresh response; please run 'bp sso login' to re-authenticate"
            );
        }

        $newAccessToken = isset($response['access_token']) && is_string($response['access_token'])
            ? trim($response['access_token']) : '';
        if ($newAccessToken === '') {
            throw new ApiException(
                self::PROVIDER_NAME . ": OAuth token refresh response did not include access_token; please run 'bp sso login' to re-authenticate"
            );
        }

        $expiresIn = isset($response['expires_in']) ? (int) $response['expires_in'] : 0;
        if ($expiresIn <= 0) {
            throw new ApiException(
                self::PROVIDER_NAME . ": OAuth token refresh response did not include expires_in; please run 'bp sso login' to re-authenticate"
            );
        }

        // Update token cache
        $tokenCache['access_token'] = $newAccessToken;
        if (isset($response['refresh_token']) && is_string($response['refresh_token']) && trim($response['refresh_token']) !== '') {
            $tokenCache['refresh_token'] = $response['refresh_token'];
        }
        $tokenCache['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', time() + $expiresIn);

        return $newAccessToken;
    }

    private function getRoleCredentials($accessToken, $region)
    {
        $accountId = isset($this->profileData['account-id']) ? trim($this->profileData['account-id']) : '';
        if ($accountId === '') {
            throw new ApiException(
                self::PROVIDER_NAME . ": profile '{$this->profileName}' did not contain account-id"
            );
        }

        $roleName = isset($this->profileData['role-name']) ? trim($this->profileData['role-name']) : '';
        if ($roleName === '') {
            throw new ApiException(
                self::PROVIDER_NAME . ": profile '{$this->profileName}' did not contain role-name"
            );
        }

        $portalURL = sprintf(self::PORTAL_BASE_URL_TEMPLATE, $region)
            . '/federation/credentials'
            . '?account_id=' . urlencode($accountId)
            . '&role_name=' . urlencode($roleName);

        $responseBody = $this->doGet($portalURL, $accessToken);
        $response = json_decode($responseBody, true);
        if (!is_array($response)) {
            throw new ApiException(
                self::PROVIDER_NAME . ": failed to parse portal credentials response; please run 'bp sso login' to re-authenticate"
            );
        }

        $result = isset($response['Result'])
            ? $response['Result']
            : (isset($response['result']) ? $response['result'] : []);
        $roleCreds = isset($result['RoleCredentials'])
            ? $result['RoleCredentials']
            : (isset($result['roleCredentials']) ? $result['roleCredentials'] : null);
        if (!is_array($roleCreds)) {
            throw new ApiException(
                self::PROVIDER_NAME . ": portal response did not contain Result.RoleCredentials; please run 'bp sso login' to re-authenticate"
            );
        }

        $ak = isset($roleCreds['AccessKeyId']) && is_string($roleCreds['AccessKeyId']) ? trim($roleCreds['AccessKeyId']) : '';
        $sk = isset($roleCreds['SecretAccessKey']) && is_string($roleCreds['SecretAccessKey']) ? trim($roleCreds['SecretAccessKey']) : '';
        $rawSessionToken = isset($roleCreds['sessionToken'])
            ? $roleCreds['sessionToken']
            : (isset($roleCreds['SessionToken']) ? $roleCreds['SessionToken'] : '');
        $sessionToken = is_string($rawSessionToken) ? trim($rawSessionToken) : '';
        $expiration = isset($roleCreds['Expiration']) ? (int) $roleCreds['Expiration'] : 0;

        if ($ak === '' || $sk === '') {
            throw new ApiException(
                self::PROVIDER_NAME . ": portal credentials response did not include AccessKeyId or SecretAccessKey; please run 'bp sso login' to re-authenticate"
            );
        }

        if ($expiration > 0) {
            $this->expirationTime = $this->unixTimestampToSeconds($expiration);
        } else {
            $this->expirationTime = null;
        }

        return [
            'AccessKeyId' => $ak,
            'SecretAccessKey' => $sk,
            'SessionToken' => $sessionToken,
            'ProviderName' => self::PROVIDER_NAME,
        ];
    }

    private function doPost($url, $body)
    {
        $client = new Client([
            'timeout' => self::DEFAULT_HTTP_TIMEOUT,
            'connect_timeout' => 5,
            'verify' => true,
            'http_errors' => false,
        ]);

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => $body,
            ]);
        } catch (TransferException $e) {
            throw new ApiException(
                self::PROVIDER_NAME . ': HTTP POST request failed to ' . $url . ' - ' . $e->getMessage()
                . "; please run 'bp sso login' to re-authenticate"
            );
        }

        $statusCode = $response->getStatusCode();
        $responseBody = (string) $response->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ApiException(
                self::PROVIDER_NAME . ': HTTP POST request failed with status ' . $statusCode
                . ($responseBody !== '' ? ': ' . $responseBody : '')
                . "; please run 'bp sso login' to re-authenticate",
                $statusCode,
                $response->getHeaders(),
                $responseBody
            );
        }

        return $responseBody;
    }

    private function doGet($url, $accessToken)
    {
        $client = new Client([
            'timeout' => self::DEFAULT_HTTP_TIMEOUT,
            'connect_timeout' => 5,
            'verify' => true,
            'http_errors' => false,
        ]);

        $lastError = null;
        for ($attempt = 0; $attempt < self::DEFAULT_MAX_RETRIES; $attempt++) {
            try {
                $response = $client->get($url, [
                    'headers' => [
                        'Accept' => 'application/json',
                        self::PORTAL_ACCESS_TOKEN_HEADER => $accessToken,
                    ],
                ]);
            } catch (TransferException $e) {
                throw new ApiException(
                    self::PROVIDER_NAME . ': HTTP GET request failed to ' . $url . ' - ' . $e->getMessage()
                    . "; please run 'bp sso login' to re-authenticate"
                );
            }

            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();
            if ($statusCode >= 200 && $statusCode < 300) {
                return $responseBody;
            }

            $lastError = new ApiException(
                self::PROVIDER_NAME . ': HTTP GET request failed with status ' . $statusCode
                . ($responseBody !== '' ? ': ' . $responseBody : '')
                . "; please run 'bp sso login' to re-authenticate",
                $statusCode,
                $response->getHeaders(),
                $responseBody
            );
            if ($statusCode < 500) {
                throw $lastError;
            }
            if ($attempt < self::DEFAULT_MAX_RETRIES - 1) {
                sleep(self::DEFAULT_RETRY_INTERVAL);
            }
        }
        throw $lastError;
    }

    /**
     * Convert a unix timestamp (seconds, milliseconds, microseconds, or nanoseconds) to seconds.
     */
    private function unixTimestampToSeconds($ts)
    {
        if ($ts >= 1e18) {
            return (int) ($ts / 1e9);
        }
        if ($ts >= 1e15) {
            return (int) ($ts / 1e6);
        }
        if ($ts >= 1e12) {
            return (int) ($ts / 1e3);
        }
        return (int) $ts;
    }
}
