[← Overview](0-Overview.md) | Credentials[(中文)](1-Credentials-zh.md) | [Endpoint →](2-Endpoint.md)

---

## Credentials

The Byteplus PHP SDK supports explicit credentials and `CredentialProvider`-based automatic resolution.

### Credential Providers Overview

| Provider | Purpose | Refresh Support | Typical Scenario |
| --- | --- | --- | --- |
| Direct `Configuration` (`AK/SK` or `AK/SK/Token`) | Explicit static or temporary credentials | No | Simple server-side integration |
| `StaticCredentialProvider` | Static credentials through the provider interface | No | Custom provider chains or provider-based client setup |
| `StsProvider` | STS AssumeRole | Yes | Role-based temporary credentials |
| `OidcCredentialProvider` | STS AssumeRoleWithOIDC | Yes | OIDC federation |
| `SamlCredentialProvider` | STS AssumeRoleWithSAML | Yes | SAML federation |
| `EnvironmentVariableCredentialProvider` | Read from env vars | No | CI/CD and container env injection |
| `CLIConfigCredentialProvider` | Read from `~/.byteplus/config.json` | Depends on mode | Reuse CLI login/profile |
| `EcsRoleCredentialProvider` | Read from ECS IMDS | Yes | ECS instance role credentials |
| `DefaultCredentialProvider` | Chain wrapper | Depends on delegated provider | No AK/SK in application code |

### AK/SK

AK/SK is a pair of permanent access keys created in the Byteplus console. The SDK signs each request to authenticate.

> ⚠️ **Notes**
>
> 1. Do not embed or expose AK/SK in client-side applications.
> 2. Use a configuration center or environment variables.
> 3. Follow least privilege principles.

**Example:**

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk("Your AK")
    ->setSk("Your SK")
    ->setRegion("cn-beijing")
    ->setVerifySsl(false)  # optional, default true
    ->setDebug(true)       # optional, default false
    ->setHost('open.byteplusapi.com') # optional, default open.byteplusapi.com
    ->setSchema('https');  # optional, default https

$apiInstance = new \Byteplus\Vpc\Api\VPCApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);

$body = new \Byteplus\Vpc\Model\CreateVpcRequest();
$body->setClientToken("token-123456789")
    ->setCidrBlock("192.168.0.0/16")
    ->setDnsServers(array("10.0.0.1", "10.1.1.2"));

try {
    $result = $apiInstance->createVpc($body);
    $responseMetaData = $result->offsetGet('ResponseMetadata');
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling VPCApi->createVpc: ', $e->getMessage(), PHP_EOL;
}

?>
```

### STS Token

STS (Security Token Service) provides temporary credentials (temporary AK/SK and Token).

> ⚠️ **Notes**
>
> 1. Least privilege.
> 2. Use a reasonable TTL. Shorter is safer; avoid exceeding 1 hour.

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk('Your AK')
    ->setSk('Your SK')
    ->setSessionToken('Your session token')
    ->setRegion("cn-beijing");

$apiInstance = new \Byteplus\Vpc\Api\VPCApi(
    new \GuzzleHttp\Client(),
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

### Static Credential Provider

Direct `setAk()` / `setSk()` remains the simplest path. Use `StaticCredentialProvider` when your code expects a `CredentialProvider`.

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider(
        new \Byteplus\Common\Auth\Providers\StaticCredentialProvider(
            "Your AK",
            "Your SK",
            "Your session token" // optional
        )
    );
```

### AssumeRole

AssumeRole provides dynamic credentials. `StsProvider::getCredentials()` caches the returned credentials and refreshes them 60 seconds before `ExpiredTime`. It validates the required STS fields and retries transient failures. The default endpoint and signing region are `sts.ap-southeast-1.byteplusapi.com` and `ap-southeast-1`.

For `StsProvider`, retryable HTTP statuses are exactly `429/500/502/503/504`;
other HTTP statuses, including `501/505/599`, do not trigger status-based retries.
Network/transport exceptions without a response can also be retried.

> ⚠️ **Notes**
>
> 1. Least privilege.
> 2. Choose a reasonable TTL; maximum is 12 hours.
> 3. Use fine-grained roles and policies.

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$sts = new \Byteplus\Common\Auth\Providers\StsProvider(
    "Your ak", // required
    "Your sk", // required
    "Your role name",  // required
    "Your account id", // required
    "ap-southeast-1", // optional; default ap-southeast-1
    "3600", // optional
    "https", // optional
    "sts.ap-southeast-1.byteplusapi.com", // optional; default shown
    '{"Statement":[{"Effect":"Allow","Action":["vpc:CreateVpc"],"Resource":["*"],"Condition":{"StringEquals":{"byteplus:RequestedRegion":["cn-beijing"]}}}]}' // optional
);

// Optional: tune transport and retry settings. maxRetries means extra retries.
// $sts->setConnectTimeout(5)
//     ->setReadTimeout(30)
//     ->setMaxRetries(3)
//     ->setRetryInterval(1);

// Optional: reach STS through a forward proxy and/or customize TLS trust.
// $sts->setProxyUrl('http://user:pass@10.0.0.1:8080') // forward proxy, scheme://[user:password@]host:port; Basic auth; empty falls back to Configuration, then proxy environment variables
//     ->setCaFile('/path/to/ca.pem')                  // custom CA bundle (PEM) to verify the STS server certificate; empty uses the system trust store
//     ->setInsecureSkipVerify(false);                 // set true to skip STS TLS verification (debugging only, never in production)

try {
    $result = $sts->getCredentials();
    print_r($result);
} catch (Exception $e) {
    echo 'Exception: ', $e->getMessage(), PHP_EOL;
}

?>
```

### OIDC Credential Provider

`OidcCredentialProvider` obtains temporary credentials via STS AssumeRoleWithOIDC, caches them and refreshes 60 seconds before expiry. The expiry prefers the `Expiration` returned by STS and falls back to `ExpiredTime`; responses without a valid server-side expiry are rejected.

Supported OIDC env vars:

- `BYTEPLUS_OIDC_ROLE_TRN`
- `BYTEPLUS_OIDC_TOKEN_FILE`
- `BYTEPLUS_OIDC_ROLE_SESSION_NAME`
- `BYTEPLUS_OIDC_ROLE_POLICY`
- `BYTEPLUS_OIDC_STS_ENDPOINT`

You can either construct the provider explicitly, or build it from environment variables with `OidcCredentialProvider::fromEnvironment()`.

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$provider = new \Byteplus\Common\Auth\Providers\OidcCredentialProvider(
    "trn:iam::1234567890:role/oidc-role",   // roleTrn (required)
    "/var/run/secrets/oidc/token",           // oidcTokenFile (required)
    "credentials-php-demo",                  // roleSessionName (optional)
    null,                                    // rolePolicy (optional)
    "sts.ap-southeast-1.byteplusapi.com" // stsEndpoint (optional; default shown)
);

// Optional: tune retry and transport settings via fluent setters
// $provider->setSchema('https')       // 'http' or 'https', default 'https'
//          ->setMaxRetries(3)          // total attempts including the first, minimum 1
//          ->setRetryInterval(1);      // seconds between retries, default 1

// Optional: reach STS through a forward proxy and/or customize TLS trust.
// $provider->setProxyUrl('http://user:pass@10.0.0.1:8080') // forward proxy; empty falls back to Configuration, then proxy environment variables
//          ->setCaFile('/path/to/ca.pem')                  // custom CA bundle (PEM); empty uses the system trust store
//          ->setInsecureSkipVerify(false);                 // set true to skip STS TLS verification (debugging only)

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider($provider);
```

Environment-based example:

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

putenv("BYTEPLUS_OIDC_ROLE_TRN=trn:iam::1234567890:role/oidc-role");
putenv("BYTEPLUS_OIDC_TOKEN_FILE=/var/run/secrets/oidc/token");

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider(
        \Byteplus\Common\Auth\Providers\OidcCredentialProvider::fromEnvironment()
    );
```

### SAML Credential Provider

`SamlCredentialProvider` exchanges a SAML 2.0 assertion (returned by your IdP) for temporary STS credentials via `AssumeRoleWithSAML`. The request includes a generated `RoleSessionName`; credentials are cached and refreshed 60 seconds before the server-provided `Expiration` or `ExpiredTime`.

> ⚠️ **Notes**
>
> 1. Least privilege.
> 2. Reasonable TTL; recommended ≤ 1 hour.
> 3. `samlAssertion` is the base64-encoded SAML Response returned by your IdP.

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$provider = new \Byteplus\Common\Auth\Providers\SamlCredentialProvider(
    "YourRoleName",                            // roleName (required)
    "1234567890",                              // account id (required)
    "MyIdp",                                   // SAML provider name (required)
    "BASE64_ENCODED_SAML_RESPONSE_FROM_IDP",   // SAML assertion (required)
    null,                                      // role policy (optional)
    "sts.ap-southeast-1.byteplusapi.com"   // sts endpoint (optional; default shown)
);

// Optional: tune retry and transport settings via fluent setters
// $provider->setSchema('https')       // 'http' or 'https', default 'https'
//          ->setMaxRetries(3)          // total attempts including the first, minimum 1
//          ->setRetryInterval(1);      // seconds between retries, default 1

// Optional: reach STS through a forward proxy and/or customize TLS trust.
// $provider->setProxyUrl('http://user:pass@10.0.0.1:8080') // forward proxy; empty falls back to Configuration, then proxy environment variables
//          ->setCaFile('/path/to/ca.pem')                  // custom CA bundle (PEM); empty uses the system trust store
//          ->setInsecureSkipVerify(false);                 // set true to skip STS TLS verification (debugging only)

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider($provider);
```

### STS Proxy and TLS (AssumeRole / OIDC / SAML)

`StsProvider`, `OidcCredentialProvider` and `SamlCredentialProvider` share three
optional fluent setters that control how the SDK reaches the STS endpoint. They
are useful when the client sits behind a corporate forward proxy, or when the
proxy performs TLS interception with a private CA.

When one of these providers is installed directly on `Configuration`, the existing
`setProxy()`, `setHttpProxy()` and `setHttpsProxy()` settings are also used for
the STS credential request. The priority is: provider `setProxyUrl()` >
`Configuration::setProxy()` > the HTTP/HTTPS proxy settings > standard proxy
environment variables. This fallback applies to the proxy only; the provider TLS
settings remain independent from the business API TLS settings.

Proxy empty-value behavior matches business API requests. An empty provider
`setProxyUrl()` value does not override Configuration. An effective Configuration
proxy of `null` leaves Guzzle's `proxy` option unset, so environment variables may
apply; `''` explicitly disables proxying. Proxy maps are passed through unchanged:
an empty map or a map that omits the request scheme may fall back to the
corresponding environment variable. An empty string `''` for a scheme (for example,
`['https' => '']`) disables its proxy. A `null` scheme value (for example,
`['https' => null]`) is treated as unset and may fall back to that scheme's
environment proxy.

| Setter | Purpose | Default |
| --- | --- | --- |
| `setProxyUrl($url)` | Forward proxy in the form `scheme://[user:password@]host:port`. Proxy credentials are sent with Basic auth; percent-encode special characters in the username or password as required by RFC 3986. When empty, the SDK falls back to the `Configuration` proxy and then standard `HTTP_PROXY` / `HTTPS_PROXY` / `NO_PROXY` environment variables (handled by Guzzle/curl). | unset (Configuration/env fallback) |
| `setCaFile($path)` | Path to a custom CA bundle (PEM) used to verify the STS server certificate. Use it when the STS/proxy certificate is signed by a private CA that is not in the system trust store. | unset (system trust store) |
| `setInsecureSkipVerify($bool)` | When `true`, skips STS TLS certificate and hostname verification. | `false` |

> ⚠️ **Notes**
>
> 1. `setInsecureSkipVerify(true)` disables certificate validation and exposes the
>    connection to man-in-the-middle attacks. Use it only for debugging; in
>    production trust a private CA via `setCaFile()` instead.
> 2. `setInsecureSkipVerify(true)` takes precedence over `setCaFile()`.
>    If both are set, the CA file must still exist and be readable.
> 3. An invalid provider proxy URL or an unreadable CA file raises an
>    `InvalidArgumentException` when credentials are fetched.

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

// Example: AssumeRole through an HTTPS-intercepting corporate proxy that uses a
// private CA. Works the same way on OidcCredentialProvider / SamlCredentialProvider.
$sts = new \Byteplus\Common\Auth\Providers\StsProvider(
    "Your ak",
    "Your sk",
    "Your role name",
    "Your account id",
    "ap-southeast-1"
);
$sts->setProxyUrl('http://user:pass@10.0.0.1:8080')
    ->setCaFile('/etc/pki/corp-ca.pem');
    // ->setInsecureSkipVerify(true); // debugging only

$result = $sts->getCredentials();
print_r($result);
```

### Environment Variable Credential Provider

`EnvironmentVariableCredentialProvider` reads credentials from:

- `BYTEPLUS_ACCESSKEY` / `BYTEPLUS_ACCESS_KEY`
- `BYTEPLUS_SECRETKEY` / `BYTEPLUS_SECRET_KEY`
- `BYTEPLUS_SESSION_TOKEN` (optional)

For Access Key / Secret Key, either the concatenated (`ACCESSKEY`) or underscore-separated (`ACCESS_KEY`) name is accepted; the concatenated form takes priority when both are set.

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

putenv("BYTEPLUS_ACCESS_KEY=YourAK");
putenv("BYTEPLUS_SECRET_KEY=YourSK");

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider(
        new \Byteplus\Common\Auth\Providers\EnvironmentVariableCredentialProvider()
    );
```

### CLI Config Credential Provider

`CLIConfigCredentialProvider` reads `~/.byteplus/config.json` by default.

- Config path priority: constructor `configPath` > `BYTEPLUS_CLI_CONFIG_FILE` > `~/.byteplus/config.json`
- Profile priority: constructor `profileName` > `BYTEPLUS_PROFILE` > `current` in config > `default`

Supported profile modes:

- `ak` or empty (also accepts `session-token` for static STS credentials)
- `ramrolearn` (delegates to `StsProvider`; supports `access-key`, `secret-key`, `role-name`, `account-id`, and optional `region`)
- `oidc` (delegates to `OidcCredentialProvider`)
- `ecsrole` (delegates to `EcsRoleCredentialProvider`)
- `sso` (reads STS credentials from the CLI sso cache; auto-refreshes the access token via OAuth when expired, delegates to `SsoCredentialProvider`)
- `console-login` (reads STS credentials from the CLI console-login cache; auto-refreshes via OAuth `refresh_token` when expired, delegates to `ConsoleLoginCredentialProvider`)

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider(
        new \Byteplus\Common\Auth\Providers\CLIConfigCredentialProvider(
            "prod",
            getenv("HOME") . "/.byteplus/config.json"
        )
    );
```

#### Runtime Refresh Behavior (sso / console-login)

For `sso` and `console-login` modes, the SDK refreshes access tokens when the
provider's expiry condition is met and retains the refreshed state in memory:

- **Per-object in-memory cache**: repeated calls on the same provider reuse
  credentials while they remain within its validity window.
- **Shared disk cache**: both the PHP SDK and `bp login` / `bp sso login` write
  the cache. The SDK writes a temporary file, attempts to set permissions to
  `0600`, then updates the cache using atomic `rename` on supported filesystems.
  SSO reports encoding/write/rename failures as exceptions; console-login
  persistence is best-effort and does not abort credential retrieval on these
  failures. A failed write does not guarantee persistence for the next process.
- **SSO rotation recovery**: on HTTP 400 `invalid_grant`, the provider reloads
  the disk cache once. Recovery requires a nonempty disk refresh token that
  differs from the in-memory token. It then reuses a nonempty, unexpired disk
  access token, or makes one further OAuth refresh call. There are at most two
  OAuth refresh requests in this recovery flow. An unchanged/empty disk refresh
  token or another `invalid_grant` produces a `bp sso login` error.
- **Console-login rotation recovery**: on HTTP 400 `invalid_grant`, the provider
  also reloads the disk cache once and requires a different, nonempty refresh
  token. It reuses valid cached credentials or attempts one further refresh.
- **Actionable error messages**: every error path that requires
  re-authentication contains `'bp login'` (console-login) or `'bp sso login'`
  (sso) so the caller can present a clear next step.

### ECS Role Credential Provider

`EcsRoleCredentialProvider` reads temporary credentials from ECS IMDS.

- `roleName` priority: constructor arg > `BYTEPLUS_ECS_METADATA` > auto-detect from IMDS
- disable switch: `BYTEPLUS_ECS_METADATA_DISABLED=true`

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$provider = \Byteplus\Common\Auth\Providers\EcsRoleCredentialProvider::create("your-ecs-role-name");

// Optional: tune retry and timeout settings via fluent setters
// $provider->setMaxRetries(3)            // total attempts including the first, minimum 1
//          ->setRetryInterval(1)          // seconds between retries, default 1
//          ->setConnectTimeout(1)         // seconds, default 1
//          ->setReadTimeout(1)            // seconds, default 1
//          ->setExpireBufferSeconds(300); // refresh buffer before expiry, default 300

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider($provider);
```

### Default Credential Provider

When `ak`, `sk`, and `credentialProvider` are all unset, the SDK automatically uses `DefaultCredentialProvider`. You do not need to configure the chain manually unless you want custom options.

Default chain order:

1. `EnvironmentVariableCredentialProvider`
2. `OidcCredentialProvider`
3. `CLIConfigCredentialProvider`
4. `EcsRoleCredentialProvider`

By default, `reuseLastProviderEnabled=true`, so the last successful provider is reused first on later calls.

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider(
        new \Byteplus\Common\Auth\Providers\DefaultCredentialProvider()
    );
```

---

[← Overview](0-Overview.md) | Credentials[(中文)](1-Credentials-zh.md) | [Endpoint →](2-Endpoint.md)
