# llmasking-php

[![CI](https://github.com/yolorouter/llmasking-php/actions/workflows/ci.yml/badge.svg)](https://github.com/yolorouter/llmasking-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)

🌐 **English** · [简体中文](i18n/README.zh-CN.md)

**A PHP library that masks sensitive data before it reaches an LLM, and restores it afterward — so the model, the network, and the vendor's logs never see the real values.**

Phone numbers, national ID numbers/SSNs, bank cards, emails, cloud credentials, private keys, JWTs, and your own custom keywords are detected and replaced with placeholders (`[PHONE_1]`) before a request leaves your process. When the LLM's reply references that placeholder, `llmasking` swaps it back — including in streamed (SSE) responses, where a placeholder can be split across chunk boundaries.

```php
$engine = Engine::new();
$session = $engine->newSession();

$result = $session->anonymize("I'm John, my phone is 13800138000");
// $result->text → "I'm John, my phone is [PHONE_1]"

// ... send $result->text to the LLM ...

$restored = $session->restore("Sure, I'll contact [PHONE_1]");
// $restored->text → "Sure, I'll contact 13800138000"
```

Already using a PSR-18 HTTP client (Guzzle, Symfony, ...)? The `MaskingClient` decorator auto-detects the request format and anonymizes every supported free-text field — your SDK and framework code stay untouched:

```php
$client = new MaskingClient(
    $innerClient,       // any PSR-18 ClientInterface
    $streamFactory,     // any PSR-17 StreamFactoryInterface
    $engine,
);
// Every outgoing request is anonymized; every response (including SSE streams) is restored.
```

## Table of contents

- [How it works](#how-it-works)
- [Install](#install)
- [Features](#features)
- [Supported entity types](#supported-entity-types)
- [Configuration](#configuration)
- [Masking strategies](#masking-strategies)
- [Usage](#usage)
  - [Transport decorator](#transport-decorator)
  - [Reporting](#reporting-what-was-masked-and-restored)
  - [One-shot masking](#one-shot-masking-no-restore)
- [Playground](#playground)
- [Error handling](#error-handling)
- [Limitations](#limitations)
- [Quality](#quality)
- [Acknowledgements](#acknowledgements)
- [License](#license)

## How it works

`llmasking` follows the same two-stage Analyzer/Anonymizer split as [microsoft/presidio](https://github.com/microsoft/presidio), compiled into a single PHP call:

1. **Recognize** — every active `Recognizer` scans the input and reports `Finding`s: a text span, an entity name, and a confidence score. Recognizers are regex rules (checksum-validated where applicable — Luhn for bank cards, ISO 7064 for Chinese IDs) or an Aho-Corasick multi-pattern matcher for custom keywords.
2. **Resolve conflicts** — overlapping findings are reduced to a non-overlapping set. `SECRET`-family findings always win; otherwise the longest match wins, then the highest-scoring one.
3. **Apply a strategy** — each surviving finding is replaced according to its entity's `Strategy`: `Placeholder` by default, `Redact` for secrets, or whatever you configured.
4. **Track, if reversible** — a `Placeholder` replacement is recorded in the `Session`'s mapping table; `Redact`/`MaskMiddle`/`Hash` never touch it. Secrets are therefore *structurally* unrestorable.

Restoring is the mirror image: `Session::restore()` scans for placeholder-shaped tokens and looks each up in the mapping table.

## Install

```bash
composer require yolorouter/llmasking-php
```

PHP 8.1+. Dependencies: PSR-18 / PSR-17 / PSR-7 interfaces and `wikimedia/aho-corasick` (keyword matching).

## Features

- **PSR-18 transport decorator**: `MaskingClient` wraps any PSR-18 client, anonymizes known free-text fields in outgoing JSON requests, and restores placeholders in responses — including SSE streams. Supports gzip decompression, integrity-header detection, and failed-body degradation.
- **Two-layer detection**: Aho-Corasick keyword matching plus region-tagged regex rules (Chinese ID checksum, bank card Luhn, US SSN filtering). `WithRegions` trims which geographic rule packs load.
- **Secret detection**: cloud access keys, PEM private keys, JWTs, git tokens, high-entropy passwords — all non-reversible (`Redact`) by default. Secrets win conflict resolution over any overlapping match.
- **Session-scoped restoration**: the same entity always maps to the same placeholder within a session; restoration tolerates minor LLM reformatting (missing brackets, zero-padded numbers, full-width brackets).
- **Streaming-safe**: `StreamRestorer` withholds just enough of a chunk's tail when a placeholder might be split across a boundary — including mid-byte inside a multi-byte UTF-8 character.
- **Structured reporting**: `anonymize()`/`restore()` return `MaskEvent`/`RestoreEvent` arrays; `TransportOptions::withMaskReport()`/`withRestoreReport()` deliver them per request/response.
- **Fail-closed**: any input or state the library can't safely handle throws an exception. Per-session limits guard against memory exhaustion.
- **More than LLMs**: `Engine::mask()` does one-shot, non-reversible masking for logs and data export — no Session, no mapping table.

## Supported entity types

| Entity | Placeholder | What it matches | Region | Default strategy |
|---|---|---|---|---|
| `PHONE` | `[PHONE_1]` | China mobile, US phone, international `+`-prefixed | CN / US / Universal | Placeholder |
| `IDCARD` | `[IDCARD_1]` | 18-char Chinese resident ID (ISO 7064 checksum) | CN | Placeholder |
| `LANDLINE` | `[LANDLINE_1]` | China landline (area code + number) | CN | Placeholder |
| `SSN` | `[SSN_1]` | US Social Security Numbers | US | Placeholder |
| `EMAIL` | `[EMAIL_1]` | Email addresses | Universal | Placeholder |
| `BANKCARD` | `[BANKCARD_1]` | Luhn-valid 13–19 digit PAN | Universal | Placeholder |
| `IP` | `[IP_1]` | IPv4 addresses | Universal | Placeholder |
| `URL` | `[URL_1]` | `http(s)` URLs | Universal | Placeholder |
| `KEYWORD` | `[KEYWORD_1]` | Your own terms via `WithKeywords` | Universal | Placeholder |
| `CLOUDKEY` | `[CLOUDKEY_1]` | AWS `AKIA...`, Alibaba `LTAI...`/`AKID...` | Universal | Redact |
| `PRIVATEKEY` | `[PRIVATEKEY_1]` | PEM private key blocks | Universal | Redact |
| `JWT` | `[JWT_1]` | JSON Web Tokens | Universal | Redact |
| `GITTOKEN` | `[GITTOKEN_1]` | GitHub `ghp_`/`gho_`, GitLab `glpat-` | Universal | Redact |
| `SECRET` | `[SECRET_1]` | Generic high-entropy strings | Universal | Redact |

`CLOUDKEY`/`PRIVATEKEY`/`JWT`/`GITTOKEN`/`SECRET` form the **SECRET family**: they always win conflict resolution, and `WithStrategy` refuses to assign them a reversible strategy.

## Configuration

`Engine::new()` takes zero or more `EngineOption`s; all validation happens at construction time.

| Option | Purpose | Default |
|---|---|---|
| `EngineOption::withRecognizers(...)` | Replace the default recognizer set | All 11 built-ins |
| `EngineOption::withKeywords(...)` | Add custom keyword recognizer | none |
| `EngineOption::withRegions(...)` | Trim geographic rule packs | All regions |
| `EngineOption::withStrategy($entity, $strategy)` | Override strategy for one entity | Redact for SECRET, Placeholder otherwise |
| `EngineOption::withEntityType($name)` | Register a custom entity name | — |
| `EngineOption::withMaxEntities($n)` | Max findings per session | 10,000 |
| `EngineOption::withMaxSessionBytes($n)` | Max mapping-table bytes | 10 MB |
| `EngineOption::withMaxInputBytes($n)` | Max input per call | 1 MB |
| `EngineOption::withMaxOutputBytes($n)` | Max output per call | 16 MB |

```php
$engine = Engine::new(
    EngineOption::withRegions(Region::US),
    EngineOption::withKeywords('Project Chimera', 'internal codename X'),
    EngineOption::withStrategy('PHONE', Strategies::maskMiddle()),
    EngineOption::withMaxEntities(50000),
);
```

## Masking strategies

| Strategy | Output | Reversible | Typical use |
|---|---|---|---|
| `Placeholder` | `[PHONE_1]` | Yes | Default — round-trips through the LLM |
| `Redact` | `[SECRET_1]` | No | Default for SECRET family |
| `MaskMiddle` | `138****8000` | No | Keep value shape visible |
| `Hash` | First 8 hex of SHA-256 | No | Deterministic correlation |

```php
$engine = Engine::new(
    EngineOption::withStrategy('PHONE', Strategies::maskMiddle()),
);
$session = $engine->newSession();
$result = $session->anonymize('13800138000');
// $result->text → "138****8000"
```

Implement the `Strategy` interface (`apply(Finding $f, int $seq): string`) for custom strategies — always non-reversible.

## Usage

### Basic round-trip

```php
use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\EngineOption;

$engine = Engine::new();
$session = $engine->newSession();

// Mask
$result = $session->anonymize('email a@example.com, phone 13800138000');
echo $result->text;
// "email [EMAIL_1], phone [PHONE_1]"

// ... send $result->text to the LLM ...

// Restore
$restored = $session->restore($llmReply);
echo $restored->text;
// Original values swapped back
```

### Transport decorator

The `MaskingClient` PSR-18 decorator wraps any HTTP client. It detects `application/json` POST requests, anonymizes all known free-text fields (messages content, tool descriptions, schema annotations), and restores placeholders in responses — including `text/event-stream` SSE.

```php
use Yolorouter\Llmasking\Transport\MaskingClient;
use Yolorouter\Llmasking\Transport\TransportOptions;

$client = new MaskingClient(
    $guzzle,            // PSR-18 ClientInterface
    $streamFactory,     // PSR-17 StreamFactoryInterface
    Engine::new(),
    TransportOptions::withPassthrough(),  // optional: forward unparseable bodies
);

// Use $client as your PSR-18 client — masking and restoration happen automatically.
$response = $client->sendRequest($request);
```

### Reporting what was masked and restored

```php
$client = new MaskingClient(
    $inner, $factory, $engine,
    TransportOptions::withMaskReport(function ($request, $events) {
        foreach ($events as $e) {
            error_log("masked {$e->entity} → {$e->replacement}");
        }
    }),
    TransportOptions::withRestoreReport(function ($request, $events, $complete, $error) {
        foreach ($events as $e) {
            if (!$e->restored) {
                error_log("unresolved placeholder: {$e->placeholder}");
            }
        }
    }),
);
```

### One-shot masking (no restore)

For logs, data export, or anything write-only — no Session, no mapping, safe for concurrent use:

```php
$engine = Engine::new();
$masked = $engine->mask('user 13800138000 login failed');
// → "user [PHONE_1] login failed"
```

## Playground

A local web UI for end-to-end testing against any LLM endpoint — see [`playground/README.md`](playground/README.md):

```bash
./playground/playground
# → 🚀 llmasking-php playground → http://127.0.0.1:8787
```

Open the URL in a browser, configure your LLM endpoint, send a message, and watch the anonymize → LLM → restore pipeline side-by-side — for both plain and streamed responses.

## Error handling

All errors implement `LlmaskingException` (which extends `\Throwable`). Typed subclasses:

| Exception | When |
|---|---|
| `LimitExceededException` | A resource limit would be exceeded |
| `InvalidUTF8Exception` | Input is not valid UTF-8 |
| `InvalidConfigException` | An `EngineOption` is invalid |
| `InvalidFindingException` | A recognizer produced an invalid Finding |
| `StreamClosedException` | StreamRestorer used after terminal state |
| `InvalidRequestException` | (transport) Request body cannot be safely processed |
| `StreamRestoreException` | (transport) Response restore failed |

## Limitations

- **Session lives in process memory only** — not persisted or shared across processes.
- **`Session::anonymize()` is not safe for concurrent use** on the same Session (mutates mapping table). `Session::restore()` is read-only.
- **Transport redirect gap**: each `sendRequest()` creates a fresh Session; a 307/308 redirect with a new Session can't restore placeholders from the first hop.
- **Supported transport fields**: OpenAI Chat Completions (messages content/refusal/tool arguments, tools/functions descriptions, JSON Schema annotations). SSE streaming (delta content/refusal/arguments). Unknown fields pass through unchanged.

## Quality

- 475 tests / 4617 assertions
- PHPStan `max` level clean
- PSR-12 coding standard
- Multi-round adversarial code review (codex) approved

## Acknowledgements

This project is a PHP port of [llmasking-go](https://github.com/yolorouter/llmasking-go), whose design and rule sets draw on:

- [microsoft/presidio](https://github.com/microsoft/presidio) — Analyzer/Anonymizer pipeline architecture
- [bytedance/godlp](https://github.com/bytedance/godlp) — China-region recognition patterns
- [gitleaks/gitleaks](https://github.com/gitleaks/gitleaks) — Secret detection patterns
- [wikimedia/aho-corasick](https://packagist.org/packages/wikimedia/aho-corasick) — Keyword matching engine

## License

MIT, see [LICENSE](LICENSE).
