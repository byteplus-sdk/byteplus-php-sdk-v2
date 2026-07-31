[← Credentials](1-Credentials.md) | Endpoint[(中文)](2-Endpoint-zh.md) | [Transport →](3-Transport.md)

---

## Endpoint Configuration

> **Default**
>
> If `host` is not specified, the SDK uses [Automatic Endpoint Resolution](#automatic-endpoint-resolution).

### Custom Endpoint

You can specify a custom endpoint when initializing the client:

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk("Your ak")
    ->setSk("Your sk")
    ->setHost('https://open.byteplusapi.com');  // custom endpoint
```

An explicitly configured `host` has the highest priority and skips every subsequent resolution step (including any custom endpoint provider).

### Custom RegionId

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk("Your ak")
    ->setSk("Your sk")
    ->setRegion("cn-beijing");
```

### Automatic Endpoint Resolution

BytePlus provides a flexible endpoint resolution mechanism. The SDK automatically builds the endpoint based on the service name, region and the service's Go China flag, and supports DualStack.

#### Default Endpoint Resolution

##### Resolution Logic

1. **Service registration check**

    Every service in the built-in map carries an `isGlobal` and a `goChinaEnabled` bool. The SDK builds the endpoint following the rules below.

    - Service missing from the map: `DefaultEndpointProvider::getDefaultEndpoint` throws `\Byteplus\Common\ApiException` with a message like `service '<xxx>' not registered in default endpoint map`; `ResolveEndpointInterceptor` propagates it up the call chain. See [Error handling](#error-handling).

    Built-in service map: `$defaultEndpoint` in [`./src/Common/Endpoint/Providers/DefaultEndpointProvider.php`](../src/Common/Endpoint/Providers/DefaultEndpointProvider.php).

2. **DualStack support (IPv6)**

    `Configuration::$useDualStack` defaults to `null`, and `ApiClient` forwards it to the Endpoint Provider unchanged. Resolution rules:

    - `null` (default): the resolver reads the `BYTEPLUS_ENABLE_DUALSTACK` env var; DualStack is enabled only when its value equals the string `true`.
    - `true` (via `setUseDualStack(true)`): DualStack is force-enabled and the env var is ignored.
    - `false` (via `setUseDualStack(false)`): DualStack is force-disabled and the env var is ignored.

    When DualStack is enabled, the suffix changes from `byteplusapi.com` to `byteplus-api.com`.

3. **Go China suffix**

    When a service entry has `goChinaEnabled=true` and the request region is in the Chinese mainland (a `cn-*` prefix but not one of the non-mainland regions such as `cn-hongkong`), the resolver appends the `.cn` suffix.

    Whether Go China applies is decided by the service itself and cannot be overridden. Regions are normalized with `strtolower(trim(...))` before matching, so `CN-Beijing`, `  cn-beijing  ` and `cn-beijing` are treated identically.

4. **Endpoint construction**

    - **Global services (e.g. `IAM`, `Billing`)**: `<service>.byteplusapi.com` (or `byteplus-api.com` when DualStack is enabled; `.cn` is appended when Go China applies).
    - **Regional services (e.g. `ECS`, `RDS`)**: `<service>.<region>.byteplusapi.com` (DualStack / Go China rules identical to global services).

##### Decision Table

The table lists every effective combination. `RegionType` is derived from the service's `isGlobal` flag; "Region is Go China" refers to the request region.

| RegionType | goChinaEnabled | Region is Go China | Endpoint | Region embedded |
|---|---|---|---|---|
| Global | true | yes | `{service}.byteplusapi.com.cn` | no |
| Global | true | no | `{service}.byteplusapi.com` | no |
| Global | false | any | `{service}.byteplusapi.com` | no |
| Regional | true | yes | `{service}.{region}.byteplusapi.com.cn` | yes |
| Regional | true | no | `{service}.{region}.byteplusapi.com` | yes |
| Regional | false | any | `{service}.{region}.byteplusapi.com` | yes |

When DualStack is enabled, replace every occurrence of `byteplusapi.com` in the table with `byteplus-api.com`.

##### `customBootstrapRegion` / `BYTEPLUS_BOOTSTRAP_REGION_LIST_CONF` (Deprecated)

> **⚠️ Deprecated**: the `customBootstrapRegion` argument on `DefaultEndpointProvider::endpointFor(...)` and the `BYTEPLUS_BOOTSTRAP_REGION_LIST_CONF` environment variable are **deprecated** and **no longer participate** in the default addressing pipeline. The argument is retained only for source-level compatibility with the abstract `EndpointProvider` signature and is treated as a no-op at runtime. **Do not use it in new code.** Existing callers should switch to `Configuration::setRegion(...)` + `Configuration::setUseDualStack(...)` and let the SDK auto-resolve the endpoint, or override it explicitly via `Configuration::setHost(...)`.

##### Code Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk("Your ak")
    ->setSk("Your sk")
    ->setRegion("ap-southeast-1")
    ->setUseDualStack(true);   // enable dual-stack (IPv4 + IPv6); default is null, then reads BYTEPLUS_ENABLE_DUALSTACK
```

##### Error handling

If the requested service is not registered in `$defaultEndpoint`, the SDK throws `\Byteplus\Common\ApiException` on the first default endpoint resolution triggered by `ResolveEndpointInterceptor`, with a message like `service '<xxx>' not registered in default endpoint map`. Detect it with:

```php
<?php
use Byteplus\Common\ApiException;

try {
    // ... SDK call that triggers default endpoint resolution
} catch (ApiException $e) {
    // The installed SDK likely does not know this service.
    // Upgrade the dependency or set the endpoint explicitly.
    throw $e;
}
```

When you hit this error, first try upgrading the SDK. If the service is genuinely not carried by the SDK yet, set the endpoint explicitly via `Configuration::setHost(...)` or supply a custom endpoint provider.

---

[← Credentials](1-Credentials.md) | Endpoint[(中文)](2-Endpoint-zh.md) | [Transport →](3-Transport.md)
