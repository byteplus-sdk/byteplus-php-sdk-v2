<?php

namespace Byteplus\Common {
    // Counts HMAC operations so tests can prove that no signature was computed.
    function hash_hmac($algo, $data, $key, $rawOutput = false)
    {
        $GLOBALS['endpoint_ssrf_sign_calls']++;
        return \hash_hmac($algo, $data, $key, $rawOutput);
    }
}

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../src/Common/autoload.php';

    use GuzzleHttp\Client;
    use GuzzleHttp\Promise\FulfilledPromise;
    use GuzzleHttp\Psr7\Response;
    use Byteplus\Common\ApiClient;
    use Byteplus\Common\ApiException;
    use Byteplus\Common\Auth\Providers\SsoCredentialProvider;
    use Byteplus\Common\Auth\Providers\StsProvider;
    use Byteplus\Common\Configuration;
    use Byteplus\Common\Endpoint\EndpointProvider;
    use Byteplus\Common\Endpoint\ResolvedEndpoint;
    use Byteplus\Common\Endpoint\Providers\DefaultEndpointProvider;
    use Byteplus\Common\Endpoint\Providers\ServiceEndpointInfo;
    use Byteplus\Common\Endpoint\Providers\StandardEndpointProvider;
    use Byteplus\Common\Feature\Rds\ConnectUtils;
    use Byteplus\Common\Interceptor\Interceptors\Context;
    use Byteplus\Common\Interceptor\Interceptors\Request;
    use Byteplus\Common\Interceptor\Interceptors\ResolveEndpointInterceptor;
    use Byteplus\Common\UniversalApi;
    use Byteplus\Common\UniversalInfo;
    use Byteplus\Ecs\Api\ECSApi;

    // Keep results independent from the caller's environment.
    foreach (['BYTEPLUS_ENABLE_DUALSTACK', 'BYTEPLUS_PROFILE', 'BYTEPLUS_CLI_CONFIG_FILE', 'BYTEPLUS_ECS_METADATA', 'BYTEPLUS_ECS_METADATA_DISABLED'] as $name) {
        putenv($name);
    }
    foreach (['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy', 'NO_PROXY', 'no_proxy'] as $name) {
        putenv($name);
        unset($_SERVER[$name], $_ENV[$name]);
    }

    $GLOBALS['endpoint_ssrf_sign_calls'] = 0;
    $GLOBALS['checks'] = 0;

    const LABEL_RULE = "must start with a letter, end with a letter or digit, and contain only letters, digits, '-' and '_'";
    const ORACLE = '/\A[A-Za-z](?:[A-Za-z0-9_-]*[A-Za-z0-9])?\z/';

    function check($condition, $label)
    {
        $GLOBALS['checks']++;
        if (!$condition) {
            throw new \RuntimeException('check failed: ' . $label);
        }
    }

    function label_message($kind, $quoted, $reason)
    {
        return 'invalid ' . $kind . ' ' . $quoted . ': ' . $reason . ', ' . $kind . ' ' . LABEL_RULE;
    }

    // Runs $fn and requires the exact endpoint label error, raised before any signature is computed.
    function rejects($code, $message, $fn, $label)
    {
        $signCalls = $GLOBALS['endpoint_ssrf_sign_calls'];
        try {
            $result = $fn();
            if ($result instanceof \GuzzleHttp\Promise\PromiseInterface) {
                $result->wait();
            }
        } catch (ApiException $e) {
            check($e->getErrorCode() === $code, $label . ': error code ' . var_export($e->getErrorCode(), true) . ' (' . $e->getMessage() . ')');
            if ($message !== null) {
                check($e->getErrorMessage() === $message, $label . ': error message ' . $e->getErrorMessage());
                check($e->getMessage() === $code . ': ' . $message, $label . ': exception message ' . $e->getMessage());
            }
            check($e->getStatusCode() === 400 && $e->getCode() === 400, $label . ': status code');
            check($GLOBALS['endpoint_ssrf_sign_calls'] === $signCalls, $label . ': a signature was computed');
            return $e;
        }
        throw new \RuntimeException($label . ': input was accepted');
    }

    function accepts($fn, $label)
    {
        try {
            $result = $fn();
            if ($result instanceof \GuzzleHttp\Promise\PromiseInterface) {
                $result = $result->wait();
            }
            $GLOBALS['checks']++;
            return $result;
        } catch (\Exception $e) {
            throw new \RuntimeException($label . ': unexpected ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    class FailingEndpointProvider extends EndpointProvider
    {
        public function endpointFor($service, $region, $customBootstrapRegion = null, $useDualStack = null)
        {
            throw new \LogicException('custom resolver must not be called, got ' . var_export([$service, $region], true));
        }
    }

    class EchoEndpointProvider extends EndpointProvider
    {
        public $calls = 0;

        public function endpointFor($service, $region, $customBootstrapRegion = null, $useDualStack = null)
        {
            $this->calls++;
            return new ResolvedEndpoint($service . '.' . $region . '.internal');
        }
    }

    class StringableLabel
    {
        private $value;

        public function __construct($value)
        {
            $this->value = $value;
        }

        public function __toString()
        {
            return $this->value;
        }
    }

    function http_client(&$sent)
    {
        return new Client(['handler' => function ($request) use (&$sent) {
            $sent[] = $request;
            return new FulfilledPromise(new Response(200, [], '{"ResponseMetadata":{},"Result":{}}'));
        }]);
    }

    function configuration($region = 'cn-beijing')
    {
        return (new Configuration())->setRegion($region)->setAk('test-ak')->setSk('test-sk')->setUseDualStack(false);
    }

    function private_value($objectOrClass, $name)
    {
        $property = new \ReflectionProperty(is_object($objectOrClass) ? get_class($objectOrClass) : $objectOrClass, $name);
        $property->setAccessible(true);
        return $property->isStatic() ? $property->getValue() : $property->getValue($objectOrClass);
    }

    function call_private($object, $method, array $args = [])
    {
        $ref = new \ReflectionMethod(get_class($object), $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $args);
    }

    function call_private_static($class, $method)
    {
        $ref = new \ReflectionMethod($class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(null);
    }

    function standardize($service)
    {
        return strtolower(str_replace('_', '-', $service));
    }

    // value => [illegal index, quoted rendering]
    $injections = [
        'cn-beijing@127.0.0.1' => [10, '"cn-beijing@127.0.0.1"'],
        'cn-beijing:443' => [10, '"cn-beijing:443"'],
        'cn/beijing' => [2, '"cn/beijing"'],
        'cn?beijing' => [2, '"cn?beijing"'],
        'cn#beijing' => [2, '"cn#beijing"'],
        'cn beijing' => [2, '"cn beijing"'],
        "cn\tbeijing" => [2, '"cn\x09beijing"'],
        "cn\nbeijing" => [2, '"cn\x0abeijing"'],
        "cn\rbeijing" => [2, '"cn\x0dbeijing"'],
        "cn\x00beijing" => [2, '"cn\x00beijing"'],
        'evil.com' => [4, '"evil.com"'],
        'cn%2fbeijing' => [2, '"cn%2fbeijing"'],
        'cn\\beijing' => [2, '"cn\\\\beijing"'],
        '北京' => [0, '"\xe5\x8c\x97\xe4\xba\xac"'],
        'cn-北京' => [3, '"cn-\xe5\x8c\x97\xe4\xba\xac"'],
        "cn-beijing\n" => [10, '"cn-beijing\x0a"'],
    ];
    $boundary = [
        '1cn-beijing' => 'must start with a letter',
        '1' => 'must start with a letter',
        '-cn-beijing' => 'must start with a letter',
        '_ecs' => 'must start with a letter',
        'cn-beijing-' => "must not end with '-' or '_'",
        'rds_mysql_' => "must not end with '-' or '_'",
        '-' => 'must start with a letter',
        '_' => 'must start with a letter',
    ];
    $valid = ['cn-beijing', 'ap-southeast-1', 'a', 'rds_mysql', 'Redis', 'Rds_mysql-2', ''];

    // [code, expected message] for every invalid input of a label kind.
    function invalid_cases($kind)
    {
        global $injections, $boundary;
        $code = $kind === 'region' ? 'InvalidRegion' : 'InvalidService';
        $cases = [];
        foreach ($injections as $value => $expected) {
            $cases[] = [(string) $value, $code, label_message($kind, $expected[1], 'illegal character at index ' . $expected[0])];
        }
        foreach ($boundary as $value => $reason) {
            $cases[] = [(string) $value, $code, label_message($kind, '"' . $value . '"', $reason)];
        }
        return $cases;
    }

    // 1. Rules, reasons and messages of the shared validators.
    foreach (['region' => 'validateRegion', 'service' => 'validateService'] as $kind => $method) {
        foreach (invalid_cases($kind) as $case) {
            list($value, $code, $message) = $case;
            rejects($code, $message, function () use ($method, $value) {
                EndpointProvider::$method($value);
            }, $method . ' ' . json_encode($value));
        }
        foreach ($valid as $value) {
            accepts(function () use ($method, $value) { EndpointProvider::$method($value); }, $method . ' valid ' . $value);
        }
        accepts(function () use ($method) { EndpointProvider::$method(null); }, $method . ' null');
        accepts(function () use ($method) { EndpointProvider::$method(false); }, $method . ' false');
        accepts(function () use ($method) { EndpointProvider::$method(new StringableLabel('cn-beijing')); }, $method . ' stringable');
        $code = $kind === 'region' ? 'InvalidRegion' : 'InvalidService';
        $nonStrings = [
            [true, '"1"', 'must start with a letter'],
            [12, '"12"', 'must start with a letter'],
            [1.5, '"1.5"', 'illegal character at index 1'],
            [[], '"[array]"', 'illegal character at index 0'],
            [new \stdClass(), '"[object]"', 'illegal character at index 0'],
            [new StringableLabel('evil.com'), '"evil.com"', 'illegal character at index 4'],
        ];
        foreach ($nonStrings as $case) {
            $value = $case[0];
            rejects($code, label_message($kind, $case[1], $case[2]), function () use ($method, $value) {
                EndpointProvider::$method($value);
            }, $method . ' non-string ' . gettype($value));
        }
    }

    // Escaping of control bytes, non-ASCII bytes and quotes.
    $quotes = [
        "\xff" => '"\xff"',
        "a\xe4\xb8" => '"a\xe4\xb8"',
        "\x07\x1b\x7f" => '"\x07\x1b\x7f"',
        'a"b' => '"a\"b"',
    ];
    foreach ($quotes as $value => $quoted) {
        rejects('InvalidRegion', label_message('region', $quoted, 'illegal character at index ' . (strpos($value, 'a') === 0 ? 1 : 0)), function () use ($value) {
            EndpointProvider::validateRegion($value);
        }, 'quote ' . bin2hex($value));
    }

    // Service is checked before region.
    rejects('InvalidService', null, function () {
        EndpointProvider::validateServiceAndRegion('ecs@evil', 'cn@evil');
    }, 'service first');
    rejects('InvalidRegion', null, function () {
        EndpointProvider::validateServiceAndRegion('ecs', 'cn@evil');
    }, 'region second');

    // Exhaustive equivalence with the reference regular expression over all 1- and 2-byte strings.
    $inputs = [];
    for ($a = 0; $a < 256; $a++) {
        $inputs[] = chr($a);
        for ($b = 0; $b < 256; $b++) {
            $inputs[] = chr($a) . chr($b);
        }
    }
    $exhaustive = 0;
    foreach ($inputs as $value) {
        $expectedValid = preg_match(ORACLE, $value) === 1;
        if (preg_match('/[^A-Za-z0-9_-]/', $value, $m, PREG_OFFSET_CAPTURE)) {
            $reason = 'illegal character at index ' . $m[0][1];
        } elseif (!preg_match('/\A[A-Za-z]/', $value)) {
            $reason = 'must start with a letter';
        } else {
            $reason = "must not end with '-' or '_'";
        }
        foreach (['validateRegion' => 'InvalidRegion', 'validateService' => 'InvalidService'] as $method => $code) {
            try {
                EndpointProvider::$method($value);
                $actualValid = true;
            } catch (ApiException $e) {
                $actualValid = false;
                if ($e->getErrorCode() !== $code || strpos($e->getErrorMessage(), ': ' . $reason . ', ') === false) {
                    throw new \RuntimeException('exhaustive reason mismatch for ' . bin2hex($value) . ': ' . $e->getMessage());
                }
            }
            if ($actualValid !== $expectedValid) {
                throw new \RuntimeException('exhaustive mismatch for ' . bin2hex($value));
            }
            $exhaustive++;
        }
    }
    check($exhaustive === 2 * (256 + 256 * 256), 'exhaustive coverage');

    // 2. Every resolver and default endpoint generator rejects before building a host.
    $failing = new FailingEndpointProvider();
    foreach (['region', 'service'] as $kind) {
        foreach (invalid_cases($kind) as $case) {
            list($value, $code, $message) = $case;
            $service = $kind === 'service' ? $value : 'ecs';
            $region = $kind === 'region' ? $value : 'ap-southeast-2';
            $label = $kind . ' ' . json_encode($value);
            $default = new DefaultEndpointProvider();
            $custom = new DefaultEndpointProvider([$service => new ServiceEndpointInfo('ecs', false)]);
            $standard = new StandardEndpointProvider(null, null, [], [$service => false]);
            $template = new StandardEndpointProvider('{Service}.{Region}.internal', null, [], [$service => true]);
            $calls = [
                'default endpointFor' => function () use ($default, $service, $region) { return $default->endpointFor($service, $region); },
                'default dualstack' => function () use ($default, $service, $region) {
                    return $default->endpointFor($service, $region, null, true);
                },
                'default custom endpoints' => function () use ($custom, $service, $region) { return $custom->endpointFor($service, $region); },
                'getDefaultEndpoint' => function () use ($default, $service, $region) { return $default->getDefaultEndpoint($service, $region); },
                'getDefaultEndpoint dualstack' => function () use ($default, $service, $region) {
                    return $default->getDefaultEndpoint($service, $region, DUALSTACK_ENDPOINT_SUFFIX);
                },
                'service info regional' => function () use ($service, $region) {
                    return (new ServiceEndpointInfo($service, false, true))->getEndpointFor($region);
                },
                'service info global' => function () use ($service, $region) {
                    return (new ServiceEndpointInfo($service, true, true))->getEndpointFor($region);
                },
                'standard custom service' => function () use ($standard, $service, $region) { return $standard->endpointFor($service, $region); },
                'standard template' => function () use ($template, $service, $region) { return $template->endpointFor($service, $region, null, true); },
            ];
            if ($kind === 'service') {
                // getDefaultEndpoint only looks the service up in the built-in map; ServiceEndpointInfo validates the host parts.
                unset($calls['getDefaultEndpoint'], $calls['getDefaultEndpoint dualstack']);
            }
            foreach ($calls as $name => $fn) {
                rejects($code, $message, $fn, $name . ' ' . $label);
            }
        }
    }

    // 3. Pipeline: generated clients and the universal client fail before resolving, signing or sending.
    $resolvers = [
        'default' => function () { return new DefaultEndpointProvider(); },
        'standard' => function () { return new StandardEndpointProvider(); },
        'failing custom' => function () { return new FailingEndpointProvider(); },
    ];
    foreach ($resolvers as $resolverName => $makeResolver) {
        foreach (invalid_cases('region') as $case) {
            list($value, $code, $message) = $case;
            $label = $resolverName . ' region ' . json_encode($value);
            $sent = [];
            $config = configuration('cn-beijing')->setEndpointProvider($makeResolver())->setAutoRetry(true);
            $apiClient = new ApiClient($config, http_client($sent));
            $ecs = new ECSApi(null, $config, null, $apiClient);
            $universal = new UniversalApi($apiClient);
            // Invalid values set after construction are rejected by every request.
            $config->setRegion($value);
            rejects($code, $message, function () use ($ecs) { return $ecs->describeInstances(new \Byteplus\Ecs\Model\DescribeInstancesRequest()); }, $label . ' generated sync');
            rejects($code, $message, function () use ($ecs) { return $ecs->describeInstancesAsync(new \Byteplus\Ecs\Model\DescribeInstancesRequest()); }, $label . ' generated async');
            rejects($code, $message, function () use ($universal) {
                return $universal->doCall(new UniversalInfo('GET', 'ecs', '2020-04-01', 'DescribeInstances'));
            }, $label . ' universal');
            rejects($code, $message, function () use ($apiClient) {
                return $apiClient->callApi([], '/DescribeInstances/2020-04-01/ecs/get/', 'GET');
            }, $label . ' callApi');
            check(count($sent) === 0, $label . ': request sent');
            if ($resolverName === 'failing custom') {
                $config->setEndpointProvider(new EchoEndpointProvider());
            }
            $config->setRegion('cn-beijing');
            accepts(function () use ($ecs) { return $ecs->describeInstances(new \Byteplus\Ecs\Model\DescribeInstancesRequest()); }, $label . ' recovered sync');
            accepts(function () use ($universal) {
                return $universal->doCall(new UniversalInfo('GET', 'ecs', '2020-04-01', 'DescribeInstances'));
            }, $label . ' recovered universal');
            check(count($sent) === 2 && $sent[0]->hasHeader('Authorization') && $sent[1]->hasHeader('X-Date'), $label . ': recovered requests not signed');
        }
        foreach (invalid_cases('service') as $case) {
            list($value, $code, $message) = $case;
            $label = $resolverName . ' service ' . json_encode($value);
            $sent = [];
            $config = configuration('cn-beijing')->setEndpointProvider($makeResolver());
            $apiClient = new ApiClient($config, http_client($sent));
            rejects($code, $message, function () use ($apiClient, $value) {
                return (new UniversalApi($apiClient))->doCall(new UniversalInfo('GET', $value, '2020-04-01', 'DescribeInstances'));
            }, $label . ' universal');
            if (strpos($value, '/') === false) {
                rejects($code, $message, function () use ($apiClient, $value) {
                    return $apiClient->callApi([], '/DescribeInstances/2020-04-01/' . $value . '/get/', 'GET', [], 'object', true);
                }, $label . ' callApi');
            }
            check(count($sent) === 0, $label . ': request sent');
        }
    }

    // The interceptor itself guards custom resolvers for direct users.
    foreach ([['/DescribeInstances/2020-04-01/ecs@evil.com/get/', 'cn-beijing', 'InvalidService'], ['/DescribeInstances/2020-04-01/ecs/get/', 'cn#evil', 'InvalidRegion']] as $case) {
        $request = new Request();
        $request->resourcePath = $case[0];
        $request->region = $case[1];
        $request->schema = 'https';
        $request->endpointProvider = new FailingEndpointProvider();
        $context = new Context();
        $context->setRequest($request);
        rejects($case[2], null, function () use ($context) {
            return (new ResolveEndpointInterceptor(null))->intercept($context);
        }, 'resolve interceptor ' . $case[2]);
        check(!$request->host, 'interceptor set a host');
    }

    // 4. Signing proof: drive the internal request chain with a prepared request and inspect it.
    $sent = [];
    $config = configuration('cn-beijing')->setEndpointProvider(new FailingEndpointProvider());
    $apiClient = new ApiClient($config, http_client($sent));
    $chain = private_value($apiClient, 'interceptorChain');
    foreach ([['region', 'cn-beijing@127.0.0.1', 'InvalidRegion'], ['service', 'ecs@127.0.0.1', 'InvalidService']] as $case) {
        $context = call_private($apiClient, 'createContextAndRequest', [[], '/DescribeInstances/2020-04-01/ecs/get/', 'GET', [], 'object']);
        $request = $context->getRequest();
        if ($case[0] === 'region') {
            $request->region = $case[1];
        } else {
            $request->resourcePath = '/DescribeInstances/2020-04-01/' . $case[1] . '/get/';
        }
        rejects($case[2], null, function () use ($chain, $context) { return $chain->executeRequest($context); }, 'chain ' . $case[0]);
        $names = array_map('strtolower', array_keys($request->headers));
        check(!in_array('authorization', $names, true) && !in_array('x-date', $names, true), 'chain ' . $case[0] . ': request was signed');
        check($request->realRequest === null && !$request->host, 'chain ' . $case[0] . ': request was built');
    }
    $config->setEndpointProvider(new DefaultEndpointProvider());
    $context = call_private($apiClient, 'createContextAndRequest', [[], '/DescribeInstances/2020-04-01/ecs/get/', 'GET', [], 'object']);
    $chain->executeRequest($context);
    $request = $context->getRequest();
    check(isset($request->headers['Authorization'], $request->headers['X-Date']), 'chain control: request not signed');
    check($request->realRequest->getUri()->getHost() === 'ecs.cn-beijing.byteplusapi.com.cn', 'chain control host');
    check(count($sent) === 0, 'chain sent a request');

    // 5. RDS pre-signing builds no token for invalid input.
    foreach (invalid_cases('region') as $case) {
        list($value, $code, $message) = $case;
        $sent = [];
        $rds = new ApiClient(configuration($value), http_client($sent));
        rejects($code, $message, function () use ($rds) {
            return ConnectUtils::buildAuthToken($rds, 'test-user', 'test-instance');
        }, 'RDS ' . json_encode($value));
        check(count($sent) === 0, 'RDS network');
    }
    $rdsConfig = configuration('cn-beijing');
    $rds = new ApiClient($rdsConfig, http_client($sent));
    $rdsConfig->setRegion('cn/beijing');
    rejects('InvalidRegion', null, function () use ($rds) { return ConnectUtils::buildAuthToken($rds, 'u', 'i'); }, 'RDS set after construction');
    $rdsConfig->setRegion('cn-beijing');
    $token = accepts(function () use ($rds) { return ConnectUtils::buildAuthToken($rds, 'u', 'i'); }, 'RDS recovered');
    check(strpos($token, 'X-Signature=') !== false && strpos($token, 'X-HOST=' . rawurlencode('https://rds-mysql.cn-beijing.byteplusapi.com.cn')) !== false, 'RDS token');

    // 6. Explicit endpoints keep their host and are not validated (they do not build the host).
    foreach (['api.internal:8443', 'https://api.internal:8443', 'http://127.0.0.1:8080', 'http://[::1]:8080'] as $host) {
        $sent = [];
        $config = configuration('cn-beijing@127.0.0.1')->setHost($host)->setEndpointProvider(new FailingEndpointProvider());
        $apiClient = new ApiClient($config, http_client($sent));
        $ecs = new ECSApi(null, $config, null, $apiClient);
        accepts(function () use ($ecs) { return $ecs->describeInstances(new \Byteplus\Ecs\Model\DescribeInstancesRequest()); }, 'explicit generated ' . $host);
        accepts(function () use ($apiClient) {
            return (new UniversalApi($apiClient))->doCall(new UniversalInfo('GET', 'custom.service', '2020-04-01', 'DescribeInstances'));
        }, 'explicit universal ' . $host);
        $expected = new \GuzzleHttp\Psr7\Uri(strpos($host, '://') === false ? 'https://' . $host : $host);
        check(count($sent) === 2 && $sent[0]->getUri()->getAuthority() === $expected->getAuthority()
            && $sent[1]->getUri()->getAuthority() === $expected->getAuthority(), 'explicit endpoint authority ' . $host);
        check($config->getHost() === $host, 'explicit host config changed');
        $token = accepts(function () use ($apiClient) { return ConnectUtils::buildAuthToken($apiClient, 'u', 'i'); }, 'explicit RDS ' . $host);
        check(strpos($token, 'X-Signature=') !== false, 'explicit RDS signature');
    }

    // 7. SSO formats the region into its OAuth and portal host names.
    $cacheDir = sys_get_temp_dir() . '/endpoint-ssrf-sso-' . getmypid();
    foreach (invalid_cases('region') as $case) {
        list($value, $code, $message) = $case;
        if (trim($value) !== $value) {
            continue;
        }
        $sso = new SsoCredentialProvider(['sso-session-name' => 's'], 'p', ['sso-session' => ['s' => ['start-url' => 'https://start.example', 'region' => $value]]], $cacheDir);
        rejects($code, $message, function () use ($sso) { return $sso->getCredentials(); }, 'SSO ' . json_encode($value));
    }
    $sso = new SsoCredentialProvider(['sso-session-name' => 's'], 'p', ['sso-session' => ['s' => ['start-url' => 'https://start.example', 'region' => 'cn-beijing']]], $cacheDir);
    try {
        $sso->getCredentials();
        check(false, 'SSO control returned credentials');
    } catch (ApiException $e) {
        check($e->getErrorCode() === null && strpos($e->getMessage(), 'token cache') !== false, 'SSO control: ' . $e->getMessage());
    }

    // 8. Built-in services and regions pass, and resolve exactly as before.
    $default = new DefaultEndpointProvider();
    $standard = new StandardEndpointProvider();
    $default->getDefaultEndpoint('ecs', 'ap-southeast-1');
    $defaultMap = private_value(DefaultEndpointProvider::class, 'defaultEndpoint');
    $serviceInfos = [];
    $standardServiceFields = [];
    foreach (call_private_static(StandardEndpointProvider::class, 'serviceInfos') as $name => $info) {
        $serviceInfos[$name] = (bool) $info->IsGlobal;
        $standardServiceFields[] = $info->service;
    }
    $whiteRegions = array_keys(private_value(call_private_static(StandardEndpointProvider::class, 'regionMatcher'), 'whiteRegions'));
    $bootstrapRegions = [];
    foreach (get_defined_constants(true)['user'] as $name => $value) {
        if (strpos($name, 'REGION_CODE_') === 0) {
            $bootstrapRegions[] = $value;
        }
    }
    check(count($defaultMap) > 40 && count($serviceInfos) > 100 && count($whiteRegions) > 40 && count($bootstrapRegions) === 1, 'built-in inventory');
    $builtinServices = array_merge(array_keys($defaultMap), array_keys($serviceInfos), array_merge($standardServiceFields, [ConnectUtils::SERVICE, 'sts']));
    foreach ($defaultMap as $info) {
        $builtinServices[] = $info->service;
    }
    $builtinRegions = array_merge($whiteRegions, $bootstrapRegions, [(new Configuration())->getRegion(), SsoCredentialProvider::DEFAULT_REGION, StsProvider::DEFAULT_REGION,
        \Byteplus\Common\Auth\Providers\OidcCredentialProvider::DEFAULT_REGION, \Byteplus\Common\Auth\Providers\SamlCredentialProvider::DEFAULT_REGION]);

    $apiFiles = glob(__DIR__ . '/../src/*/Api/*Api.php');
    $generated = [];
    $unloadable = [];
    foreach ($apiFiles as $file) {
        $module = basename(dirname(dirname($file)));
        $source = file_get_contents($file);
        preg_match_all("#resourcePath = '/[^/]+/[^/]+/([^/]+)/#", $source, $m);
        check(count($m[1]) > 0, 'resource paths in ' . $file);
        foreach (array_unique($m[1]) as $service) {
            $builtinServices[] = $service;
        }
        $class = 'Byteplus\\' . $module . '\\Api\\' . basename($file, '.php');
        // Some generated files redeclare methods or declare a namespace outside PSR-4 and cannot be loaded.
        preg_match_all('/function (\w+)\(/', $source, $fm);
        $methods = array_map('strtolower', $fm[1]);
        preg_match('/^namespace\s+([^;]+);/m', $source, $nm);
        if (count($methods) !== count(array_unique($methods)) || !isset($nm[1]) || $nm[1] . '\\' . basename($file, '.php') !== $class) {
            $unloadable[] = $class;
            continue;
        }
        $generated[] = [$class, 'Byteplus\\' . $module . '\\Model\\'];
    }
    foreach (array_unique($builtinServices) as $service) {
        accepts(function () use ($service) { EndpointProvider::validateService($service); }, 'built-in service ' . $service);
    }
    foreach (array_unique($builtinRegions) as $region) {
        accepts(function () use ($region) { EndpointProvider::validateRegion($region); }, 'built-in region ' . $region);
    }

    // Resolution as on master: unregistered services throw, mainland China regions get a .cn suffix.
    $isMainland = function ($region) {
        return strpos($region, 'cn-') === 0 && $region !== 'cn-hongkong';
    };
    $expectedDefault = function ($service, $region, $dualStack) use ($defaultMap, $isMainland) {
        if (!isset($defaultMap[$service])) {
            return null;
        }
        $info = $defaultMap[$service];
        $normalized = strtolower(trim($region));
        $suffix = $dualStack ? '.byteplus-api.com' : '.byteplusapi.com';
        $cn = $info->goChinaEnabled && $isMainland($normalized) ? '.cn' : '';
        return standardize($info->service) . ($info->isGlobal ? '' : '.' . $normalized) . $suffix . $cn;
    };
    foreach (array_keys($defaultMap) as $service) {
        foreach (array_merge($whiteRegions, $bootstrapRegions, ['AP-Southeast-1']) as $region) {
            foreach ([false, true] as $dualStack) {
                check($default->endpointFor($service, $region, null, $dualStack)->host === $expectedDefault($service, $region, $dualStack), 'default host ' . $service . ' ' . $region);
            }
        }
    }
    foreach ($serviceInfos as $service => $isGlobal) {
        foreach ($whiteRegions as $region) {
            foreach ([false, true] as $dualStack) {
                $expected = standardize($service) . ($isGlobal ? '' : '.' . $region) . ($dualStack ? '.byteplus-api.com' : '.byteplusapi.com')
                    . (!$isGlobal && $isMainland($region) ? '.cn' : '');
                check($standard->endpointFor($service, $region, null, $dualStack)->host === $expected, 'standard host ' . $service . ' ' . $region);
            }
        }
    }

    // Each generated client's default instance sends its first operation to the original host.
    $operations = 0;
    $unregistered = 0;
    foreach ($generated as $entry) {
        list($class, $modelNamespace) = $entry;
        $operation = null;
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (method_exists($class, $name . 'WithHttpInfo') && class_exists($modelNamespace . ucfirst($name) . 'Request')) {
                $operation = $name;
                break;
            }
        }
        check($operation !== null, 'operation in ' . $class);
        foreach (['', 'custom.example:8443'] as $host) {
            $sent = [];
            $config = configuration('ap-southeast-1')->setHost($host);
            $api = new $class(http_client($sent), $config);
            $model = $modelNamespace . ucfirst($operation) . 'Request';
            $requestInfo = call_private($api, $operation . 'Request', [new $model()]);
            $service = explode('/', $requestInfo['resourcePath'])[3];
            $expectedHost = $host ?: $expectedDefault($service, 'ap-southeast-1', false);
            if ($expectedHost === null) {
                // Unregistered in the default map: rejected before sending, as on master.
                try {
                    $api->$operation(new $model());
                    check(false, $class . ': unregistered service was sent');
                } catch (ApiException $e) {
                    check($e->getErrorCode() === null && strpos($e->getMessage(), 'not registered') !== false && count($sent) === 0, $class . ': ' . $e->getMessage());
                }
                $unregistered++;
                $operations++;
                continue;
            }
            accepts(function () use ($api, $operation, $model) { return $api->$operation(new $model()); }, $class . '::' . $operation . ' ' . $host);
            check(count($sent) === 1 && $sent[0]->getUri()->getAuthority() === $expectedHost && $sent[0]->hasHeader('Authorization'), $class . ' host ' . $host);
            $operations++;
        }
    }
    check($operations === 2 * count($generated) && count($generated) + count($unloadable) >= 40, 'generated clients covered');

    // Missing values and unregistered services keep their previous handling.
    foreach ([['', ''], [null, null], ['new_service', 'Future_REGION-1']] as $case) {
        try {
            $default->endpointFor($case[0], $case[1]);
            check(false, 'unregistered service resolved');
        } catch (ApiException $e) {
            check($e->getErrorCode() === null && strpos($e->getMessage(), 'not registered') !== false, 'unregistered service: ' . $e->getMessage());
        }
    }
    foreach ([['ecs', '', 'InvalidArgument: region must not be empty'], ['', 'ap-southeast-1', 'InvalidArgument: service must not be empty'], ['new_service', 'ap-southeast-1', 'ServiceNotFound: ']] as $case) {
        try {
            $standard->endpointFor($case[0], $case[1]);
            check(false, 'standard accepted ' . json_encode($case));
        } catch (\Byteplus\Common\Endpoint\Providers\StandProviderError $e) {
            check(strpos($e->getMessage(), $case[2]) === 0, 'standard error: ' . $e->getMessage());
        }
    }
    // Valid regions are still normalized for the host; surrounding whitespace is now rejected.
    check((new ServiceEndpointInfo('rds_mysql', false, true))->getEndpointFor('CN-Beijing') === 'rds-mysql.cn-beijing.byteplusapi.com.cn', 'region normalization');
    rejects('InvalidRegion', null, function () {
        return (new ServiceEndpointInfo('rds_mysql', false, true))->getEndpointFor(' cn-beijing');
    }, 'region with whitespace');

    if ($unloadable) {
        echo 'Skipped unloadable generated clients: ' . implode(', ', $unloadable) . PHP_EOL;
    }
    echo 'Endpoint SSRF behavior check passed (' . $GLOBALS['checks'] . ' assertions, ' . $exhaustive . ' exhaustive cases, '
        . $operations . ' generated client requests, ' . $unregistered . ' unregistered in the default map).' . PHP_EOL;
}
