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

test('CLI crashes render as plain text, not an HTML page', function () {
    // an agent or script reading stdout must see the message, not 40KB
    // of markup with the error buried in a <title> tag
    $hub = new Hub();
    $hub->reportTo($spy = new HandlerSpyReporter());
    $handler = new Handler($hub, ['debug' => true]);

    ob_start();
    $handler->handle(new RuntimeException('kaboom'));
    $out = ob_get_clean();

    expect($out)->toContain('RuntimeException: kaboom')
        ->not->toContain('<!doctype html>')
        ->not->toContain('<style');

    $hub->dispatcher()->flush();
    expect($spy->received)->toHaveCount(1);
    expect($spy->received[0]->message)->toBe('kaboom');
});

test('debug mode renders the crash page for web requests', function () {
    $hub = new Hub();
    $report = $hub->capture(new RuntimeException('kaboom'));

    $html = (new \Leaf\Crash\Renderer\HtmlRenderer())->render($report, []);

    expect($html)->toContain('<!doctype html>')->toContain('kaboom')->toContain('leaf');
});

test('production mode hides internals but still reports', function () {
    $hub = new Hub();
    $hub->reportTo($spy = new HandlerSpyReporter());
    $handler = new Handler($hub, ['debug' => false]);

    $page = Handler::productionPage();

    expect($page)->toContain('Something went wrong');
    expect($page)->not->toContain('secret internals');
    expect($page)->not->toContain('RuntimeException');

    ob_start();
    $handler->handle(new RuntimeException('secret internals'));
    ob_end_clean();

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
