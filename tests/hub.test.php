<?php

use Leaf\Crash\Ai;
use Leaf\Crash\Breadcrumbs;
use Leaf\Crash\Dispatcher;
use Leaf\Crash\Hub;
use Leaf\Crash\Redactor;
use Leaf\Crash\Report;
use Leaf\Crash\Reporter;

class CollectingReporter implements Reporter
{
    /** @var Report[] */
    public array $received = [];

    public function report(Report $report): void
    {
        $this->received[] = $report;
    }
}

class ExplodingReporter implements Reporter
{
    public function report(Report $report): void
    {
        throw new RuntimeException('reporting endpoint down');
    }
}

test('breadcrumbs record a typed journey and cap their length', function () {
    $crumbs = new Breadcrumbs(3);

    $crumbs->add('GET /checkout', Breadcrumbs::TYPE_REQUEST);
    $crumbs->add('SELECT * FROM carts', Breadcrumbs::TYPE_QUERY, ['ms' => 4]);
    $crumbs->add('applied coupon', Breadcrumbs::TYPE_ACTION);
    $crumbs->add('charged card', Breadcrumbs::TYPE_ACTION);

    $trail = $crumbs->trail();

    expect($trail)->toHaveCount(3); // oldest dropped
    expect($trail[0]['message'])->toBe('SELECT * FROM carts');
    expect($trail[0]['meta'])->toBe(['ms' => 4]);
    expect($trail[2]['type'])->toBe(Breadcrumbs::TYPE_ACTION);
    expect(strtotime($trail[0]['at']))->toBeGreaterThan(0);
});

test('captured crashes carry the journey and hub context', function () {
    $hub = new Hub();
    $hub->context([
        'appRoot' => dirname(__DIR__),
        'user' => ['id' => 7, 'email' => 'user@example.com'],
        'app' => ['env' => 'production'],
    ]);

    $hub->leaveCrumb('GET /checkout', Breadcrumbs::TYPE_REQUEST);
    $hub->leaveCrumb('charge attempted', Breadcrumbs::TYPE_ACTION, ['amount' => 4200]);

    $report = $hub->capture(new RuntimeException('charge failed'));

    expect($report->user['id'])->toBe(7);
    expect($report->app['env'])->toBe('production');
    expect($report->breadcrumbs)->toHaveCount(2);
    expect($report->breadcrumbs[1]['meta'])->toBe(['amount' => 4200]);
    expect($report->level)->toBe('error');
});

test('manual checkpoints capture without an exception and without blocking', function () {
    $hub = new Hub();
    $hub->context(['appRoot' => dirname(__DIR__)]);
    $reporter = new CollectingReporter();
    $hub->reportTo($reporter);

    $hub->leaveCrumb('order total calculated', Breadcrumbs::TYPE_ACTION, ['total' => 0]);
    $report = $hub->capture('order total is 0 but cart has items', ['level' => 'warning']);

    expect($report->exception)->toBe('message');
    expect($report->level)->toBe('warning');
    expect($report->frames[0]->file)->toBe(__FILE__); // stack starts at the caller
    expect($report->breadcrumbs[0]['meta'])->toBe(['total' => 0]);

    // buffered — not delivered yet, the app is never blocked
    expect($reporter->received)->toBeEmpty();

    $hub->dispatcher()->flush();
    expect($reporter->received)->toHaveCount(1);
    expect($reporter->received[0]->id)->toBe($report->id);
});

test('immediate dispatch delivers synchronously when asked', function () {
    $hub = new Hub();
    $reporter = new CollectingReporter();
    $hub->reportTo($reporter);

    $hub->capture('checkpoint', ['immediately' => true]);

    expect($reporter->received)->toHaveCount(1);
});

test('a broken reporter never breaks the app or the other reporters', function () {
    $dispatcher = new Dispatcher();
    $working = new CollectingReporter();

    $dispatcher->reporter(new ExplodingReporter());
    $dispatcher->reporter($working);

    $dispatcher->dispatch(Report::message('test'), true);

    expect($working->received)->toHaveCount(1);
    expect($dispatcher->failures())->toHaveCount(1);
    expect($dispatcher->failures()[0]['reporter'])->toBe(ExplodingReporter::class);
});

test('message fingerprints group by capture site and message', function () {
    $a = Report::message('total is 0', ['appRoot' => dirname(__DIR__)]);
    $b = Report::message('total is 0', ['appRoot' => dirname(__DIR__)]);
    $c = Report::message('something else', ['appRoot' => dirname(__DIR__)]);

    expect($a->fingerprint)->toBe($b->fingerprint);
    expect($c->fingerprint)->not->toBe($a->fingerprint);
});

test('manual captures redact context like crash reports do', function () {
    $report = Report::message('checkpoint', [
        'user' => ['api_token' => 'secret-token', 'name' => 'Michael'],
    ]);

    expect($report->user['api_token'])->toBe(Redactor::MASK);
    expect($report->user['name'])->toBe('Michael');
});

test('the ai prompt combines project context, journey and crash', function () {
    $hub = new Hub();
    $hub->context(['appRoot' => dirname(__DIR__)]);
    $hub->leaveCrumb('POST /checkout', Breadcrumbs::TYPE_REQUEST);

    $report = $hub->capture(new RuntimeException('charge failed'));

    $prompt = Ai::prompt($report, "# My Shop\nLeaf MVC app selling plants.");

    expect($prompt)
        ->toContain('debugging an error in a Leaf PHP application')
        ->toContain('## Project context')
        ->toContain('Leaf MVC app selling plants')
        ->toContain('# RuntimeException')
        ->toContain('[request] POST /checkout')
        ->toContain('root cause');
});

test('ai links open assistants with the prompt prefilled', function () {
    $prompt = 'why did this break?';

    expect(Ai::claudeUrl($prompt))->toBe('https://claude.ai/new?q=why%20did%20this%20break%3F');
    expect(Ai::chatgptUrl($prompt))->toStartWith('https://chatgpt.com/?q=');
});

test('project context is read from .leaf/CONTEXT.md when present', function () {
    $root = sys_get_temp_dir() . '/crash-ctx-' . uniqid();
    mkdir("$root/.leaf", 0777, true);
    file_put_contents("$root/.leaf/CONTEXT.md", '# Context file');

    expect(Ai::projectContext($root))->toBe('# Context file');
    expect(Ai::projectContext(sys_get_temp_dir()))->toBeNull();

    unlink("$root/.leaf/CONTEXT.md");
    rmdir("$root/.leaf");
    rmdir($root);
});

test('breadcrumbs record where in the app they were left', function () {
    $crumbs = new Breadcrumbs();
    $crumbs->add('checkout started');

    $origin = $crumbs->trail()[0]['origin'];

    expect($origin['file'])->toBe(__FILE__);
    expect($origin['line'])->toBeGreaterThan(0);
    expect($origin['callable'])->toContain('{closure'); // the enclosing test closure
});

test('crumbs left through the hub point at the caller, not the hub', function () {
    $hub = new Hub();
    $hub->leaveCrumb('via hub');

    expect($hub->breadcrumbs()->trail()[0]['origin']['file'])->toBe(__FILE__);
});

class ArrayOccurrenceStore implements \Leaf\Crash\OccurrenceStore
{
    public array $counts = [];

    public function record(\Leaf\Crash\Report $report): int
    {
        return $this->counts[$report->fingerprint] = ($this->counts[$report->fingerprint] ?? 0) + 1;
    }
}

test('peeks export bounded, redacted snapshots of variables', function () {
    $deep = ['a' => ['b' => ['c' => ['d' => 'too deep']]]];

    $report = Report::message('checkpoint', [
        'peeks' => [
            'order' => ['total' => 0, 'items' => ['monstera'], 'api_key' => 'secret'],
            'deep' => $deep,
            'closure' => fn () => 1,
            'longText' => str_repeat('x', 900),
        ],
    ]);

    expect($report->peeks['order']['total'])->toBe(0);
    expect($report->peeks['order']['api_key'])->toBe(Redactor::MASK);
    // deep-but-small structures survive intact under the node budget
    expect($report->peeks['deep']['a']['b']['c'])->toBe(['d' => 'too deep']);
    expect($report->peeks['closure'])->toBe('(closure)');
    expect($report->peeks['longText'])->toContain('(900 chars)');

    // ...but a huge structure exhausts the budget and truncates
    $huge = \Leaf\Crash\Peek::export(array_fill(0, 25, array_fill(0, 25, array_fill(0, 25, 'x'))));
    expect(json_encode($huge))->toContain('(truncated)');
});

test('timing spans are cheap to record and ride on captures', function () {
    $hub = new Hub();

    $result = $hub->span('warmup', fn () => 'ready');
    expect($result)->toBe('ready');

    $hub->timing()->start('db');
    usleep(2000);
    $hub->timing()->stop('db');

    $report = $hub->capture('checkpoint');

    expect($report->timings)->toHaveCount(2);
    expect($report->timings[0]['name'])->toBe('warmup');
    expect($report->timings[1]['ms'])->toBeGreaterThan(1);
});

test('the full caused-by chain is captured with excerpts', function () {
    $inner = new PDOException('connection refused');
    $middle = new RuntimeException('query failed', 0, $inner);
    $outer = new LogicException('checkout broke', 0, $middle);

    $report = Report::from($outer, ['appRoot' => dirname(__DIR__)]);

    expect($report->chain)->toHaveCount(2);
    expect($report->chain[0]['exception'])->toBe(RuntimeException::class);
    expect($report->chain[1]['exception'])->toBe(PDOException::class);
    expect($report->chain[0]['excerpt'])->not->toBeEmpty();
    // legacy shape still points at the nearest cause
    expect($report->previous['exception'])->toBe(RuntimeException::class);
});

test('an occurrence store stamps seen-counts onto reports', function () {
    $hub = new Hub();
    $hub->context(['appRoot' => dirname(__DIR__)]);
    $hub->countWith($store = new ArrayOccurrenceStore());

    $first = $hub->capture(new RuntimeException('same crash'));
    $second = $hub->capture(new RuntimeException('same crash'));

    expect($first->occurrences)->toBe(1);
    expect($second->occurrences)->toBe(2);
});

test('a broken occurrence store never breaks capture', function () {
    $hub = new Hub();
    $hub->countWith(new class () implements \Leaf\Crash\OccurrenceStore {
        public function record(\Leaf\Crash\Report $report): int
        {
            throw new RuntimeException('store down');
        }
    });

    $report = $hub->capture('checkpoint');

    expect($report->occurrences)->toBeNull();
});

test('captures automatically carry cheap runtime facts', function () {
    $report = (new Hub())->capture('checkpoint');

    expect($report->app['php'])->toBe(PHP_VERSION);
    expect($report->app['memory'])->toContain('MB');
});

test('the crash() global returns one shared hub', function () {
    expect(crash())->toBeInstanceOf(Hub::class);
    expect(crash())->toBe(crash());

    crash()->leaveCrumb('via global');
    expect(crash()->breadcrumbs()->trail())->not->toBeEmpty();
});

test('large crumb data is bounded at record time and redacted in reports', function () {
    $crumbs = new Breadcrumbs();

    // a "model result": deep, wide, with a secret inside
    $rows = array_fill(0, 50, ['id' => 1, 'nested' => ['deep' => ['deeper' => ['too-deep' => 1]]]]);
    $crumbs->add('loaded orders', Breadcrumbs::TYPE_QUERY, [
        'rows' => $rows,
        'api_token' => 'secret-token',
    ]);

    $meta = $crumbs->trail()[0]['meta'];

    // bounded: item cap applied (25 + the "more" marker)
    expect(count($meta['rows']))->toBeLessThanOrEqual(26);
    // deep-but-small data survives for the tree viewer
    expect($meta['rows'][0]['nested']['deep']['deeper']['too-deep'])->toBe(1);

    // redaction applies when the trail lands on a report
    $report = Report::message('checkpoint', ['breadcrumbs' => $crumbs->trail()]);
    expect($report->breadcrumbs[0]['meta']['api_token'])->toBe(Redactor::MASK);
});
