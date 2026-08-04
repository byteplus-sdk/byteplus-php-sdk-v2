[← Error Handling](7-ErrorHandling.md) | Debugging[(中文)](8-Debugging-zh.md) | [SDK Integration →](../SDK_Integration.md)

---

## Debugging

The PHP SDK supports Guzzle wire debug output.

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk("Your ak")
    ->setSk("Your sk")
    ->setRegion('cn-beijing')
    ->setDebug(true)
    ->setDebugFile('/tmp/byteplus-php-sdk-debug.log');
```

SDK structured debug logs can be enabled separately with a bitmask. HTTP request and response logs do not record request or response body.

```php
<?php
$config->setLogLevel(
    \Byteplus\Common\SdkLogger::LOG_REQUEST |
    \Byteplus\Common\SdkLogger::LOG_RESPONSE |
    \Byteplus\Common\SdkLogger::LOG_RETRY |
    \Byteplus\Common\SdkLogger::LOG_ENDPOINT
);
```

You can append your application identifier to the SDK User-Agent. The SDK
User-Agent remains the prefix:

```php
<?php
$config->setUserAgent('my-app/1.0');
```

You can still use `Configuration::toDebugReport()` for environment diagnostics.

---

[← Error Handling](7-ErrorHandling.md) | Debugging[(中文)](8-Debugging-zh.md) | [SDK Integration →](../SDK_Integration.md)
