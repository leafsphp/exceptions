<?php

use Leaf\Crash\Frame;
use Leaf\Crash\Redactor;
use Leaf\Crash\Report;

/*
 * The app fixture lives outside vendor/, so its frames count as
 * application code; everything thrown inside pest/phpunit counts as
 * vendor. A helper "app" function gives us a stable application frame.
 */

function appExplosion(string $message = 'boom'): \RuntimeException
{
    return new \RuntimeException($message);
}

function reportFor(\Throwable $e, array $options = []): Report
{
    return Report::from($e, array_merge(['appRoot' => dirname(__DIR__)], $options));
}

test('a report captures the exception identity', function () {
    $report = reportFor(appExplosion('database exploded'));

    expect($report->exception)->toBe(RuntimeException::class);
    expect($report->message)->toBe('database exploded');
    expect($report->id)->toHaveLength(16);
    expect(strtotime($report->occurredAt))->toBeGreaterThan(0);
});

test('frames start at the throw site and include code excerpts', function () {
    $report = reportFor(appExplosion());

    $first = $report->frames[0];

    expect($first->file)->toBe(__FILE__);
    expect($first->excerpt)->not->toBeEmpty();
    expect($first->excerpt[$first->line])->toContain('RuntimeException');
});

test('application frames are separated from vendor frames', function () {
    $report = reportFor(appExplosion());

    expect($report->frames[0]->isApplication)->toBeTrue();

    $vendorFrames = array_filter($report->frames, fn (Frame $f) => !$f->isApplication);
    expect($vendorFrames)->not->toBeEmpty();

    expect($report->applicationFrame()->file)->toBe(__FILE__);
});

test('fingerprints group the same crash and separate different ones', function () {
    $a = reportFor(appExplosion('first occurrence'));
    $b = reportFor(appExplosion('second occurrence, different message'));
    $c = reportFor(new \LogicException('first occurrence'));

    // same crash site + type → same issue, regardless of message
    expect($a->fingerprint)->toBe($b->fingerprint);
    // different exception type → different issue
    expect($c->fingerprint)->not->toBe($a->fingerprint);
});

test('fingerprints do not depend on the line number', function () {
    $report = reportFor(appExplosion());

    // the fingerprint hashes class + file + callable — assert the line
    // number is not an ingredient by checking it against a hand-built hash
    $frame = $report->applicationFrame();
    $relativeFile = substr($frame->file, strlen(dirname(__DIR__)) + 1);

    expect($report->fingerprint)->toBe(sha1(implode('|', [
        RuntimeException::class,
        $relativeFile,
        $frame->callable(),
    ])));
});

test('request, app and user context are carried and redacted', function () {
    $report = reportFor(appExplosion(), [
        'request' => [
            'method' => 'POST',
            'url' => '/checkout',
            'headers' => ['Authorization' => 'Bearer abc123', 'Accept' => 'application/json'],
            'body' => ['email' => 'user@example.com', 'card_number' => '4242424242424242'],
        ],
        'app' => ['php' => PHP_VERSION, 'STRIPE_SECRET' => 'sk_live_123'],
        'user' => ['id' => 7, 'password' => 'hunter2'],
    ]);

    expect($report->request['method'])->toBe('POST');
    expect($report->request['headers']['Accept'])->toBe('application/json');
    expect($report->request['headers']['Authorization'])->toBe(Redactor::MASK);
    expect($report->request['body']['card_number'])->toBe(Redactor::MASK);
    expect($report->request['body']['email'])->toBe('user@example.com');
    expect($report->app['STRIPE_SECRET'])->toBe(Redactor::MASK);
    expect($report->user['password'])->toBe(Redactor::MASK);
    expect($report->user['id'])->toBe(7);
});

test('custom redaction patterns extend the defaults', function () {
    $report = reportFor(appExplosion(), [
        'request' => ['body' => ['national_id' => '123456789']],
        'redact' => ['national_id'],
    ]);

    expect($report->request['body']['national_id'])->toBe(Redactor::MASK);
});

test('the previous exception chain is captured', function () {
    $inner = new \PDOException('connection refused');
    $outer = new \RuntimeException('query failed', 0, $inner);

    $report = reportFor($outer);

    expect($report->previous['exception'])->toBe(PDOException::class);
    expect($report->previous['message'])->toBe('connection refused');
});

test('reports serialize to json and back without loss of shape', function () {
    $report = reportFor(appExplosion(), [
        'request' => ['method' => 'GET', 'url' => '/'],
        'breadcrumbs' => ['[info] cache warmed', '[error] retrying db'],
    ]);

    $decoded = json_decode($report->toJson(), true);

    expect($decoded['exception'])->toBe(RuntimeException::class);
    expect($decoded['fingerprint'])->toBe($report->fingerprint);
    expect($decoded['frames'][0]['file'])->toBe(__FILE__);
    expect($decoded['frames'][0]['excerpt'])->not->toBeEmpty();
    expect($decoded['breadcrumbs'])->toHaveCount(2);
});

test('unserializable context does not break json output', function () {
    $report = reportFor(appExplosion(), [
        'request' => ['body' => ['stream' => fopen('php://memory', 'r')]],
    ]);

    expect(json_decode($report->toJson(), true))->toBeArray();
});

test('markdown serialization reads like a bug report', function () {
    $inner = new \PDOException('connection refused');
    $report = reportFor(new \RuntimeException('query failed', 0, $inner), [
        'request' => ['method' => 'POST', 'url' => '/checkout'],
        'breadcrumbs' => ['[error] db retry 1'],
    ]);

    $markdown = $report->toMarkdown();

    expect($markdown)
        ->toContain('# RuntimeException')
        ->toContain('> query failed')
        ->toContain('POST /checkout')
        ->toContain('## Stack trace')
        ->toContain('(app)')
        ->toContain('## Caused by: PDOException')
        ->toContain('## Breadcrumbs')
        ->toContain('```php');
});

test('internal frames without files are handled', function () {
    // call_user_func produces an internal frame with no file
    $report = null;

    try {
        array_map(function () {
            throw appExplosion('inside internal call');
        }, [1]);
    } catch (\Throwable $e) {
        $report = reportFor($e);
    }

    expect($report)->not->toBeNull();
    expect($report->toMarkdown())->toContain('inside internal call');
    expect(json_decode($report->toJson(), true))->toBeArray();
});
