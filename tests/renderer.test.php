<?php

use Leaf\Crash\Breadcrumbs;
use Leaf\Crash\Hub;
use Leaf\Crash\Renderer\HtmlRenderer;
use Leaf\Crash\Report;

function renderedCrash(array $options = []): string
{
    $hub = new Hub();
    $hub->context([
        'appRoot' => dirname(__DIR__),
        // built by concatenation so the secret never appears verbatim in
        // this file's code excerpt — we're asserting on context redaction
        'request' => ['method' => 'POST', 'url' => '/checkout', 'body' => ['password' => 'hunt' . 'er2']],
        'app' => ['env' => 'testing'],
    ]);
    $hub->leaveCrumb('POST /checkout', Breadcrumbs::TYPE_REQUEST);

    $report = $hub->capture(new RuntimeException('charge <b>failed</b>'));

    return (new HtmlRenderer())->render($report, $options);
}

test('the crash page is a self-contained html document', function () {
    $html = renderedCrash();

    expect($html)
        ->toStartWith('<!doctype html>')
        ->toContain('RuntimeException')
        ->toContain('leaf crash')
        ->not->toContain('http://cdn')
        ->not->toContain('src="http');
});

test('exception messages are escaped', function () {
    $html = renderedCrash();

    expect($html)->not->toContain('<b>failed</b>');
    expect($html)->toContain('charge &lt;b&gt;failed&lt;/b&gt;');
});

test('the page carries frames, journey, context and redaction', function () {
    $html = renderedCrash();

    expect($html)
        ->toContain('User journey')
        ->toContain('POST /checkout')
        ->toContain('vendor frames')
        ->toContain('redacted')
        // needle built by concatenation: this page excerpts the calling
        // test's own source, so a literal here would always match itself
        ->not->toContain('hunt' . 'er2');
});

test('ai actions and markdown copy are wired', function () {
    $html = renderedCrash(['projectContext' => '# Test app context']);

    expect($html)
        ->toContain('Open in Claude')
        ->toContain('https://claude.ai/new?q=')
        ->toContain('Open in ChatGPT')
        ->toContain('Copy as Markdown')
        ->toContain('Test%20app%20context');
});

test('a solution hint renders when provided', function () {
    $html = renderedCrash(['solution' => 'Run your migrations']);

    expect($html)->toContain('Run your migrations');
});

test('vendor frame runs are grouped', function () {
    $report = Report::from(new RuntimeException('x'), ['appRoot' => dirname(__DIR__)]);

    $groups = HtmlRenderer::groupFrames($report->frames);

    expect($groups)->not->toBeEmpty();
    expect($groups[0]['type'])->toBe('app');

    foreach ($groups as $group) {
        foreach ($group['frames'] as $item) {
            expect($item['frame']->isApplication)->toBe($group['type'] === 'app');
        }
    }
});

test('journey steps with a known origin are clickable code targets', function () {
    $html = renderedCrash();

    expect($html)->toContain('showOrigin(');
    expect($html)->toContain('step clickable');
});

test('ai actions live in one dropdown with prompt copy and json download', function () {
    $html = renderedCrash();

    expect($html)
        ->toContain('Open with AI')
        ->toContain('ask about this crash')
        ->toContain('Copy AI prompt')
        ->toContain('Download report (JSON)')
        ->toContain('vscode://file/');
});

test('editor links, curl copy and new sections render', function () {
    $inner = new PDOException('connection refused');
    $hub = new Hub();
    $hub->context([
        'appRoot' => dirname(__DIR__),
        'request' => ['method' => 'POST', 'url' => 'http://localhost/checkout', 'headers' => ['Accept' => 'application/json'], 'body' => ['coupon' => 'X']],
    ]);
    $hub->timing()->start('db');
    $hub->timing()->stop('db');

    $report = $hub->capture(new RuntimeException('query failed', 0, $inner), [
        'peeks' => ['order' => ['total' => 0]],
    ]);
    $report->occurrences = 12;

    $html = (new HtmlRenderer())->render($report, ['editor' => 'phpstorm']);

    expect($html)
        ->toContain('phpstorm://open?file=')
        ->toContain('Copy request as cURL')
        ->toContain('curl -X')
        ->toContain('caused by')
        ->toContain('PDOException')
        ->toContain('peeked values')
        ->toContain('timings')
        ->toContain('12 times');
});
