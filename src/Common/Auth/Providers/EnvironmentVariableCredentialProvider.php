<?php

namespace Byteplus\Common\Auth\Providers;

use Byteplus\Common\ApiException;

class EnvironmentVariableCredentialProvider extends Provider
{
    const PROVIDER_NAME = 'EnvironmentVariableCredentialProvider';

    public function getCredentials()
    {
        $ak = $this->getEnvWithFallback('BYTEPLUS_ACCESSKEY', 'BYTEPLUS_ACCESS_KEY');
        $sk = $this->getEnvWithFallback('BYTEPLUS_SECRETKEY', 'BYTEPLUS_SECRET_KEY');
        $token = $this->getEnvWithFallback('BYTEPLUS_SESSION_TOKEN');

        if (empty($ak) || empty($sk)) {
            throw new ApiException(
                self::PROVIDER_NAME . ': required environment variables BYTEPLUS_ACCESSKEY and '
                . 'BYTEPLUS_SECRETKEY are not set'
            );
        }

        return [
            'AccessKeyId' => $ak,
            'SecretAccessKey' => $sk,
            'SessionToken' => $token ?: '',
            'ProviderName' => self::PROVIDER_NAME,
        ];
    }

    private function getEnvWithFallback()
    {
        foreach (func_get_args() as $name) {
            $value = getenv($name);
            if ($value !== false && $value !== '') {
                return $value;
            }
        }
        return null;
    }
}
