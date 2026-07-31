[← 访问凭据](1-Credentials-zh.md) | Endpoint 配置[(English)](2-Endpoint.md) | [Transport →](3-Transport-zh.md)

---

## EndPoint 配置

> **默认**
>
> 不指定 `host` 时，走 [自动化 Endpoint 寻址](#自动化-endpoint-寻址)。

### 自定义 Endpoint

用户可以通过在初始化客户端时指定 Endpoint：

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk("Your ak")
    ->setSk("Your sk")
    ->setHost('https://open.byteplusapi.com');  // 自定义 Endpoint
```

显式设置的 `host` 优先级最高，会跳过后续所有寻址逻辑（包括自定义 Endpoint Provider）。

### 自定义 RegionId

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk("Your ak")
    ->setSk("Your sk")
    ->setRegion("cn-beijing");
```

### 自动化 Endpoint 寻址

> **默认**
>
> 默认支持自动寻址，无需手动指定 Endpoint。

为了简化用户配置，Byteplus 提供了灵活的 Endpoint 自动寻址机制。用户无需手动指定服务地址，SDK 会根据服务名称、区域（Region）、服务是否标记为 Go China 等信息自动拼接出合理的访问地址，并支持用户自定义 DualStack（双栈）。

#### Endpoint 默认寻址

##### 寻址逻辑

1. **服务注册判定**

    每个服务在内置映射中都会登记 `isGlobal` 和 `goChinaEnabled` 两个 bool 字段，SDK 按下方"标准寻址规则"构造 Endpoint。

    - 服务未在映射中登记：`DefaultEndpointProvider::getDefaultEndpoint` 会抛出 `\Byteplus\Common\ApiException`，错误消息形如 `service '<xxx>' not registered in default endpoint map`；`ResolveEndpointInterceptor` 将其直接向上传播。参见 [错误处理](#错误处理)。

    内置服务映射：[`./src/Common/Endpoint/Providers/DefaultEndpointProvider.php`](../src/Common/Endpoint/Providers/DefaultEndpointProvider.php) 中的 `$defaultEndpoint`。

2. **DualStack 支持（IPv6）**

    `Configuration::$useDualStack` 默认值为 `null`，`ApiClient` 会原样透传给 Endpoint Provider。寻址规则：

    - `null`（默认）：寻址器读取环境变量 `BYTEPLUS_ENABLE_DUALSTACK`，值为字符串 `true` 时才启用 DualStack。
    - `true`（`setUseDualStack(true)`）：强制启用 DualStack，忽略环境变量。
    - `false`（`setUseDualStack(false)`）：强制禁用 DualStack，忽略环境变量。

    启用 DualStack 后，域名后缀将从 `byteplusapi.com` 切换为 `byteplus-api.com`。

3. **Go China 后缀**

    当服务在内置映射中标记 `goChinaEnabled=true`，并且请求 Region 属于中国大陆（`cn-*` 前缀且不属于 `cn-hongkong` 等非大陆港澳台 Region）时，在域名后追加 `.cn` 后缀。

    是否 GoChina 由服务侧决定，不可修改。匹配前会先对 region 做 `strtolower(trim(...))` 归一化，因此 `CN-Beijing`、`  cn-beijing  ` 与 `cn-beijing` 等价。

4. **根据服务名和区域自动构造 Endpoint 地址**

    - **Global 服务（如 `IAM`、`Billing`）**：使用 `<服务名>.byteplusapi.com`（DualStack 时使用 `byteplus-api.com`；命中 Go China 时追加 `.cn`）。
    - **Regional 服务（如 `ECS`、`RDS`）**：使用 `<服务名>.<区域名>.byteplusapi.com` 作为默认 Endpoint（DualStack / Go China 规则同上）。

##### 寻址决策表

下表列出所有生效组合。左侧列的 "RegionType" 由服务的 `isGlobal` 决定；"Region 是否 GoChina" 指请求 Region 是否属于中国大陆。

| RegionType | goChinaEnabled | 请求 Region 是否 Go China | Endpoint | 是否包含 Region |
|---|---|---|---|---|
| Global | true | 是 | `{service}.byteplusapi.com.cn` | 否 |
| Global | true | 否 | `{service}.byteplusapi.com` | 否 |
| Global | false | 任意 | `{service}.byteplusapi.com` | 否 |
| Regional | true | 是 | `{service}.{region}.byteplusapi.com.cn` | 是 |
| Regional | true | 否 | `{service}.{region}.byteplusapi.com` | 是 |
| Regional | false | 任意 | `{service}.{region}.byteplusapi.com` | 是 |

启用 DualStack 时，将上表中的 `byteplusapi.com` 整体替换为 `byteplus-api.com`。

##### `customBootstrapRegion` / `BYTEPLUS_BOOTSTRAP_REGION_LIST_CONF`（已废弃）

> **⚠️ Deprecated**：`DefaultEndpointProvider::endpointFor(...)` 上的 `customBootstrapRegion` 参数以及 `BYTEPLUS_BOOTSTRAP_REGION_LIST_CONF` 环境变量已被标记为**废弃**，**不再参与**默认寻址链路。该参数仅为抽象类 `EndpointProvider` 签名的源代码兼容而保留，运行时视为 no-op。请**勿在新代码中使用**，已有代码建议改用 `Configuration::setRegion(...)` + `Configuration::setUseDualStack(...)` 让 SDK 自动寻址，或用 `Configuration::setHost(...)` 显式覆盖。

##### 代码示例

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$config = \Byteplus\Common\Configuration::getDefaultConfiguration()
    ->setAk("Your ak")
    ->setSk("Your sk")
    ->setRegion("ap-southeast-1")
    ->setUseDualStack(true);   // 启用双栈（IPv4 + IPv6）；默认值为 null，此时读取环境变量 BYTEPLUS_ENABLE_DUALSTACK
```

##### 错误处理

如果请求的服务名不在内置 `$defaultEndpoint` 映射中，SDK 在第一次触发默认寻址时就会抛出 `\Byteplus\Common\ApiException`，错误消息形如 `service '<xxx>' not registered in default endpoint map`。可用如下方式识别：

```php
<?php
use Byteplus\Common\ApiException;

try {
    // ... SDK call that triggers default endpoint resolution
} catch (ApiException $e) {
    // SDK 版本可能不识别该服务，请升级依赖或显式指定 Endpoint。
    throw $e;
}
```

遇到该错误时，建议先升级 SDK 版本；若确认 SDK 尚未内置该服务的寻址元数据，可通过 `Configuration::setHost(...)` 或自定义 Endpoint Provider 显式指定。

---

[← 访问凭据](1-Credentials-zh.md) | Endpoint 配置[(English)](2-Endpoint.md) | [Transport →](3-Transport-zh.md)
