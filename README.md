<!-- markdownlint-disable no-inline-html -->
<p align="center">
    <br><br>
    <img src="https://leafphp.dev/logo-circle.png" height="100"/>
    <br><br>
</p>

# Leaf Crash

[![Total Downloads](https://img.shields.io/packagist/dm/leafs/exception.svg)](https://packagist.org/packages/leafs/exception)
[![Latest Version](http://img.shields.io/packagist/v/leafs/exception.svg)](https://packagist.org/packages/leafs/exception)

Errors that explain themselves. Leaf Crash turns an exception into a full story: where the code broke, what the user was doing on the way there, and a one-click briefing your AI assistant can act on.

Every framework shows you a stack trace. Leaf shows you the fifteen seconds before it.

> Leaf 5 replaces the previous whoops-based handler in this package with a new engine, written from scratch. The legacy `Leaf\Exception` classes still ship for compatibility while leaf core migrates, but all new work happens in `Leaf\Crash`.

## How it works

One exception becomes one `Report`: a plain, serializable object carrying everything a renderer or reporter could need. Nothing downstream ever touches the raw throwable.

```txt
Exception thrown (or manual checkpoint)
      ↓
  Inspector   parses frames + code excerpts
      ↓
   Report     one canonical, serializable value object
      ↓
 Renderers                     Reporters
 (respond to this request)     (deliver after the response)
 · HTML dev page               · log file
 · JSON for API mode           · Alchemy Cloud
 · plain text for CLI          · webhooks, anything
```

Because the report is just data, the same crash can render as a dev page, download as JSON, replay as a cURL command, or ship to a reporting service without any of those consumers knowing about each other.

## Quick start

```php
use Leaf\Crash\Hub;

$hub = new Hub();

$hub->context([
    'appRoot' => __DIR__,
    'user' => ['id' => auth()->id()],
    'request' => ['method' => 'POST', 'url' => '/checkout'],
]);

try {
    checkout($cart);
} catch (Throwable $error) {
    $report = $hub->capture($error);

    echo (new Leaf\Crash\Renderer\HtmlRenderer())->render($report);
}
```

Inside a Leaf app you won't wire this yourself: leaf core registers the hub and handler for you. The API above is what you reach for in custom setups, workers, and tests.

## The user journey

Record what the app is doing as it runs. When something breaks, the trail rides along on the report, so the crash page shows the steps that led there, and each step links to the line of code that recorded it.

```php
$hub->leaveCrumb('POST /checkout', Breadcrumbs::TYPE_REQUEST);
$hub->leaveCrumb('coupon applied', Breadcrumbs::TYPE_ACTION, ['total_after' => $total]);
```

Recording a crumb is an array write. The trail is capped, so always-on use costs nothing worth measuring.

## Checkpoints: capture without an exception

Some bugs never throw. The checkout "works" and the total is somehow zero. Capture a checkpoint and it is treated exactly like a crash: stack trace starting at your call site, journey, context, its own fingerprint for grouping.

```php
if ($order['total'] <= 0 && count($order['items']) > 0) {
    $hub->capture('order total is 0 but cart has items', [
        'level' => 'warning',
        'peeks' => ['order' => $order],
    ]);
}
```

`peeks` snapshots variables with hard bounds (depth, item count, string length), so a peek can never balloon a report or recurse forever.

## Timing spans

The base for performance monitoring. A span costs two `microtime()` calls and an array write, and rides on any report captured later:

```php
$cart = $hub->span('db: load cart', fn () => Cart::for($userId));
```

## Reporters

A reporter takes the report somewhere: a log file, a webhook, Alchemy Cloud. Reporters run after the response is sent, and a reporter that throws is isolated, so reporting can never slow down or break your app.

```php
$hub->reportTo(new MyLogReporter());
$hub->countWith(new MyOccurrenceStore()); // "seen 12 times" counts, when a store exists
```

Both are single-method interfaces (`Reporter`, `OccurrenceStore`). This package ships no storage on purpose: persistence belongs to the store you attach.

## The crash page

The HTML renderer produces a single self-contained document. No CDNs, no fonts to fetch, nothing that fails when everything else is failing. It shows the stack with vendor frames collapsed, code excerpts, the user journey, request/app/user context, the caused-by chain, peeked values, and timings. Frames link straight into your editor (`vscode`, `phpstorm`, `cursor`, `sublime`, `zed`).

The "Open with AI" menu builds a briefing from three layers: your project's `.leaf/CONTEXT.md`, the user journey, and the crash itself with code, then opens it in Claude or ChatGPT, copies it as a prompt, or downloads the report as JSON. In testing, the same model that misdiagnosed a bug from a bare stack trace reconstructed the real cause from the report, citing the journey as evidence.

## Security

- Secrets are stripped when the report is created, not when it is displayed. No renderer or reporter, local or remote, ever sees a masked value.
- Redaction covers keys matching password, token, auth, cookie, card, dsn and friends, plus any patterns you add.
- The cURL export and JSON download carry the redacted values.
- The crash page has no external requests and escapes everything it prints.

## Installing

```bash
leaf install exception
# or
composer require leafs/exception
```

## Status

The `Leaf\Crash` engine is part of the Leaf 5 effort: the report core, hub, dispatcher and HTML dev page are complete and tested. The production error page, JSON/text renderers, and the leaf core wiring that retires the legacy handler are in progress. Alchemy Cloud connects through the `Reporter` and `OccurrenceStore` seams when it arrives.
