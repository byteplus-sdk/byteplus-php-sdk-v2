[← 概览](0-Overview-zh.md) | 访问凭据[(English)](1-Credentials.md) | [Endpoint 配置 →](2-Endpoint-zh.md)

---

## 访问凭据

Byteplus PHP SDK 同时支持显式凭证配置，以及基于 `CredentialProvider` 的自动凭证解析。

### 凭证提供者概览

| Provider | 用途 | 是否自动刷新 | 典型场景 |
| --- | --- | --- | --- |
| 直接在 `Configuration` 中设置 `AK/SK` 或 `AK/SK/Token` | 显式传入固定或临时凭证 | 否 | 简单服务端接入 |
| `StaticCredentialProvider` | 通过 Provider 接口传入固定凭证 | 否 | 自定义凭证链或要求使用 Provider 的客户端初始化 |
| `StsProvider` | STS AssumeRole | 是 | 基于角色的临时凭证 |
| `OidcCredentialProvider` | STS AssumeRoleWithOIDC | 是 | OIDC 联邦身份 |
| `SamlCredentialProvider` | STS AssumeRoleWithSAML | 是 | SAML 联邦身份 |
| `EnvironmentVariableCredentialProvider` | 从环境变量读取 | 否 | CI/CD、容器注入 |
| `CLIConfigCredentialProvider` | 从 `~/.byteplus/config.json` 读取 | 视 mode 而定 | 复用 CLI 登录态或 profile |
| `EcsRoleCredentialProvider` | 从 ECS IMDS 读取 | 是 | ECS 实例角色凭证 |
| `DefaultCredentialProvider` | 默认凭证链封装 | 取决于实际命中的 provider | 业务代码不显式写 AK/SK |

### AK、SK 设置

AK/SK 是由Byteplus用户在控制台创建的一对永久访问密钥。SDK 使用该密钥对每次请求进行签名，从而完成身份验证。

> ⚠️ **注意事项**
>
> 1. 不得在客户端嵌入或暴露 AK/SK。
> 2. 推荐使用配置中心或环境变量存储密钥。
> 3. 配置合理的最小权限访问策略。

**代码示例：**

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk("Your AK")
    ->setSk("Your SK")
    ->setRegion("cn-beijing")
    ->setVerifySsl(false)  #非必填，默认为true，是否验证ssl
    ->setDebug(true) #非必填，默认为false，是否开启debug模式
    ->setHost('open.byteplusapi.com') #非必填，默认为open.byteplusapi.com
    ->setSchema('https'); #非必填，默认为https，可选https或者http

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
    $responseMetaData = $result->offsetGet('ResponseMetadata');  //包含了返回的请求信息，action + version + RequestId + service + Region
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling VPCApi->createVpc: ', $e->getMessage(), PHP_EOL;
}

?>
```

### STS Token 设置

STS（Security Token Service）是Byteplus提供的临时访问凭证机制。开发者通过服务端调用 STS 接口获取临时凭证（临时 AK、SK 和 Token），有效期可配置，适用于安全要求较高的场景。

> ⚠️ **注意事项**
>
> 1. 最小权限：仅授予调用方访问所需资源的最小权限，避免使用 * 通配符授予全资源、全操作权限。
> 2. 设置合理的有效期: 请根据实际情况设置合理有效期，越短越安全，建议不要超过1小时。

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk('Your AK')
    ->setSk('Your SK')
    ->setSessionToken('Your session token')
    ->setRegion("cn-beijing");

$apiInstance = new \Byteplus\Vpc\Api\VPCApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
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

直接使用 `setAk()` / `setSk()` 仍是最简单的方式。只有当代码需要传入 `CredentialProvider` 时，再使用 `StaticCredentialProvider`。

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider(
        new \Byteplus\Common\Auth\Providers\StaticCredentialProvider(
            "Your AK",
            "Your SK",
            "Your session token" // 可选
        )
    );
```

### AssumeRole

动态访问凭证信息。`StsProvider::getCredentials()` 会缓存 STS `AssumeRole` 返回的凭证，并在 `ExpiredTime` 前 60 秒刷新，同时校验必需字段并对临时失败进行重试。默认 endpoint 为 `sts.ap-southeast-1.byteplusapi.com`，签名 region 为 `ap-southeast-1`。

> ⚠️ **注意事项**
>
> 1. 最小权限：仅授予调用方访问所需资源的最小权限，避免使用 * 通配符授予全资源、全操作权限。
> 2. 设置合理的有效期: 请根据实际情况设置合理有效期，越短越安全，最长不能超过12小时。
> 3. 细粒度角色: 角色应绑定精细的访问控制策略，仅允许访问特定服务、资源、操作，防止角色滥用。

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$sts = new \Byteplus\Common\Auth\Providers\StsProvider(
    "Your ak", # 必填，子账号的ak
    "Your sk", # 必填，子账号的sk
    "Your role name",  # 必填，子账号的角色TRN，如trn:iam::2110400000:role/role123  ,此处填写role123
    "Your account id",  # 必填，子账号的角色TRN，如trn:iam::2110400000:role/role123  ,此处填写2110400000
    "ap-southeast-1", # 非必填，请求服务器区域地址，默认 ap-southeast-1
    "3600", # 非必填，有效期默认3600秒
    "https", # 非必填，域名前缀，默认https
    "sts.ap-southeast-1.byteplusapi.com", # 非必填，请求域名，默认值如左
    '{"Statement":[{"Effect":"Allow","Action":["vpc:CreateVpc"],"Resource":["*"],"Condition":{"StringEquals":{"byteplus:RequestedRegion":["cn-beijing"]}}}]}' # 非必填，授权策略，默认为空
);

// 可选：调整传输和重试配置。maxRetries 表示额外重试次数。
// $sts->setConnectTimeout(5)
//     ->setReadTimeout(30)
//     ->setMaxRetries(3)
//     ->setRetryInterval(1);

try {
    $result = $sts->getCredentials();
    print_r($result);
    /**
    * $result返回结果如下
    * Array
    * (
    * [ExpiredTime] => 2025-10-27T12:08:12+08:00   # 临时凭证的过期时间
    * [CurrentTime] => 2025-10-27T11:08:12+08:00   # 临时凭证的生成时间
    * [AccessKeyId] => ************  # 临时凭证的ak
    * [SecretAccessKey] => ************  #临时凭证的sk
    * [SessionToken] => ***************  #临时凭证的token
    * )
    */
} catch (Exception $e) {
    echo 'Exception when calling VPCApi->createVpc: ', $e->getMessage(), PHP_EOL;
}

```

### OIDC 凭证提供者

`OidcCredentialProvider` 通过 STS AssumeRoleWithOIDC 获取临时凭证并缓存复用，在到期前 60 秒自动刷新。支持 STS 返回的 `Expiration` 或 `ExpiredTime`；若响应没有有效的服务端过期时间则拒绝该响应。

支持的 OIDC 环境变量：

- `BYTEPLUS_OIDC_ROLE_TRN`
- `BYTEPLUS_OIDC_TOKEN_FILE`
- `BYTEPLUS_OIDC_ROLE_SESSION_NAME`
- `BYTEPLUS_OIDC_ROLE_POLICY`
- `BYTEPLUS_OIDC_STS_ENDPOINT`

可以直接传参构造，也可以通过 `OidcCredentialProvider::fromEnvironment()` 从环境变量创建。

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$provider = new \Byteplus\Common\Auth\Providers\OidcCredentialProvider(
    "trn:iam::1234567890:role/oidc-role",   // roleTrn（必填）
    "/var/run/secrets/oidc/token",           // oidcTokenFile（必填）
    "credentials-php-demo",                  // roleSessionName（可选）
    null,                                    // rolePolicy（可选）
    "sts.ap-southeast-1.byteplusapi.com" // stsEndpoint（可选，左侧为默认值）
);

// 可选：通过 fluent setter 调整重试和传输参数
// $provider->setSchema('https')       // 'http' 或 'https'，默认 'https'
//          ->setMaxRetries(3)          // 总尝试次数（包含首次），最小为 1
//          ->setRetryInterval(1);      // 重试间隔秒数，默认 1

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider($provider);
```

基于环境变量的示例：

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

### SAML 凭证提供者

`SamlCredentialProvider` 通过 SAML 2.0 IdP 返回的 SAML 断言调用 STS `AssumeRoleWithSAML` 换取临时凭证。请求会生成 `RoleSessionName`，凭证按服务端返回的 `Expiration` 或 `ExpiredTime` 缓存，并在到期前 60 秒刷新。

> ⚠️ **注意事项**
>
> 1. 最小权限原则。
> 2. 合理的有效期；建议不超过 1 小时。
> 3. `samlAssertion` 为 IdP 返回的 base64 编码的 SAML Response。

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$provider = new \Byteplus\Common\Auth\Providers\SamlCredentialProvider(
    "YourRoleName",                            // roleName（必填）
    "1234567890",                              // account id（必填）
    "MyIdp",                                   // SAML provider 名称（必填）
    "BASE64_ENCODED_SAML_RESPONSE_FROM_IDP",   // SAML assertion（必填）
    null,                                      // role policy（可选）
    "sts.ap-southeast-1.byteplusapi.com"   // sts endpoint（可选，左侧为默认值）
);

// 可选：通过 fluent setter 调整重试和传输参数
// $provider->setSchema('https')       // 'http' 或 'https'，默认 'https'
//          ->setMaxRetries(3)          // 总尝试次数（包含首次），最小为 1
//          ->setRetryInterval(1);      // 重试间隔秒数，默认 1

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider($provider);
```

### 环境变量凭证提供者

`EnvironmentVariableCredentialProvider` 支持从以下环境变量读取凭证：

- `BYTEPLUS_ACCESSKEY` / `BYTEPLUS_ACCESS_KEY`
- `BYTEPLUS_SECRETKEY` / `BYTEPLUS_SECRET_KEY`
- `BYTEPLUS_SESSION_TOKEN`，可选

Access Key / Secret Key 支持「连写」（`ACCESSKEY`）与「下划线分隔」（`ACCESS_KEY`）两种写法，两者同时设置时以连写形式优先。

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

### CLI 配置凭证提供者

`CLIConfigCredentialProvider` 默认读取 `~/.byteplus/config.json`。

- 配置文件路径优先级：构造函数 `configPath` > `BYTEPLUS_CLI_CONFIG_FILE` > `~/.byteplus/config.json`
- Profile 优先级：构造函数 `profileName` > `BYTEPLUS_PROFILE` > 配置文件中的 `current` > `default`

支持的 profile mode：

- `ak` 或空（同时支持 `session-token` 字段以承载静态 STS 凭证）
- `ramrolearn`，内部委托给 `StsProvider`；支持 `access-key`、`secret-key`、`role-name`、`account-id`，可选 `region`
- `oidc`，内部委托给 `OidcCredentialProvider`
- `ecsrole`，内部委托给 `EcsRoleCredentialProvider`
- `sso`，从 CLI sso 缓存读取 STS 凭证；access token 过期时 SDK 自动通过 OAuth 续期，内部委托给 `SsoCredentialProvider`
- `console-login`，从 CLI console-login 缓存读取 STS 凭证；access token 过期时 SDK 自动通过 OAuth `refresh_token` 续期，内部委托给 `ConsoleLoginCredentialProvider`

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

#### 运行时刷新行为（sso / console-login）

`sso` 与 `console-login` 模式下，SDK 会自动续期已过期的 access token，并在当前 PHP 进程中维护刷新后的状态，行为与 Python SDK 一致：

- **对象级内存缓存**：单个 `CLIConfigCredentialProvider` 实例会在对象生命周期内（同一次 PHP 请求中）缓存已解析的凭证，同一请求内多次调用 API 复用同一份 STS。
- **CLI 独占磁盘写入**：SDK 不会写回 SSO 或 console-login 缓存，磁盘缓存仅由 `bp login` / `bp sso login` 更新。
- **Console-login 轮换恢复**：服务端返回 HTTP 400 `invalid_grant` 时，console-login provider 会重新读取一次磁盘缓存；只有发现不同的 refresh token 才会重试。SSO 会直接暴露刷新失败，与 Python 行为一致。
- **可操作的错误信息**：所有需要重新登录的错误路径都包含 `'bp login'`（console-login）或 `'bp sso login'`（sso），便于调用方向用户清晰说明下一步。

### ECS 角色凭证提供者

`EcsRoleCredentialProvider` 从 ECS IMDS 中读取临时凭证。

- `roleName` 优先级：构造参数 > `BYTEPLUS_ECS_METADATA` > 从 IMDS 自动探测
- 禁用开关：`BYTEPLUS_ECS_METADATA_DISABLED=true`

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$provider = \Byteplus\Common\Auth\Providers\EcsRoleCredentialProvider::create("your-ecs-role-name");

// 可选：通过 fluent setter 调整重试和超时参数
// $provider->setMaxRetries(3)            // 总尝试次数（包含首次），最小为 1
//          ->setRetryInterval(1)          // 重试间隔秒数，默认 1
//          ->setConnectTimeout(1)         // 连接超时秒数，默认 1
//          ->setReadTimeout(1)            // 读取超时秒数，默认 1
//          ->setExpireBufferSeconds(300); // 过期前提前刷新的缓冲秒数，默认 300

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setRegion("cn-beijing")
    ->setCredentialProvider($provider);
```

### 默认凭证提供者

当 `ak`、`sk` 和 `credentialProvider` 都未设置时，SDK 会自动使用 `DefaultCredentialProvider`。通常不需要业务方手动拼默认凭证链，除非要自定义链路选项。

默认凭证链顺序：

1. `EnvironmentVariableCredentialProvider`
2. `OidcCredentialProvider`
3. `CLIConfigCredentialProvider`
4. `EcsRoleCredentialProvider`

默认开启 `reuseLastProviderEnabled=true`，后续请求会优先复用上一次成功解析的 provider。

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

[← 概览](0-Overview-zh.md) | 访问凭据[(English)](1-Credentials.md) | [Endpoint 配置 →](2-Endpoint-zh.md)
