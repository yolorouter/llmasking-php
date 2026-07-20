# llmasking-php playground — 端到端测试工具

[English](../README.md) · 🌐 **简体中文**

一个零额外依赖的本地 Web UI，用于对**真实 LLM 端点**运行完整的「脱敏 → 发送 → 还原」管道，每一步都在浏览器中可见。

## 解决什么问题

单元测试验证库的逻辑正确，但无法回答一个更基本的问题：「接上我实际使用的 LLM 服务，这真的能用吗？」Playground 就是来回答这个的：

像聊天一样发一条消息，右侧的「处理管道」面板实时展示这一轮的四个阶段：

1. **原始输入** — 你在聊天框输入的文本
2. **脱敏后（发送内容）** — 实际通过网络发出的内容，敏感值已替换为占位符（如 `[PHONE_1]`）
3. **模型原始回复** — LLM 端点的原始响应，未修改——证明它从未看到真实的手机号/邮箱等
4. **还原后** — 用户最终看到的内容，占位符已被还原为原始值

流式模式下，阶段 ②③④ 随 SSE chunk 实时刷新，你可以观察「占位符跨 chunk 边界拆分」的边缘情况是如何被正确处理的。

## 快速开始

```bash
./playground/playground
# → 🚀 llmasking-php playground → http://127.0.0.1:8787
```

在浏览器中打开该 URL。

也可以直接用 PHP 内置服务器：

```bash
php -S 127.0.0.1:8787 playground/server.php
```

自定义端口：

```bash
./playground/playground --port 9000
# 或
php -S 127.0.0.1:9000 playground/server.php
```

## 配置

在浏览器界面中填写：

| 字段 | 说明 | 示例 |
|---|---|---|
| **协议** | LLM API 协议 | OpenAI / Anthropic / Gemini |
| **Base URL** | API 基础地址（含版本前缀） | `https://api.openai.com/v1` |
| **API Key** | 你的 API 密钥 | `sk-...` |
| **Model** | 模型名称 | `gpt-4o` |

然后像聊天一样发消息。

## 支持的 LLM 协议

| 协议 | URL 路径 | 认证方式 |
|---|---|---|
| **OpenAI** | `{baseUrl}/chat/completions` | `Authorization: Bearer {key}` |
| **Anthropic** | `{baseUrl}/messages` | `x-api-key: {key}` + `anthropic-version: 2023-06-01` |
| **Gemini** | `{baseUrl}/models/{model}:generateContent` | `x-goog-api-key: {key}` |

所有协议同时支持**非流式**和**流式（SSE）**两种模式。流式模式下，playground 使用 `StreamRestorer` 增量还原，逐 chunk 推送到浏览器。

## 内置示例

界面提供了两组内置示例（点击即可填充）：

- **个人信息**：手机号、身份证/SSN、邮箱——展示可逆脱敏（Placeholder）
- **API 密钥**：多个 LLM 供应商的示例密钥——展示不可逆脱敏（Redact，SECRET 家族）

## 安全说明

- Playground **仅绑定 127.0.0.1**（loopback），不接受外部连接。
- 它会将浏览器提交的 `baseUrl` 原样转发给 LLM 端点，并在出错时回显目标端的错误响应——**不要在不可信网络上绑定非 loopback 地址**。
- API Key 仅在浏览器→本地 playground→LLM 端点之间传输，不经过任何第三方。
