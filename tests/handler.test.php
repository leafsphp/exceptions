<?php

use Leaf\Crash\Handler;
use Leaf\Crash\Hub;
use Leaf\Crash\Report;
use Leaf\Crash\Reporter;

class HandlerSpyReporter implements Reporter
{
    public array $received = [];

    public function report(Report $report): void
    {
        $this->received[] = $report;
    }
}

test('debug mode renders the crash page and captures through the hub', function () {
    $hub = new Hub();
    $hub->reportTo($spy = new HandlerSpyReporter());
    $handler = new Handler($hub, ['debug' => true]);

    ob_start();
    $handler->handle(new RuntimeException('kaboom'));
    $html = ob_get_clean();

    expect($html)->toContain('<!doctype html>')->toContain('kaboom')->toContain('leaf');

    $hub->dispatcher()->flush();
    expect($spy->received)->toHaveCount(1);
    expect($spy->received[0]->message)->toBe('kaboom');
});

test('production mode hides internals but still reports', function () {
    $hub = new Hub();
    $hub->reportTo($spy = new HandlerSpyReporter());
    $handler = new Handler($hub, ['debug' => false]);

    ob_start();
    $handler->handle(new RuntimeException('secret internals'));
    $html = ob_get_clean();

    expect($html)->toContain('Something went wrong');
    expect($html)->not->toContain('secret internals');
    expect($html)->not->toContain('RuntimeException');

    $hub->dispatcher()->flush();
    expect($spy->received)->toHaveCount(1); // hidden from users, never from you
});

test('a custom renderer takes over output, eg for JSON APIs', function () {
    $handler = new Handler(new Hub(), ['debug' => false]);
    $handler->renderWith(fn (Report $report, bool $debug) => json_encode([
        'error' => 'internal',
        'id' => $report->id,
    ]));

    ob_start();
    $handler->handle(new RuntimeException('api crash'));
    $out = ob_get_clean();

    $decoded = json_decode($out, true);
    expect($decoded['error'])->toBe('internal');
    expect($decoded['id'])->toHaveLength(16);
});
