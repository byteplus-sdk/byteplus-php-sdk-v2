<?php

namespace Byteplus\Common\Auth\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use Byteplus\Common\ApiException;
use Byteplus\Common\HeaderSelector;
use Byteplus\Common\Utils;

class StsProvider extends Provider
{
    const PROVIDER_NAME = 'StsCredentialProvider';
    const DEFAULT_REGION = 'ap-southeast-1';
    const DEFAULT_ENDPOINT = 'sts.ap-southeast-1.byteplusapi.com';
    const DEFAULT_EXPIRE_BUFFER_SECONDS = 60;
    const MAX_EXPIRE_BUFFER_SECONDS = 600;

    private $ak;
    private $sk;
    private $roleName;
    private $accountId;
    private $durationSeconds;
    private $host;
    private $region;
    private $schema;
    private $policy;
    private $headerSelector;
    private $config;
    private $timeout;
    private $expireBufferSeconds;
    private $maxRetries;
    private $retryInterval;
    private $cachedCredentials;
    private $expirationTime;

    public function __construct(
        $ak,
        $sk,
        $roleName,
        $accountId,
        $region = self::DEFAULT_REGION,
        $durationSeconds = 3600,
        $schema = 'https',
        $host = self::DEFAULT_ENDPOINT,
        $policy = null,
        $selector = null,
        $timeout = 30,
        $expireBufferSeconds = self::DEFAULT_EXPIRE_BUFFER_SECONDS,
        $maxRetries = 3,
        $retryInterval = 1
    )
    {
        if ($expireBufferSeconds > self::MAX_EXPIRE_BUFFER_SECONDS) {
            throw new \InvalidArgumentException(
                'expireBufferSeconds must be less than or equal to ' . self::MAX_EXPIRE_BUFFER_SECONDS
            );
        }
        $this->ak = $ak;
        $this->sk = $sk;
        $this->roleName = $roleName;
        $this->accountId = $accountId;
        $this->durationSeconds = $durationSeconds;
        $this->host = $host;
        $this->region = $region;
        $this->schema = $schema;
        $this->policy = $policy;
        $this->headerSelector = $selector ?: new HeaderSelector();
        $this->config = \Byteplus\Common\Configuration::getDefaultConfiguration();
        $this->timeout = $timeout;
        $this->expireBufferSeconds = $expireBufferSeconds;
        $this->maxRetries = max((int) $maxRetries, 1);
        $this->retryInterval = $retryInterval;
        $this->expirationTime = null;
    }

    public function getCredentials()
    {
        if ($this->cachedCredentials !== null
            && $this->expirationTime !== null
            && time() < $this->expirationTime - $this->expireBufferSeconds) {
            return $this->cachedCredentials;
        }

        $this->cachedCredentials = $this->assumeRole();
        return $this->cachedCredentials;
    }

    private function assumeRole()
    {
        $headers = $this->headerSelector->selectHeaders(
            ['application/json'],
            ['text/plain']
        );
        $defaultHeaders = [];
        $defaultHeaders['User-Agent'] = $this->config->getUserAgent();
        $headers = array_merge(
            $defaultHeaders,
            $headers
        );
        $queryParams = [
            'Action' => "AssumeRole",
            'Version' => '2018-01-01',
            'DurationSeconds' => $this->durationSeconds,
            'RoleSessionName' => uniqid('', true),
            'RoleTrn' => 'trn:iam::' . $this->accountId . ':role/' . $this->roleName
        ];

        if ($this->policy !== null) {
            $queryParams['Policy'] = $this->policy;
        }

        $query = '';
        ksort($queryParams);  // sort query first
        foreach ($queryParams as $k => $v) {
            $query .= rawurlencode($k) . '=' . rawurlencode($v) . '&';
        }
        $query = substr($query, 0, -1);

        $headers = Utils::signv4($this->ak, $this->sk, $this->region, 'sts',
            '', $query, 'GET', '/', $headers);

        $request = new Request('GET',
            $this->schema . '://' . $this->host . '/' . ($query ? "?{$query}" : ''),
            $headers, '');

        $client = new Client([
            'timeout' => $this->timeout,
            'connect_timeout' => 5,
            'verify' => true,
            'http_errors' => false,
        ]);
        $lastException = null;
        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            try {
                $response = $client->send($request, [
                    'timeout' => $this->timeout,
                    'connect_timeout' => 5,
                ]);
                $statusCode = $response->getStatusCode();
                if ($statusCode >= 200 && $statusCode <= 299) {
                    $lastException = null;
                    break;
                }

                $lastException = new ApiException(
                    sprintf(
                        '[%d] Error connecting to the API (%s)(%s)',
                        $statusCode,
                        $request->getUri(),
                        $response->getBody()
                    ),
                    $statusCode,
                    $response->getHeaders(),
                    (string) $response->getBody()
                );
                if ($statusCode < 500) {
                    throw $lastException;
                }
            } catch (TransferException $e) {
                $response = $e instanceof RequestException ? $e->getResponse() : null;
                $lastException = new ApiException(
                    "[{$e->getCode()}] {$e->getMessage()}",
                    $e->getCode(),
                    $response ? $response->getHeaders() : null,
                    $response ? (string) $response->getBody() : null
                );
            }

            if ($attempt < $this->maxRetries - 1) {
                sleep($this->retryInterval);
            }
        }
        if ($lastException !== null) {
            throw $lastException;
        }
        $responseContent = $response->getBody()->getContents();
        $content = json_decode($responseContent);

        if (isset($content->{'ResponseMetadata'}->{'Error'})) {
            throw new ApiException(
                sprintf(
                    '[%d] Return Error From the API (%s)(%s)',
                    $statusCode,
                    $request->getUri(),
                    $response->getBody()
                ),
                $statusCode,
                $response->getHeaders(),
                $responseContent);
        }
        if (!isset($content->{'Result'}->{'Credentials'})) {
            throw new ApiException(
                self::PROVIDER_NAME . ': AssumeRole returned no credentials',
                0,
                $response->getHeaders(),
                $responseContent
            );
        }
        $credentials = (array) $content->{'Result'}->{'Credentials'};
        if (empty($credentials['AccessKeyId'])
            || empty($credentials['SecretAccessKey'])
            || empty($credentials['SessionToken'])
            || empty($credentials['ExpiredTime'])) {
            throw new ApiException(
                self::PROVIDER_NAME . ': AssumeRole credentials missing required fields',
                0,
                $response->getHeaders(),
                $responseContent
            );
        }

        $expiration = strtotime($credentials['ExpiredTime']);
        if ($expiration === false) {
            throw new ApiException(
                self::PROVIDER_NAME . ': AssumeRole credentials contain invalid ExpiredTime',
                0,
                $response->getHeaders(),
                $responseContent
            );
        }
        $this->expirationTime = $expiration;

        return [
            'AccessKeyId' => $credentials['AccessKeyId'],
            'SecretAccessKey' => $credentials['SecretAccessKey'],
            'SessionToken' => $credentials['SessionToken'],
            'ProviderName' => self::PROVIDER_NAME,
        ];
    }
}

?>
