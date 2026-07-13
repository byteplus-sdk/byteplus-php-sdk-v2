<?php

namespace Byteplus\Common\Endpoint\Providers;

use Byteplus\Common\Endpoint\EndpointProvider;
use Byteplus\Common\Endpoint\ResolvedEndpoint;

define('OPEN_PREFIX', 'open');
define('ENDPOINT_SUFFIX', '.byteplusapi.com');
define('DUALSTACK_ENDPOINT_SUFFIX', '.byteplus-api.com');
define('FALLBACK_ENDPOINT', OPEN_PREFIX . '.ap-southeast-1' . ENDPOINT_SUFFIX);
define('OPEN_ENDPOINT', OPEN_PREFIX . ENDPOINT_SUFFIX);

define('REGION_CODE_CN_BEIJING_AUTO_DRIVING', 'cn-beijing-autodriving');
define('REGION_CODE_CN_SHANGHAI_AUTO_DRIVING', 'cn-shanghai-autodriving');
define('REGION_CODE_CN_BEIJING_SELFDRIVE', 'cn-beijing-selfdrive');
define('REGION_CODE_AP_SOUTHEAST2', 'ap-southeast-2');
define('REGION_CODE_AP_SOUTHEAST3', 'ap-southeast-3');
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
            'ark' => new ServiceEndpointInfo(
                'ark',
                false,
                '',
                [],
                OPEN_ENDPOINT
            ),
            'billing' => new ServiceEndpointInfo(
                'billing',
                true,
                '',
                [],
                OPEN_ENDPOINT
            ),
            'ecs' => new ServiceEndpointInfo(
                'ecs',
                false,
                '',
                []
            ),
            'vpc' => new ServiceEndpointInfo(
                'vpc',
                false,
                '',
                []
            ),
            'kms' => new ServiceEndpointInfo(
                'kms',
                false,
                '',
                []
            ),
            'eco_partner' => new ServiceEndpointInfo(
                'eco_partner',
                true,
                '',
                []
            ),
            'iam' => new ServiceEndpointInfo(
                    'iam',
                    false,
                '',
                []
            ),
            'cloudmonitor' => new ServiceEndpointInfo(
                'cloudmonitor',
                false,
                '',
                []
            ),
            'cpaas' => new ServiceEndpointInfo(
                'cpaas',
                true,
                '',
                []
            ),
            'vepfs' => new ServiceEndpointInfo(
                'vepfs',
                false,
                '',
                []
            ),
            'vke' => new ServiceEndpointInfo(
                'vke',
                false,
                '',
                []
            ),
            'kickart' => new ServiceEndpointInfo(
                'kickart',
                true,
                '',
                []
            ),
            'rds_mssql' => new ServiceEndpointInfo(
                'rds_mssql',
                false,
                '',
                []
            ),
            'sts' => new ServiceEndpointInfo(
                'sts',
                false,
                '',
                []
            ),
            'redis' => new ServiceEndpointInfo(
                'redis',
                false,
                '',
                []
            ),
            'vmp' => new ServiceEndpointInfo(
                'vmp',
                false,
                '',
                []
            ),
            'vs' => new ServiceEndpointInfo(
                'vs',
                true,
                '',
                []
            ),
            'resourcecenter' => new ServiceEndpointInfo(
                'resourcecenter',
                true,
                '',
                []
            ),
            'rds_mysql' => new ServiceEndpointInfo(
                'rds_mysql',
                false,
                '',
                []
            ),
            'privatelink' => new ServiceEndpointInfo(
                'privatelink',
                false,
                '',
                []
            ),
        ];
    }

    public function getDefaultEndpoint($service, $region, $suffix = ENDPOINT_SUFFIX)
    {
        self::ensureDefaultEndpoint();

        if (array_key_exists($service, self::$defaultEndpoint)) {
            $endpointInfo = self::$defaultEndpoint[$service];
            return $endpointInfo->getEndpointFor($region, $suffix);
        }

        return FALLBACK_ENDPOINT;
    }

    private function inBootstrapRegionList($region, $customBootstrapRegion)
    {
        $regionCode = trim($region);

        $bsRegionListPath = getenv('BYTEPLUS_BOOTSTRAP_REGION_LIST_CONF');
        if ($bsRegionListPath) {
            try {
                $content = file_get_contents($bsRegionListPath);
                $lines = explode("\n", $content);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!$line) {
                        continue;
                    }
                    if ($line == $regionCode) {
                        return true;
                    }
                }
            } catch (\Exception $e) {
                trigger_error(
                    'failed to read bootstrap region list from file ' . $bsRegionListPath . ': ' . $e->getMessage(),
                    E_USER_WARNING
                );
            }
        }

        $bootstrapRegion = [
            REGION_CODE_AP_SOUTHEAST2 => [],
            REGION_CODE_AP_SOUTHEAST3 => [],
        ];
        if ($bootstrapRegion && array_key_exists($regionCode, $bootstrapRegion)) {
            return true;
        }

        if ($customBootstrapRegion) {
            return is_array($customBootstrapRegion) && array_key_exists($regionCode, $customBootstrapRegion);
        }

        return false;
    }

    private static function hasEnabledDualstack($useDualStack)
    {
        if ($useDualStack === null) {
            return getenv('BYTEPLUS_ENABLE_DUALSTACK') == 'true';
        }

        return $useDualStack;
    }

    public function endpointFor($service, $region, $customBootstrapRegion = null, $useDualStack = null)
    {
        if (is_array($this->customEndpoints) && array_key_exists($service, $this->customEndpoints)) {
            $conf = $this->customEndpoints[$service];
            $host = $conf->getEndpointFor($region);
            return new ResolvedEndpoint($host);
        }

        if ($customBootstrapRegion === null) {
            $customBootstrapRegion = [];
        }

        if (!$this->inBootstrapRegionList($region, $customBootstrapRegion)) {
            self::ensureDefaultEndpoint();

            if (!array_key_exists($service, self::$defaultEndpoint)) {
                return new ResolvedEndpoint(FALLBACK_ENDPOINT);
            }

            return new ResolvedEndpoint(self::$defaultEndpoint[$service]->fallbackEndpoint);
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
    public $globalEndpoint;
    public $regionEndpointMap;
    public $fallbackEndpoint;

    public function __construct($service, $isGlobal, $globalEndpoint, $regionEndpointMap, $fallbackEndpoint = null)
    {
        $this->service = $service;
        $this->isGlobal = $isGlobal;
        $this->globalEndpoint = $globalEndpoint;
        $this->regionEndpointMap = $regionEndpointMap ?: [];
        $this->fallbackEndpoint = $fallbackEndpoint === null ? FALLBACK_ENDPOINT : $fallbackEndpoint;
    }

    private function getStandardizeDomainServiceCode()
    {
        return strtolower(str_replace('_', '-', $this->service));
    }

    private static function isCnRegion($region)
    {
        if (strpos($region, 'cn-') !== 0) {
            return false;
        }

        $cnNoneMainlandRegion = [
            REGION_CODE_CN_HONGKONG => [],
        ];

        return !array_key_exists($region, $cnNoneMainlandRegion);
    }

    public function getEndpointFor($region, $suffix = ENDPOINT_SUFFIX)
    {
        if ($this->isGlobal) {
            if ($this->globalEndpoint) {
                return $this->globalEndpoint;
            }

            return $this->getStandardizeDomainServiceCode() . $suffix;
        }

        if (array_key_exists($region, $this->regionEndpointMap)) {
            return $this->regionEndpointMap[$region];
        }

        $endpoint = $this->getStandardizeDomainServiceCode() . '.' . $region . $suffix;
        if (self::isCnRegion($region)) {
            $endpoint .= '.cn';
        }

        return $endpoint;
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
