<?php

namespace Byteplus\Common\Endpoint\Providers;

use Byteplus\Common\Endpoint\EndpointProvider;
use Byteplus\Common\Endpoint\ResolvedEndpoint;

class StandardEndpointProvider extends EndpointProvider
{
    public function endpointFor($service, $region, $customBootstrapRegion = null, $useDualStack = null)
    {
        $suffix = self::hasEnabledDualstack($useDualStack) ? DUALSTACK_ENDPOINT_SUFFIX : ENDPOINT_SUFFIX;
        $defaultProvider = new DefaultEndpointProvider();
        $host = $defaultProvider->getDefaultEndpoint($service, $region, $suffix);
        return new ResolvedEndpoint($host);
    }

    private static function hasEnabledDualstack($useDualStack)
    {
        if ($useDualStack === null) {
            return getenv("BYTEPLUS_ENABLE_DUALSTACK") == 'true';
        }
        return $useDualStack;
    }
}
