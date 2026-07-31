<?php

namespace Byteplus\Common\Endpoint\Providers;

use Byteplus\Common\ApiException;
use Byteplus\Common\Endpoint\EndpointProvider;
use Byteplus\Common\Endpoint\ResolvedEndpoint;

define('OPEN_PREFIX', 'open');
define('ENDPOINT_SUFFIX', '.byteplusapi.com');
define('DUALSTACK_ENDPOINT_SUFFIX', '.byteplus-api.com');
define('CN_SUFFIX', '.cn');
define('OPEN_ENDPOINT', OPEN_PREFIX . ENDPOINT_SUFFIX);

define('REGION_CODE_CN_HONGKONG', 'cn-hongkong');

class DefaultEndpointProvider extends EndpointProvider
{
    public static $defaultEndpoint;

    private $customEndpoints;

    public function __construct($customEndpoints = [])
    {
        $this->customEndpoints = $customEndpoints ?: [];
    }

    private static function ensureDefaultEndpoint()
    {
        if (self::$defaultEndpoint !== null) {
            return;
        }

        self::$defaultEndpoint = [
            'vpc'            => new ServiceEndpointInfo('vpc', false, true),
            'vke'            => new ServiceEndpointInfo('vke', false, true),
            'auto_scaling'   => new ServiceEndpointInfo('auto_scaling', false, true),
            'storage_ebs'    => new ServiceEndpointInfo('storage_ebs', false, true),
            'vedbm'          => new ServiceEndpointInfo('vedbm', false, true),
            'privatelink'    => new ServiceEndpointInfo('privatelink', false, true),
            'clb'            => new ServiceEndpointInfo('clb', false, true),
            'transitrouter'  => new ServiceEndpointInfo('transitrouter', false, true),
            'directconnect'  => new ServiceEndpointInfo('directconnect', false, true),
            'vpn'            => new ServiceEndpointInfo('vpn', false, true),
            'natgateway'     => new ServiceEndpointInfo('natgateway', false, true),
            'rds_mysql'      => new ServiceEndpointInfo('rds_mysql', false, true),
            'smc'            => new ServiceEndpointInfo('smc', true, false),
            'iam'            => new ServiceEndpointInfo('iam', true, true),
            'vepfs'          => new ServiceEndpointInfo('vepfs', false, true),
            'kms'            => new ServiceEndpointInfo('kms', false, true),
            'ecs'            => new ServiceEndpointInfo('ecs', false, true),
            'mongodb'        => new ServiceEndpointInfo('mongodb', false, true),
            'private_zone'   => new ServiceEndpointInfo('private_zone', true, true),
            'rds_postgresql' => new ServiceEndpointInfo('rds_postgresql', false, true),
            'resource_share' => new ServiceEndpointInfo('resource_share', true, false),
            'vmp'            => new ServiceEndpointInfo('vmp', false, true),
            'tag'            => new ServiceEndpointInfo('tag', true, false),
            'cr'             => new ServiceEndpointInfo('cr', false, true),
            'alb'            => new ServiceEndpointInfo('alb', false, true),
            'sts'            => new ServiceEndpointInfo('sts', false, true),
            'hbase'          => new ServiceEndpointInfo('hbase', false, true),
            'rds_mssql'      => new ServiceEndpointInfo('rds_mssql', false, true),
            'ml_platform'    => new ServiceEndpointInfo('ml_platform', false, false),
            'apig'           => new ServiceEndpointInfo('apig', false, false),
            'ark'            => new ServiceEndpointInfo('ark', false, false),
            'waf'            => new ServiceEndpointInfo('waf', true, false),
            'quota'          => new ServiceEndpointInfo('quota', true, false),
            'dms'            => new ServiceEndpointInfo('dms', false, true),
            'vefaas'         => new ServiceEndpointInfo('vefaas', false, false),
            'cen'            => new ServiceEndpointInfo('cen', true, false),
            'cp'             => new ServiceEndpointInfo('cp', false, false),
            'cloudmonitor'   => new ServiceEndpointInfo('cloudmonitor', false, true),
            'eco_partner'    => new ServiceEndpointInfo('eco_partner', true, false),
            'milvus'         => new ServiceEndpointInfo('milvus', false, false),
            'llmshield'      => new ServiceEndpointInfo('llmshield', false, false),
            'billing'        => new ServiceEndpointInfo('billing', true, true),
            'id'             => new ServiceEndpointInfo('id', false, false),
            'clawsentry'     => new ServiceEndpointInfo('clawsentry', false, false),
            'resourcecenter' => new ServiceEndpointInfo('resourcecenter', true, false),
            'escloud'        => new ServiceEndpointInfo('escloud', false, false),
            'cpaas'          => new ServiceEndpointInfo('cpaas', true, false),
            'filenas'        => new ServiceEndpointInfo('filenas', false, true),
            'kafka'          => new ServiceEndpointInfo('kafka', false, true),
            'kickart'        => new ServiceEndpointInfo('kickart', true, false),
            'rabbitmq'       => new ServiceEndpointInfo('rabbitmq', false, false),
            'redis'          => new ServiceEndpointInfo('redis', false, true),
            'vod'            => new ServiceEndpointInfo('vod', false, false),
            'vs'             => new ServiceEndpointInfo('vs', true, false),
        ];
    }

    /**
     * Normalize a region code for lookup: trim + lowercase. Never modify the
     * original region used for signing.
     */
    public static function normalizeRegion($region)
    {
        if ($region === null) {
            return '';
        }
        return strtolower(trim($region));
    }

    /**
     * @throws ApiException when service is not registered in the default endpoint map.
     */
    public function getDefaultEndpoint($service, $region, $suffix = ENDPOINT_SUFFIX)
    {
        self::ensureDefaultEndpoint();

        if (!array_key_exists($service, self::$defaultEndpoint)) {
            throw new ApiException("service '" . $service . "' not registered in default endpoint map");
        }

        return self::$defaultEndpoint[$service]->getEndpointFor($region, $suffix);
    }

    /**
     * Deprecated. Retained for signature compatibility only; the return value
     * is never consulted by the default resolution pipeline.
     */
    private function inBootstrapRegionList($region, $customBootstrapRegion)
    {
        return false;
    }

    private static function hasEnabledDualstack($useDualStack)
    {
        if ($useDualStack === null) {
            return getenv('BYTEPLUS_ENABLE_DUALSTACK') == 'true';
        }

        return $useDualStack;
    }

    /**
     * @param string $service
     * @param string $region
     * @param mixed  $customBootstrapRegion Deprecated; retained for signature compatibility, no-op at runtime.
     * @param bool|null $useDualStack
     * @return ResolvedEndpoint
     * @throws ApiException when service is not registered in the default endpoint map.
     */
    public function endpointFor($service, $region, $customBootstrapRegion = null, $useDualStack = null)
    {
        if (is_array($this->customEndpoints) && array_key_exists($service, $this->customEndpoints)) {
            $conf = $this->customEndpoints[$service];
            $host = $conf->getEndpointFor($region);
            return new ResolvedEndpoint($host);
        }

        $suffix = self::hasEnabledDualstack($useDualStack) ? DUALSTACK_ENDPOINT_SUFFIX : ENDPOINT_SUFFIX;
        $host = $this->getDefaultEndpoint($service, $region, $suffix);

        return new ResolvedEndpoint($host);
    }
}

class ServiceEndpointInfo
{
    public $service;
    public $isGlobal;
    public $goChinaEnabled;

    public function __construct($service, $isGlobal, $goChinaEnabled = false)
    {
        $this->service = $service;
        $this->isGlobal = (bool) $isGlobal;
        $this->goChinaEnabled = (bool) $goChinaEnabled;
    }

    private function getStandardizeDomainServiceCode()
    {
        return strtolower(str_replace('_', '-', $this->service));
    }

    /**
     * Checks whether the (already normalized) region belongs to mainland China.
     * Regions in the non-mainland whitelist (e.g. cn-hongkong) do not receive
     * the .cn suffix and use the international .com apex.
     */
    private static function isCNMainlandRegion($normalizedRegion)
    {
        if (strpos($normalizedRegion, 'cn-') !== 0) {
            return false;
        }
        $cnNoneMainland = [
            REGION_CODE_CN_HONGKONG => true,
        ];
        return !array_key_exists($normalizedRegion, $cnNoneMainland);
    }

    public function getEndpointFor($region, $suffix = ENDPOINT_SUFFIX)
    {
        $normalized = DefaultEndpointProvider::normalizeRegion($region);
        $cnSuffix = ($this->goChinaEnabled && self::isCNMainlandRegion($normalized)) ? CN_SUFFIX : '';
        $serviceCode = $this->getStandardizeDomainServiceCode();

        if ($this->isGlobal) {
            return $serviceCode . $suffix . $cnSuffix;
        }

        return $serviceCode . '.' . $normalized . $suffix . $cnSuffix;
    }
}

class HostEndpointProvider extends EndpointProvider
{
    private $host;

    public function __construct($host)
    {
        $this->host = $host;
    }

    public function endpointFor($service, $region, $customBootstrapRegion = null, $useDualStack = null)
    {
        return new ResolvedEndpoint($this->host);
    }
}

?>
