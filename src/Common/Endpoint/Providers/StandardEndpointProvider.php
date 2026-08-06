<?php

namespace Byteplus\Common\Endpoint\Providers;

use Byteplus\Common\Endpoint\EndpointProvider;
use Byteplus\Common\Endpoint\ResolvedEndpoint;

class StandProviderError extends \Exception
{
    private $standCode;

    public function __construct($code, $message)
    {
        $this->standCode = $code;
        parent::__construct($code . ': ' . $message);
    }

    public function getStandCode()
    {
        return $this->standCode;
    }
}

class StandardEndpointResolverVariable
{
    public $service = '';
    public $region = '';
    public $siteStack = '';
    public $cnSuffix = '';
    public $extension = [];

    public function toArray()
    {
        return [
            'Service' => $this->service,
            'Region' => $this->region,
            'SiteStack' => $this->siteStack,
            'CNSuffix' => $this->cnSuffix,
            'Extension' => $this->extension,
        ];
    }
}

class ServiceInfo
{
    public $service;
    public $IsGlobal;

    public function __construct($service, $isGlobal)
    {
        $this->service = $service;
        $this->IsGlobal = $isGlobal;
    }
}

class RegionChecker
{
    private $whiteRegions;
    private $pattern;

    public function __construct($whiteRegions, $pattern)
    {
        $this->whiteRegions = $whiteRegions ?: [];
        $this->pattern = $pattern;
    }

    public function validate($region)
    {
        if ($this->whiteRegions && array_key_exists($region, $this->whiteRegions)) {
            return true;
        }

        if ($this->pattern) {
            return preg_match($this->pattern, $region) === 1;
        }

        return false;
    }
}

class StandardEndpointProvider extends EndpointProvider
{
    const DEFAULT_FORMAT = '{Service}{Region}.{SiteStack}.com{CNSuffix}';
    const SITE_STACK_BYTEPLUS_IPV4 = 'byteplusapi';
    const SITE_STACK_BYTEPLUS_DUAL_STACK = 'byteplus-api';
    const CN_SUFFIX = '.cn';

    private static $serviceInfos;
    private static $regionMatcher;

    private $fmt;
    private $variables;
    private $customServices;

    public function __construct($fmt = null, $siteStack = null, $extension = null, $customServices = null)
    {
        $this->fmt = $fmt ?: self::DEFAULT_FORMAT;
        $this->variables = new StandardEndpointResolverVariable();
        $this->variables->siteStack = $siteStack ?: self::SITE_STACK_BYTEPLUS_IPV4;
        $this->variables->extension = $extension ?: [];
        $this->customServices = $customServices ?: [];
    }

    private static function serviceInfos()
    {
        if (self::$serviceInfos !== null) {
            return self::$serviceInfos;
        }

        self::$serviceInfos = [
            'vpc' => new ServiceInfo('vpc', false),
            'ecs' => new ServiceInfo('ecs', false),
            'billing' => new ServiceInfo('billing', true),
            'ark' => new ServiceInfo('ark', false),
            'iam' => new ServiceInfo('iam', true),
            'mcs' => new ServiceInfo('mcs', false),
            'rocketmq' => new ServiceInfo('rocketmq', false),
            'bytehouse' => new ServiceInfo('bytehouse', false),
            'dns' => new ServiceInfo('dns', true),
            'autoscaling' => new ServiceInfo('autoscaling', false),
            'spark' => new ServiceInfo('spark', false),
            'cloud_detect' => new ServiceInfo('cloud_detect', false),
            'filenas' => new ServiceInfo('filenas', false),
            'escloud' => new ServiceInfo('escloud', false),
            'flink' => new ServiceInfo('flink', false),
            'cp' => new ServiceInfo('cp', false),
            'vefaas' => new ServiceInfo('vefaas', false),
            'ml_platform' => new ServiceInfo('ml_platform', false),
            'edx' => new ServiceInfo('edx', true),
            'dcdn' => new ServiceInfo('dcdn', true),
            'cdn' => new ServiceInfo('cdn', true),
            'kafka' => new ServiceInfo('kafka', false),
            'certificate_service' => new ServiceInfo('certificate_service', true),
            'waf' => new ServiceInfo('waf', true),
            'rds_mssql' => new ServiceInfo('rds_mssql', false),
            'cloudtrail' => new ServiceInfo('cloudtrail', false),
            'vei_api' => new ServiceInfo('vei_api', true),
            'cen' => new ServiceInfo('cen', true),
            'rabbitmq' => new ServiceInfo('rabbitmq', false),
            'vmp' => new ServiceInfo('vmp', false),
            'volc_observe' => new ServiceInfo('volc_observe', false),
            'dataleap' => new ServiceInfo('dataleap', false),
            'fw_center' => new ServiceInfo('fw_center', true),
            'redis' => new ServiceInfo('redis', false),
            'mcdn' => new ServiceInfo('mcdn', true),
            'cloudidentity' => new ServiceInfo('cloudidentity', false),
            'vedbm' => new ServiceInfo('vedbm', false),
            'cv' => new ServiceInfo('cv', true),
            'translate' => new ServiceInfo('translate', true),
            'cloud_trail' => new ServiceInfo('cloud_trail', false),
            'bio' => new ServiceInfo('bio', false),
            'nta' => new ServiceInfo('nta', true),
            'elasticmapreduce' => new ServiceInfo('elasticmapreduce', false),
            'vepfs' => new ServiceInfo('vepfs', false),
            'seccenter' => new ServiceInfo('seccenter', true),
            'advdefence' => new ServiceInfo('advdefence', true),
            'tis' => new ServiceInfo('tis', true),
            'organization' => new ServiceInfo('organization', true),
            'vke' => new ServiceInfo('vke', false),
            'Redis' => new ServiceInfo('Redis', false),
            'privatelink' => new ServiceInfo('privatelink', false),
            'RocketMQ' => new ServiceInfo('RocketMQ', false),
            'Kafka' => new ServiceInfo('Kafka', false),
            'rds_mysql' => new ServiceInfo('rds_mysql', false),
            'rds_postgresql' => new ServiceInfo('rds_postgresql', false),
            'storage_ebs' => new ServiceInfo('storage_ebs', false),
            'clb' => new ServiceInfo('clb', false),
            'alb' => new ServiceInfo('alb', false),
            'FileNAS' => new ServiceInfo('FileNAS', false),
            'configcenter' => new ServiceInfo('configcenter', false),
            'cr' => new ServiceInfo('cr', false),
            'sts' => new ServiceInfo('sts', false),
            'mongodb' => new ServiceInfo('mongodb', false),
            'transitrouter' => new ServiceInfo('transitrouter', false),
            'Volc_Observe' => new ServiceInfo('Volc_Observe', false),
            'dms' => new ServiceInfo('dms', false),
            'auto_scaling' => new ServiceInfo('auto_scaling', false),
            'directconnect' => new ServiceInfo('directconnect', false),
            'kms' => new ServiceInfo('kms', false),
            'dbw' => new ServiceInfo('dbw', false),
            'dts' => new ServiceInfo('dts', false),
            'natgateway' => new ServiceInfo('natgateway', false),
            'tos' => new ServiceInfo('tos', false),
            'TLS' => new ServiceInfo('TLS', false),
            'vpn' => new ServiceInfo('vpn', false),
            'vod' => new ServiceInfo('vod', false),
            'quota' => new ServiceInfo('quota', true),
            'ecs_ops' => new ServiceInfo('ecs_ops', true),
            'as_ops' => new ServiceInfo('as_ops', true),
            'account_management' => new ServiceInfo('account_management', true),
            'account_management_byteplus' => new ServiceInfo('account_management_byteplus', true),
            'bandwidthquota' => new ServiceInfo('bandwidthquota', true),
            'psa_manager' => new ServiceInfo('psa_manager', true),
            'dc_controller' => new ServiceInfo('dc_controller', false),
            'eps_platform_trade' => new ServiceInfo('eps_platform_trade', false),
            'eps_platform_fund' => new ServiceInfo('eps_platform_fund', false),
            'commercialization' => new ServiceInfo('commercialization', true),
            'veecp_openapi' => new ServiceInfo('veecp_openapi', false),
            'orgnization' => new ServiceInfo('orgnization', true),
            'coze' => new ServiceInfo('coze', true),
            'sec_agent' => new ServiceInfo('sec_agent', true),
            'sec_intelligent_dev' => new ServiceInfo('sec_intelligent_dev', true),
            'vegame' => new ServiceInfo('vegame', false),
            'acep' => new ServiceInfo('acep', true),
            'private_zone' => new ServiceInfo('private_zone', true),
            'sqs' => new ServiceInfo('sqs', false),
            'resourcecenter' => new ServiceInfo('resourcecenter', true),
            'aiotvideo' => new ServiceInfo('aiotvideo', true),
            'apig' => new ServiceInfo('apig', false),
            'bmq' => new ServiceInfo('bmq', false),
            'bytehouse_ce' => new ServiceInfo('bytehouse_ce', false),
            'cloudmonitor' => new ServiceInfo('cloudmonitor', false),
            'emr' => new ServiceInfo('emr', false),
            'ga' => new ServiceInfo('ga', true),
            'graph' => new ServiceInfo('graph', false),
            'gtm' => new ServiceInfo('gtm', true),
            'hbase' => new ServiceInfo('hbase', false),
            'metakms' => new ServiceInfo('metakms', false),
            'na' => new ServiceInfo('na', true),
            'resource_share' => new ServiceInfo('resource_share', true),
            'speech_saas_prod' => new ServiceInfo('speech_saas_prod', true),
            'tag' => new ServiceInfo('tag', true),
            'vefaas_dev' => new ServiceInfo('vefaas_dev', false),
            'vms' => new ServiceInfo('vms', false),
            'eco_partner' => new ServiceInfo('eco_partner', true),
            'smc' => new ServiceInfo('smc', true),
            'cpaas' => new ServiceInfo('cpaas', true),
            'kickart' => new ServiceInfo('kickart', true),
            'vs' => new ServiceInfo('vs', true),
        ];

        return self::$serviceInfos;
    }

    private static function regionMatcher()
    {
        if (self::$regionMatcher !== null) {
            return self::$regionMatcher;
        }

        self::$regionMatcher = new RegionChecker(
            [
                'ap-singapore-1' => [],
                'ap-southeast-1' => [],
                'ap-southeast-2' => [],
                'ap-southeast-3' => [],
                'byteplus-global' => [],
                'cn-beijing' => [],
                'cn-beijing-autodriving' => [],
                'cn-beijing-selfdrive' => [],
                'cn-beijing2' => [],
                'cn-beijing300' => [],
                'cn-changsha-sdv' => [],
                'cn-chengdu' => [],
                'cn-chengdu-sdv' => [],
                'cn-chongqing-sdv' => [],
                'cn-datong' => [],
                'cn-east-1-dedicated' => [],
                'cn-gaofang-bj' => [],
                'cn-gaofang-gz1' => [],
                'cn-gaofang-nt1' => [],
                'cn-gaofang-nt2' => [],
                'cn-gaofang-nt3' => [],
                'cn-gaofang-nt4' => [],
                'cn-gaofang-nt5' => [],
                'cn-guangzhou' => [],
                'cn-guilin-boe' => [],
                'cn-hangzhou' => [],
                'cn-hjxj' => [],
                'cn-hjzg' => [],
                'cn-hlbx' => [],
                'cn-hlxj' => [],
                'cn-hlzg' => [],
                'cn-hongkong' => [],
                'cn-hongkong-pop' => [],
                'cn-lfbx' => [],
                'cn-lfxj' => [],
                'cn-lfzg' => [],
                'cn-macau-pop-sdv' => [],
                'cn-mainland' => [],
                'cn-nanjing-bbit' => [],
                'cn-ningbo-sdv' => [],
                'cn-north-1' => [],
                'cn-north-1-dedicated' => [],
                'cn-north-boe' => [],
                'cn-shanghai' => [],
                'cn-shanghai-autodriving' => [],
                'cn-taiwan-boe' => [],
                'cn-wuhan' => [],
                'cn-wulanchabu' => [],
                'cn-xian-boe-sdv' => [],
                'overseas-1' => [],
                'rec-cn' => [],
                'rec-sg' => [],
            ],
            '/^(?:[a-z]{2}-[a-z]+(?:-[a-z]+)?|(?:cn|ap|eu|na|sa|me|af)-[a-z]+-\d+(?:-(?:finance|exclusive|local|inner))?)$/'
        );

        return self::$regionMatcher;
    }

    private static function standardizeDomainServiceCode($serviceCode)
    {
        return strtolower(str_replace('_', '-', $serviceCode));
    }

    private static function isGoChina($region)
    {
        if (strpos($region, 'cn-') !== 0) {
            return false;
        }

        $cnNonMainlandRegion = [
            'cn-hongkong' => [],
        ];

        return !array_key_exists($region, $cnNonMainlandRegion);
    }

    private static function formatExtension($extension)
    {
        if (!$extension) {
            return '{}';
        }

        $parts = [];
        foreach ($extension as $key => $value) {
            $parts[] = "'" . $key . "': '" . $value . "'";
        }

        return '{' . implode(', ', $parts) . '}';
    }

    private function renderTemplate($fmt)
    {
        $values = [
            'Service' => $this->variables->service,
            'Region' => $this->variables->region,
            'SiteStack' => $this->variables->siteStack,
            'CNSuffix' => $this->variables->cnSuffix,
            'Extension' => self::formatExtension($this->variables->extension),
        ];

        if (preg_match_all('/\{([^{}]+)\}/', $fmt, $matches)) {
            foreach ($matches[1] as $key) {
                if (!array_key_exists($key, $values)) {
                    throw new StandProviderError(
                        'TemplateExecuteError',
                        'failed to execute template for format ' . $fmt . ', missing variable ' . $key
                    );
                }
            }
        }

        $replacements = [];
        foreach ($values as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }

        return strtr($fmt, $replacements);
    }

    public function endpointFor($service, $region, $customBootstrapRegion = null, $useDualStack = null)
    {
        if (!self::regionMatcher()->validate($region)) {
            throw new StandProviderError(
                'InvalidRegion',
                'invalid region ' . $region . ' for standard endpoint resolver, please upgrade the sdk endpoint resolver to the latest version'
            );
        }

        $fmt = $this->fmt ?: self::DEFAULT_FORMAT;
        $this->variables->region = '';
        $this->variables->cnSuffix = '';
        $this->variables->service = self::standardizeDomainServiceCode($service);

        $serviceInfos = self::serviceInfos();
        $svcInfo = null;
        if (array_key_exists($service, $serviceInfos)) {
            $svcInfo = $serviceInfos[$service];
        } elseif (is_array($this->customServices) && array_key_exists($service, $this->customServices)) {
            $svcInfo = $this->customServices[$service];
        }

        if (!$svcInfo) {
            throw new StandProviderError(
                'ServiceNotFound',
                'service ' . $service . ' not found in ServiceInfos or customServices , please upgrade the sdk endpoint resolver to the latest version'
            );
        }

        if (!$svcInfo->IsGlobal) {
            $this->variables->region = '.' . $region;
        } else {
            $this->variables->region = '';
        }

        if ($useDualStack) {
            $this->variables->siteStack = self::SITE_STACK_BYTEPLUS_DUAL_STACK;
        } else {
            $this->variables->siteStack = self::SITE_STACK_BYTEPLUS_IPV4;
        }

        if (!$svcInfo->IsGlobal && self::isGoChina($region)) {
            $this->variables->cnSuffix = self::CN_SUFFIX;
        }

        return new ResolvedEndpoint($this->renderTemplate($fmt));
    }
}

?>
