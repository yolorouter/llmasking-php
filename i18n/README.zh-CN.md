# llmasking-php

[![CI](https://github.com/yolorouter/llmasking-php/actions/workflows/ci.yml/badge.svg)](https://github.com/yolorouter/llmasking-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)

[English](../README.md) · 🌐 **简体中文**

**一个 PHP 库，在敏感数据到达 LLM 之前将其脱敏，并在响应返回后还原——模型、网络和供应商日志都不会看到真实值。**

手机号、身份证号/SSN、银行卡号、邮箱、云凭证、私钥、JWT 以及你自定义的关键词，会在请求离开你的进程之前被检测并替换为占位符（`[PHONE_1]`）。当 LLM 的回复引用该占位符时，`llmasking` 将其还原——包括流式（SSE）响应中占位符跨 chunk 边界被拆分的情况。

```php
$engine = Engine::new();
$session = $engine->newSession();

$result = $session->anonymize("我是张三，手机号 13800138000");
// $result->text → "我是张三，手机号 [PHONE_1]"

// ... 将 $result->text 发送给 LLM ...

$restored = $session->restore("好的，我会联系 [PHONE_1]");
// $restored->text → "好的，我会联系 13800138000"
```

已经在用 PSR-18 HTTP 客户端（Guzzle、Symfony 等）？`MaskingClient` 装饰器自动检测请求格式并脱敏所有支持的自由文本字段——你的 SDK 和框架代码不需要改动：

```php
$client = new MaskingClient(
    $innerClient,       // 任意 PSR-18 ClientInterface
    $streamFactory,     // 任意 PSR-17 StreamFactoryInterface
    $engine,
);
// 每个发出的请求自动脱敏；每个响应（包括 SSE 流）自动还原。
```

## 目录

- [工作原理](#工作原理)
- [安装](#安装)
- [功能特性](#功能特性)
- [支持的实体类型](#支持的实体类型)
- [配置](#配置)
- [脱敏策略](#脱敏策略)
- [用法](#用法)
  - [传输层装饰器](#传输层装饰器)
  - [报告](#报告脱敏与还原详情)
  - [一次性脱敏](#一次性脱敏无需还原)
- [Playground](#playground)
- [错误处理](#错误处理)
- [限制](#限制)
- [质量](#质量)
- [致谢](#致谢)
- [许可证](#许可证)

## 工作原理

`llmasking` 采用与 [microsoft/presidio](https://github.com/microsoft/presidio) 相同的两阶段 Analyzer/Anonymizer 架构，编译为单次 PHP 调用：

1. **识别** — 每个激活的 `Recognizer` 独立扫描输入并报告 `Finding`：文本范围、实体名称和置信度。识别器是正则规则（适用时做校验——银行卡 Luhn、中国身份证 ISO 7064）或用于自定义关键词的 Aho-Corasick 多模式匹配器。
2. **冲突解决** — 重叠的发现被缩减为非重叠集合。`SECRET` 家族始终优先；否则最长匹配优先，然后是最高分。
3. **应用策略** — 每个幸存的发现按其实体的 `Strategy` 替换：默认 `Placeholder`，密钥用 `Redact`，或你自定义的配置。
4. **跟踪（可逆时）** — `Placeholder` 替换记录在 `Session` 的映射表中；`Redact`/`MaskMiddle`/`Hash` 永不记录。因此密钥在*结构上*是不可还原的。

还原是镜像操作：`Session::restore()` 扫描占位符形式的 token 并在映射表中查找。

## 安装

```bash
composer require yolorouter/llmasking-php
```

PHP 8.1+。依赖：PSR-18 / PSR-17 / PSR-7 接口和 `wikimedia/aho-corasick`（关键词匹配）。

## 功能特性

- **PSR-18 传输层装饰器**：`MaskingClient` 包装任意 PSR-18 客户端，脱敏 JSON 请求中的已知自由文本字段，并还原响应中的占位符——包括 SSE 流。支持 gzip 解压、完整性头检测和失败体降级。
- **两层检测**：Aho-Corasick 关键词匹配 + 区域标记正则规则（中国身份证校验、银行卡 Luhn、美国 SSN 过滤）。`WithRegions` 裁剪加载哪些地理规则包。
- **密钥检测**：云访问密钥、PEM 私钥、JWT、git token、高熵密码——全部默认不可逆（`Redact`）。密钥在冲突解决中始终优先于任何重叠匹配。
- **会话级还原**：同一实体在会话中始终映射到同一占位符；还原容忍 LLM 轻微改写（缺括号、零填充数字、全角括号）。
- **流式安全**：`StreamRestorer` 在占位符可能跨边界拆分时保留足够的 chunk 尾部——包括多字节 UTF-8 字符的中间字节。
- **结构化报告**：`anonymize()`/`restore()` 返回 `MaskEvent`/`RestoreEvent` 数组；`TransportOptions::withMaskReport()`/`withRestoreReport()` 按请求/响应交付。
- **Fail-closed**：任何库无法安全处理的输入或状态都抛出异常。每会话限制防止内存耗尽。
- **不仅限 LLM**：`Engine::mask()` 做一次性、不可逆脱敏——用于日志、数据导出，无需 Session。

## 支持的实体类型

| 实体 | 占位符 | 匹配内容 | 区域 | 默认策略 |
|---|---|---|---|---|
| `PHONE` | `[PHONE_1]` | 中国手机、美国电话、国际 `+` 前缀 | 中国 / 美国 / 通用 | Placeholder |
| `IDCARD` | `[IDCARD_1]` | 18 位中国居民身份证（ISO 7064 校验） | 中国 | Placeholder |
| `LANDLINE` | `[LANDLINE_1]` | 中国座机（区号 + 号码） | 中国 | Placeholder |
| `SSN` | `[SSN_1]` | 美国社会安全号 | 美国 | Placeholder |
| `EMAIL` | `[EMAIL_1]` | 邮箱地址 | 通用 | Placeholder |
| `BANKCARD` | `[BANKCARD_1]` | Luhn 有效 13–19 位卡号 | 通用 | Placeholder |
| `IP` | `[IP_1]` | IPv4 地址 | 通用 | Placeholder |
| `URL` | `[URL_1]` | `http(s)` URL | 通用 | Placeholder |
| `KEYWORD` | `[KEYWORD_1]` | 你的自定义关键词 | 通用 | Placeholder |
| `CLOUDKEY` | `[CLOUDKEY_1]` | AWS `AKIA...`、阿里云 `LTAI...`/`AKID...` | 通用 | Redact |
| `PRIVATEKEY` | `[PRIVATEKEY_1]` | PEM 私钥块 | 通用 | Redact |
| `JWT` | `[JWT_1]` | JSON Web Token | 通用 | Redact |
| `GITTOKEN` | `[GITTOKEN_1]` | GitHub `ghp_`/`gho_`、GitLab `glpat-` | 通用 | Redact |
| `SECRET` | `[SECRET_1]` | 通用高熵字符串 | 通用 | Redact |

`CLOUDKEY`/`PRIVATEKEY`/`JWT`/`GITTOKEN`/`SECRET` 构成 **SECRET 家族**：它们始终在冲突解决中优先，且 `WithStrategy` 拒绝为它们分配可逆策略。

## 配置

`Engine::new()` 接受零或多个 `EngineOption`；所有校验在构造时完成。

| 选项 | 用途 | 默认值 |
|---|---|---|
| `EngineOption::withRecognizers(...)` | 替换默认识别器集合 | 全部 11 个内置识别器 |
| `EngineOption::withKeywords(...)` | 添加自定义关键词识别器 | 无 |
| `EngineOption::withRegions(...)` | 裁剪地理规则包 | 全部区域 |
| `EngineOption::withStrategy($entity, $strategy)` | 覆盖某实体的策略 | SECRET 用 Redact，其余用 Placeholder |
| `EngineOption::withEntityType($name)` | 注册自定义实体名 | — |
| `EngineOption::withMaxEntities($n)` | 每会话最大发现数 | 10,000 |
| `EngineOption::withMaxSessionBytes($n)` | 映射表最大字节数 | 10 MB |
| `EngineOption::withMaxInputBytes($n)` | 每次调用最大输入 | 1 MB |
| `EngineOption::withMaxOutputBytes($n)` | 每次调用最大输出 | 16 MB |

```php
$engine = Engine::new(
    EngineOption::withRegions(Region::US),
    EngineOption::withKeywords('Project Chimera', 'internal codename X'),
    EngineOption::withStrategy('PHONE', Strategies::maskMiddle()),
    EngineOption::withMaxEntities(50000),
);
```

## 脱敏策略

| 策略 | 输出 | 可逆 | 典型用途 |
|---|---|---|---|
| `Placeholder` | `[PHONE_1]` | 是 | 默认——经 LLM 往返 |
| `Redact` | `[SECRET_1]` | 否 | SECRET 家族默认 |
| `MaskMiddle` | `138****8000` | 否 | 保留值的形状可见 |
| `Hash` | SHA-256 前 8 位十六进制 | 否 | 确定性关联 |

```php
$engine = Engine::new(
    EngineOption::withStrategy('PHONE', Strategies::maskMiddle()),
);
$session = $engine->newSession();
$result = $session->anonymize('13800138000');
// $result->text → "138****8000"
```

实现 `Strategy` 接口（`apply(Finding $f, int $seq): string`）来自定义策略——始终不可逆。

## 用法

### 基本往返

```php
use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\EngineOption;

$engine = Engine::new();
$session = $engine->newSession();

// 脱敏
$result = $session->anonymize('邮箱 a@example.com，手机 13800138000');
echo $result->text;
// "邮箱 [EMAIL_1]，手机 [PHONE_1]"

// ... 将 $result->text 发送给 LLM ...

// 还原
$restored = $session->restore($llmReply);
echo $restored->text;
// 原始值已还原
```

### 传输层装饰器

`MaskingClient` PSR-18 装饰器包装任意 HTTP 客户端。它检测 `application/json` POST 请求，脱敏所有已知自由文本字段（消息内容、工具描述、Schema 注解），并还原响应中的占位符——包括 `text/event-stream` SSE。

```php
use Yolorouter\Llmasking\Transport\MaskingClient;
use Yolorouter\Llmasking\Transport\TransportOptions;

$client = new MaskingClient(
    $guzzle,            // PSR-18 ClientInterface
    $streamFactory,     // PSR-17 StreamFactoryInterface
    Engine::new(),
    TransportOptions::withPassthrough(),  // 可选：转发无法解析的 body
);

// 将 $client 作为你的 PSR-18 客户端使用——脱敏和还原自动完成。
$response = $client->sendRequest($request);
```

### 报告脱敏与还原详情

```php
$client = new MaskingClient(
    $inner, $factory, $engine,
    TransportOptions::withMaskReport(function ($request, $events) {
        foreach ($events as $e) {
            error_log("已脱敏 {$e->entity} → {$e->replacement}");
        }
    }),
    TransportOptions::withRestoreReport(function ($request, $events, $complete, $error) {
        foreach ($events as $e) {
            if (!$e->restored) {
                error_log("未解析的占位符: {$e->placeholder}");
            }
        }
    }),
);
```

### 一次性脱敏（无需还原）

用于日志、数据导出或任何只写场景——无 Session、无映射表、并发安全：

```php
$engine = Engine::new();
$masked = $engine->mask('user 13800138000 login failed');
// → "user [PHONE_1] login failed"
```

## Playground

一个本地 Web UI，用于对真实 LLM 端点进行端到端测试——详见 [`playground/README.md`](../playground/README.md)：

```bash
./playground/playground
# → 🚀 llmasking-php playground → http://127.0.0.1:8787
```

在浏览器中打开该 URL，配置你的 LLM 端点，发送消息，观察 脱敏 → LLM → 还原 管道的每个阶段——支持流式和非流式。

## 错误处理

所有错误实现 `LlmaskingException`（继承 `\Throwable`）。类型化子类：

| 异常 | 何时抛出 |
|---|---|
| `LimitExceededException` | 资源上限将被超出 |
| `InvalidUTF8Exception` | 输入不是有效的 UTF-8 |
| `InvalidConfigException` | `EngineOption` 无效 |
| `InvalidFindingException` | 识别器产生了无效 Finding |
| `StreamClosedException` | StreamRestorer 在终态后使用 |
| `InvalidRequestException` | （传输层）请求体无法安全处理 |
| `StreamRestoreException` | （传输层）响应还原失败 |

## 限制

- **Session 仅存在于进程内存中**——不持久化、不跨进程共享。
- **`Session::anonymize()` 不可并发**（会修改映射表）。`Session::restore()` 只读。
- **传输层重定向限制**：每次 `sendRequest()` 创建新 Session；307/308 重定向的新 Session 无法还原前一跳的占位符。
- **支持的传输字段**：OpenAI Chat Completions（消息 content/refusal/tool arguments、tools/functions descriptions、JSON Schema 注解）。SSE 流式（delta content/refusal/arguments）。未知字段原样透传。

## 质量

- 475 测试 / 4617 断言
- PHPStan `max` 级别无错误
- PSR-12 编码规范
- 多轮 adversarial code review（codex）approve

## 致谢

本项目是 [llmasking-go](https://github.com/yolorouter/llmasking-go) 的 PHP 移植，其设计和规则集参考了：

- [microsoft/presidio](https://github.com/microsoft/presidio) — Analyzer/Anonymizer 管道架构
- [bytedance/godlp](https://github.com/bytedance/godlp) — 中国地区识别规则
- [gitleaks/gitleaks](https://github.com/gitleaks/gitleaks) — 密钥检测模式
- [wikimedia/aho-corasick](https://packagist.org/packages/wikimedia/aho-corasick) — 关键词匹配引擎

## 许可证

MIT，详见 [LICENSE](../LICENSE)。
