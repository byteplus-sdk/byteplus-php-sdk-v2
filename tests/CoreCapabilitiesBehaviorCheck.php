<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Common/autoload.php';

use Byteplus\Common\ApiClient;
use Byteplus\Common\ApiException;
use Byteplus\Common\Auth\Providers\StaticCredentialProvider;
use Byteplus\Common\Configuration;
use Byteplus\Common\Interceptor\Interceptors\Context;
use Byteplus\Common\Interceptor\Interceptors\Request;
use Byteplus\Common\Interceptor\Interceptors\ResolveEndpointInterceptor;
use Byteplus\Common\Retry\Retryer;
use Byteplus\Common\UniversalApi;
use Byteplus\Common\UniversalInfo;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;

function core_assert_same($expected, $actual, $label)
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function core_assert_true($actual, $label)
{
    if (!$actual) {
        throw new RuntimeException($label . ': expected true');
    }
}

function run_static_credential_check()
{
    $provider = new StaticCredentialProvider('ak', 'sk', 'token');
    $credentials = $provider->getCredentials();

    core_assert_same('ak', $credentials['AccessKeyId'], 'static access key');
    core_assert_same('sk', $credentials['SecretAccessKey'], 'static secret key');
    core_assert_same('token', $credentials['SessionToken'], 'static session token');
    core_assert_same('StaticCredentialProvider', $credentials['ProviderName'], 'static provider name');
}

function run_configuration_check()
{
    $configuration = new Configuration();
    $configuration->setUserAgent('core-check/1.0');
    core_assert_same(
        'byteplus-php-sdk-v2/1.0.3 core-check/1.0',
        $configuration->getUserAgent(),
        'user agent prefix'
    );

    Configuration::setDefaultConfiguration($configuration);
    core_assert_true(
        Configuration::getDefaultConfiguration() === $configuration,
        'shared default configuration'
    );

    $invalidClientRejected = false;
    try {
        new UniversalApi(new stdClass());
    } catch (InvalidArgumentException $e) {
        $invalidClientRejected = true;
    }
    core_assert_true($invalidClientRejected, 'universal api client type validation');
}

function run_retryer_and_error_check()
{
    $retryer = new Retryer(1, 0, 0);
    $response = new Psr7Response(429, ['Retry-After' => '2']);

    core_assert_true($retryer->shouldRetry($response, 0, null), 'retry HTTP 429');
    core_assert_same(false, $retryer->shouldRetry($response, 1, null), 'retry limit');
    core_assert_same(2000.0, $retryer->getRetryDelay(0, $response), 'retry-after delay');

    $body = json_encode([
        'ResponseMetadata' => [
            'Error' => [
                'Code' => 'Throttling',
                'Message' => 'slow down',
            ],
        ],
    ]);
    $exception = ApiException::fromServiceError(429, 'https://api.byteplusapi.com', [], $body);
    core_assert_same(429, $exception->getStatusCode(), 'structured error status');
    core_assert_same('Throttling', $exception->getErrorCode(), 'structured error code');
    core_assert_same('slow down', $exception->getErrorMessage(), 'structured error message');
}

function run_custom_endpoint_scheme_check()
{
    $request = new Request();
    $request->host = 'https://api.byteplusapi.com';
    $request->schema = 'http';
    $request->truePath = '/';
    $request->region = 'ap-southeast-1';
    $request->service = 'vpc';
    $request->logger = null;
    $request->logLevel = 0;

    $context = new Context();
    $context->setRequest($request);
    (new ResolveEndpointInterceptor(null))->intercept($context);

    core_assert_same('api.byteplusapi.com', $request->host, 'custom endpoint normalized host');
    core_assert_same('https://api.byteplusapi.com/', $request->url, 'custom endpoint URL');
}

function build_mock_api_client(array $responses, array &$history)
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $configuration = (new Configuration())
        ->setAk('ak')
        ->setSk('sk')
        ->setRegion('ap-southeast-1')
        ->setHost('api.byteplusapi.com')
        ->setAutoRetry(true)
        ->setNumMaxRetries(1)
        ->setMinRetryDelayMs(0)
        ->setMaxRetryDelayMs(0);

    return new ApiClient($configuration, new Client(['handler' => $stack]));
}

function success_response($requestId)
{
    return new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
        'ResponseMetadata' => ['RequestId' => $requestId],
        'Result' => ['Value' => 'ok'],
    ]));
}

function retry_response($statusCode)
{
    return new Psr7Response($statusCode, ['Content-Type' => 'application/json'], json_encode([
        'ResponseMetadata' => [
            'Error' => [
                'Code' => 'InternalError',
                'Message' => 'retry me',
            ],
        ],
    ]));
}

function assert_request_history(array $history, $label)
{
    core_assert_same(2, count($history), $label . ' attempt count');
    core_assert_same('api.byteplusapi.com', $history[0]['request']->getUri()->getHost(), $label . ' host');
    core_assert_true($history[0]['request']->hasHeader('Authorization'), $label . ' authorization');
}

function run_sync_retry_and_universal_api_check()
{
    $history = [];
    $client = build_mock_api_client([retry_response(500), success_response('sync-request')], $history);
    $api = new UniversalApi($client);
    $result = $api->doCall(
        new UniversalInfo('POST', 'vpc', '2020-04-01', 'DescribeVpcs'),
        ['Limit' => 10]
    );

    core_assert_same('ok', $result['Value'], 'sync universal result');
    assert_request_history($history, 'sync retry');
}

function run_async_retry_check()
{
    $history = [];
    $client = build_mock_api_client([retry_response(429), success_response('async-request')], $history);
    $promise = $client->callApi(
        ['Limit' => 10],
        '/DescribeVpcs/2020-04-01/vpc/post/',
        'POST',
        ['Content-Type' => 'application/json'],
        'object',
        true
    );
    $result = $promise->wait();

    core_assert_same('ok', $result[0]['Value'], 'async result');
    assert_request_history($history, 'async retry');
}

run_static_credential_check();
run_configuration_check();
run_retryer_and_error_check();
run_custom_endpoint_scheme_check();
run_sync_retry_and_universal_api_check();
run_async_retry_check();

echo "Core capabilities behavior check passed.\n";
