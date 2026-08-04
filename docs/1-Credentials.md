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

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider($provider);
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

For `sso` and `console-login` modes the SDK refreshes expired access tokens and
keeps the refreshed state in the current PHP process, matching the Python SDK:

- **Per-object in-memory cache**: a single `CLIConfigCredentialProvider` instance
  caches the parsed credentials for the lifetime of the object (within a single
  PHP request), so repeated API calls inside one request reuse the same STS.
- **CLI-owned disk cache**: the SDK never writes the SSO or console-login cache;
  `bp login` / `bp sso login` remain the sole disk-cache writers.
- **Console-login rotation recovery**: on HTTP 400 `invalid_grant`, the
  console-login provider reloads the disk cache once and retries only when it
  finds a different refresh token. SSO surfaces the refresh failure directly,
  matching Python behavior.
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
