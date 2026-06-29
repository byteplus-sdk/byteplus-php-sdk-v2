English | [中文](README.md)

# Byteplus SDK for PHP

## Requirements

PHP 5.5 and later

## Installation & Usage

### Composer

To install the bindings via [Composer](http://getcomposer.org/), add the following to `composer.json`:

```
{
  "require": {
    "byteplus_sdk/byteplus-php-sdk-v2": "v1.0.0"
  }
}
```

Then run `composer install`.

## Getting Started

Please follow the [installation procedure](#installation--usage).

For detailed SDK documentation (credentials, endpoint, transport, timeout, retry, error handling, debugging), see: [SDK Integration Guide](./docs/0-Overview.md)

##### Endpoint Configuration

If you want to customize the SDK endpoint, use the following example:

```php
$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk("Your AK")
    ->setSk("Your SK")
    ->setRegion("cn-beijing")
    ->setHost("ecs.cn-beijing-autodriving.byteplusapi.com");
```

Standard endpoint rules:

| Regional Service | Global Service |
|---|---|
| `{service}.{region}.byteplusapi.com` <br> e.g. `ecs.cn-beijing-autodriving.byteplusapi.com` | `{service}.byteplusapi.com` <br> e.g. `iam.byteplusapi.com` |

Note:

- If the service name contains `_`, it should be converted to `-` in the endpoint. Use lowercase for all characters.

##### SDK Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk("Your AK")
    ->setSk("Your SK")
    ->setRegion("cn-beijing");

$apiInstance = new \Byteplus\Vpc\Api\VPCApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\\ClientInterface`.
    // This is optional, `GuzzleHttp\\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);

$body = new \Byteplus\Vpc\Model\CreateVpcRequest();
$body->setClientToken("token-123456789")
    ->setCidrBlock("192.168.0.0/16")
    ->setDnsServers(array("10.0.0.1", "10.1.1.2"));

try {
    $result = $apiInstance->createVpc($body);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling VPCApi->createVpc: ', $e->getMessage(), PHP_EOL;
}

?>
```

## Security and privacy
This project takes security seriously. 
For vulnerability reporting and supported versions, see [SECURITY.md](SECURITY.md)

