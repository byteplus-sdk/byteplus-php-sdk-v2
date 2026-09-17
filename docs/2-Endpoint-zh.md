[← 访问凭据](1-Credentials-zh.md) | Endpoint 配置[(English)](2-Endpoint.md) | [Transport →](3-Transport-zh.md)

---

## EndPoint 配置

> **默认**
>
> 不指定 `host` 时，走 [自动化 Endpoint 寻址](#自动化-endpoint-寻址)。

### 自定义 Endpoint

用户可以通过在初始化客户端时指定 Endpoint：

`setHost()` 支持域名（可带端口）或 HTTP(S) 源站 URL。URL 显式协议优先于配置中的协议，允许尾部 `/`；
请求的 `Host` 头只包含域名和可选端口，不包含协议或尾部 `/`。
源站 URL 中的用户信息、非根路径、查询参数和片段会在发送请求前抛出 `InvalidArgumentException`，资源路径和 API 参数应单独传入。

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

#### Standard Endpoint Provider

`Byteplus\Common\Endpoint\Providers\StandardEndpointProvider` 是可选的模板寻址器，通过 `Configuration::setEndpointProvider()` 配置；显式 `host` 仍有最高优先级。它使用独立的内置服务表。

构造方法：`new StandardEndpointProvider($fmt = null, $siteStack = null, $extension = null, $customServices = null)`。

| 参数 | 行为 |
|---|---|
| `$fmt` | 默认 `{Service}{Region}.{SiteStack}.com{CNSuffix}` |
| `$siteStack` | 仅初始化变量，`endpointFor()` 会按 DualStack 覆盖；自定义域名应使用模板字面量或扩展键 |
| `$extension` | 额外模板变量数组，非数组会被忽略；使用字符串值，以及不含 `{`、`}` 的非空字符串键 |
| `$customServices` | 服务名到全局/区域级分类的映射数组，非数组会被忽略 |

**模板与变量**

支持混用 `{Key}`、`${Key}`、`{{.Key}}`。例如 `${Service}{{.Region}}.{SiteStack}.com{CNSuffix}` 与默认模板输出相同。`{{.Key}}` 只是占位符语法，不提供完整的 Go 模板引擎。

| 内置变量 | 值 |
|---|---|
| `Service` | 服务代码转小写，`_` 替换为 `-` |
| `Region` | 区域级服务为 `.<region>`，全局服务为空；包含前导点 |
| `SiteStack` | `byteplusapi`，启用 DualStack 时为 `byteplus-api` |
| `CNSuffix` | 区域级服务、region 通过校验且以 `cn-` 开头、并非精确值 `cn-hongkong` 时为 `.cn`，否则为空 |
| `Extension` | 保留的扩展键值格式化字符串，不是 JSON |

每个扩展键也可直接作为占位符，例如 `{Tenant}`、`${Tenant}`、`{{.Tenant}}`。同名时内置变量优先。替换值按字面输出，不再进行二次扫描。

**自定义服务与错误**

内置服务条目优先于 `$customServices`。内置表之外的服务支持以下形态：

- `['mysvc' => false]`：`false` 表示区域级，`true` 表示全局。
- `['mysvc' => ['isGlobal' => false]]`：也支持 `IsGlobal` 键。
- `['mysvc' => (object) ['IsGlobal' => true]]`：也支持公开的 `isGlobal` 属性；还支持 `new \Byteplus\Common\Endpoint\Providers\ServiceInfo('mysvc', true)`。

异常类型为 `StandProviderError`，通过 `getStandCode()` 获取符号错误码，而非数值 `getCode()`：
service 或 region 非字符串/纯空白时报 `InvalidArgument`；region 格式不支持时报 `InvalidRegion`；服务未注册时报 `ServiceNotFound`；自定义条目形态不支持时报 `InvalidCustomService`；占位符不存在时报 `TemplateExecuteError`。
这不是完整的域名合法性校验，调用方仍须保证模板字面量和替换值适合作为域名。

DualStack 优先使用显式 `true`/`false`，`null` 时读取 `BYTEPLUS_ENABLE_DUALSTACK`。`customBootstrapRegion` 不参与寻址。
与默认 Provider 不同，Standard 没有服务级 `goChinaEnabled` 标志，`CNSuffix` 采用上表规则。

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

use Byteplus\Common\Configuration;
use Byteplus\Common\Endpoint\Providers\StandardEndpointProvider;

$provider = new StandardEndpointProvider(
    '{Service}{Region}.{Tenant}.{SiteStack}.com{CNSuffix}',
    null,
    ['Tenant' => 'tenant-a'],
    ['mysvc' => ['isGlobal' => false]]
);
$host = $provider->endpointFor('mysvc', 'ap-southeast-1', null, false)->host;
// mysvc.ap-southeast-1.tenant-a.byteplusapi.com
$config = (new Configuration())
    ->setEndpointProvider($provider)
    ->setRegion('ap-southeast-1')
    ->setUseDualStack(false);
```

---

[← 访问凭据](1-Credentials-zh.md) | Endpoint 配置[(English)](2-Endpoint.md) | [Transport →](3-Transport-zh.md)
