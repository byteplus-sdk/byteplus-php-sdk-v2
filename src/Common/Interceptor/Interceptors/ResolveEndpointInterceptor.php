<?php

namespace Byteplus\Common\Interceptor\Interceptors;

use Byteplus\Common\LogHelper;
use Byteplus\Common\SdkLogger;

class ResolveEndpointInterceptor extends Interceptor
{
    public $endpointProvider;

    public function __construct($endpointProvider)
    {
        $this->endpointProvider = $endpointProvider;
    }

    public function name()
    {
        return 'byteplus-resolve-endpoint-interceptor';
    }

    public function intercept(Context $context)
    {
        $host = $context->request->host;
        $schema = $context->request->schema;
        if (!$host) {
            $pathParts = explode('/', $context->request->resourcePath);
            $service = isset($pathParts[3]) ? $pathParts[3] : '';
            $endpointResolver = $context->request->endpointProvider->endpointFor(
                $service,
                $context->request->region,
                $context->request->customBootstrapRegion,
                $context->request->useDualStack
            );
            $context->request->host = $endpointResolver->host;
            $prefix = $endpointResolver->urlFor($schema);
        } else {
            if (strpos($host, 'https://') === 0) {
                $prefix = $host;
                $context->request->host = substr($host, strlen('https://'));
            } elseif (strpos($host, 'http://') === 0) {
                $prefix = $host;
                $context->request->host = substr($host, strlen('http://'));
            } else {
                $prefix = $schema . '://' . $host;
            }
        }

        LogHelper::debug($context->request->logger, $context->request->logLevel, SdkLogger::LOG_ENDPOINT,
            'Endpoint service={service} region={region} host={host}', [
                'service' => $context->request->service ?: (isset($service) ? $service : ''),
                'region' => $context->request->region,
                'host' => $context->request->host,
            ]
        );

        $context->request->url = $prefix . $context->request->truePath;
        return $context;
    }
}
