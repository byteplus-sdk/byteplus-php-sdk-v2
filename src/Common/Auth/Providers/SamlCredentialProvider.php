<?php

namespace Byteplus\Common\Auth\Providers;

use Byteplus\Common\ApiException;

class SamlCredentialProvider extends Provider
{
    use StsCredentialTrait;

    const PROVIDER_NAME = 'SamlCredentialProvider';
    const DEFAULT_DURATION_SECONDS = 3600;
    const DEFAULT_EXPIRE_BUFFER_SECONDS = 60;
    const MAX_EXPIRE_BUFFER_SECONDS = 600;
    const DEFAULT_REGION = 'ap-southeast-1';

    private $roleName;
    private $accountId;
    private $samlProviderName;
    private $samlAssertion;
    private $rolePolicy;
    private $stsEndpoint;
    private $durationSeconds;
    private $expireBufferSeconds;
    private $roleTrn;
    private $samlProviderTrn;
    private $roleSessionName;
    private $region;

    private $cachedCredentials;
    private $expirationTime = 0;

    public function __construct(
        $roleName = null,
        $accountId = null,
        $samlProviderName = null,
        $samlAssertion = null,
        $rolePolicy = null,
        $stsEndpoint = null,
        $durationSeconds = self::DEFAULT_DURATION_SECONDS,
        $expireBufferSeconds = self::DEFAULT_EXPIRE_BUFFER_SECONDS,
        $roleTrn = null,
        $samlProviderTrn = null,
        $roleSessionName = null,
        $region = self::DEFAULT_REGION
    )
    {
        $resolvedRoleTrn = !empty($roleTrn)
            ? $roleTrn
            : (!empty($roleName) && !empty($accountId)
                ? 'trn:iam::' . $accountId . ':role/' . $roleName
                : null);
        $resolvedAccountId = !empty($accountId) ? $accountId : $this->extractAccountId($resolvedRoleTrn);
        $resolvedSamlProviderTrn = !empty($samlProviderTrn)
            ? $samlProviderTrn
            : (!empty($samlProviderName) && !empty($resolvedAccountId)
                ? 'trn:iam::' . $resolvedAccountId . ':saml-provider/' . $samlProviderName
                : null);

        if (empty($resolvedRoleTrn) || empty($resolvedSamlProviderTrn) || empty($samlAssertion)) {
            throw new \InvalidArgumentException(
                self::PROVIDER_NAME . ': roleTrn, samlProviderTrn and samlAssertion are required; '
                . 'TRNs may be supplied directly or derived from roleName, accountId and samlProviderName'
            );
        }
        if ($expireBufferSeconds > self::MAX_EXPIRE_BUFFER_SECONDS) {
            throw new \InvalidArgumentException(
                self::PROVIDER_NAME . ': expireBufferSeconds must be less than or equal to '
                . self::MAX_EXPIRE_BUFFER_SECONDS
            );
        }
        $this->roleName = $roleName;
        $this->accountId = $accountId;
        $this->samlProviderName = $samlProviderName;
        $this->samlAssertion = $samlAssertion;
        $this->rolePolicy = $rolePolicy;
        $this->stsEndpoint = $stsEndpoint ?: StsFormRequest::DEFAULT_STS_ENDPOINT;
        $this->durationSeconds = $durationSeconds;
        $this->expireBufferSeconds = $expireBufferSeconds;
        $this->roleTrn = $resolvedRoleTrn;
        $this->samlProviderTrn = $resolvedSamlProviderTrn;
        $this->roleSessionName = $roleSessionName;
        $this->region = $region;
    }

    public function getCredentials()
    {
        if ($this->cachedCredentials !== null && time() < $this->expirationTime) {
            return $this->cachedCredentials;
        }

        $this->refresh();
        return $this->cachedCredentials;
    }

    private function refresh()
    {
        $queryParams = [
            'Action' => 'AssumeRoleWithSAML',
            'Version' => '2018-01-01',
        ];

        $bodyParams = [
            'DurationSeconds' => $this->durationSeconds,
            'RoleSessionName' => $this->roleSessionName ?: md5(uniqid('', true)),
            'RoleTrn' => $this->roleTrn,
            'SAMLProviderTrn' => $this->samlProviderTrn,
            'SAMLResp' => $this->samlAssertion,
        ];

        // SAML puts Policy in form body
        if ($this->rolePolicy !== null) {
            $bodyParams['Policy'] = $this->rolePolicy;
        }

        $formBody = http_build_query($bodyParams);

        $responseBody = StsFormRequest::doPostWithRetry(
            $this->stsEndpoint, $this->schema, $queryParams,
            $formBody, $this->maxRetries, $this->retryInterval,
            self::PROVIDER_NAME
        );

        $content = json_decode($responseBody, true);
        if (!is_array($content)) {
            throw new ApiException(
                self::PROVIDER_NAME . ': AssumeRoleWithSAML returned empty response',
                0, [], $responseBody
            );
        }

        if (isset($content['ResponseMetadata']['Error'])) {
            throw new ApiException(
                self::PROVIDER_NAME . ': AssumeRoleWithSAML returned error: '
                . json_encode($content['ResponseMetadata']['Error']),
                0, [], $responseBody
            );
        }

        if (!isset($content['Result']['Credentials'])) {
            throw new ApiException(
                self::PROVIDER_NAME . ': AssumeRoleWithSAML returned no credentials',
                0, [], $responseBody
            );
        }

        $creds = $content['Result']['Credentials'];
        if (empty($creds['AccessKeyId']) || empty($creds['SecretAccessKey']) || empty($creds['SessionToken'])) {
            if (empty($creds['AccessKeyId'])) {
                $missing = 'AccessKeyId';
            } elseif (empty($creds['SecretAccessKey'])) {
                $missing = 'SecretAccessKey';
            } else {
                $missing = 'SessionToken';
            }
            throw new ApiException(
                self::PROVIDER_NAME . ': AssumeRoleWithSAML credentials missing field: ' . $missing,
                0, [], $responseBody
            );
        }
        $this->cachedCredentials = [
            'AccessKeyId' => $creds['AccessKeyId'],
            'SecretAccessKey' => $creds['SecretAccessKey'],
            'SessionToken' => $creds['SessionToken'],
            'ProviderName' => self::PROVIDER_NAME,
        ];
        $expirationValue = array_key_exists('Expiration', $creds)
            ? $creds['Expiration']
            : (isset($creds['ExpiredTime']) ? $creds['ExpiredTime'] : null);
        $expiration = is_string($expirationValue) ? strtotime($expirationValue) : false;
        if ($expiration === false) {
            throw new ApiException(
                self::PROVIDER_NAME . ': AssumeRoleWithSAML credentials missing or invalid expiration',
                0, [], $responseBody
            );
        }
        $this->expirationTime = $expiration - $this->expireBufferSeconds;
    }

    private function extractAccountId($roleTrn)
    {
        if (empty($roleTrn)) {
            return null;
        }
        $parts = explode(':', $roleTrn);
        if (count($parts) >= 4 && $parts[0] === 'trn' && $parts[1] === 'iam' && $parts[3] !== '') {
            return $parts[3];
        }
        return null;
    }
}
