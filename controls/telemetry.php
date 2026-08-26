<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Baseline operational telemetry (11-implementation-and-rollout.md §64).
 *
 * Aggregates the NDJSON event log the control engine writes per run
 * (.cache/baseline/telemetry.jsonl, enabled with BASELINE_TELEMETRY=1):
 * check durations, failure frequency per control and last-seen failures.
 *
 * Usage:
 *   php controls/telemetry.php --summary [--format=text|json]
 *       [--events=.cache/baseline/telemetry.jsonl] [--limit=20]
 *
 * Exit codes: 0 ok, 2 usage error.
 */

const EXIT_OK = 0;
const EXIT_USAGE = 2;

/**
 * @param  list<string> $lines raw NDJSON lines
 *
 * @return array<string, array{runs: int, failures: int, skipped: int, avg_ms: float, max_ms: int, last_failure: ?string}>
 */
function telemetry_aggregate(array $lines): array
{
    $stats = [];
    foreach ($lines as $line) {
        $event = json_decode(trim($line), true);
        if (! is_array($event) || ! isset($event['control'], $event['status'])) {
            continue;
        }
        $control = (string) $event['control'];
        $entry = $stats[$control] ?? ['runs' => 0, 'failures' => 0, 'skipped' => 0, 'total_ms' => 0, 'max_ms' => 0, 'last_failure' => null];
        $entry['runs']++;
        if ($event['status'] === 'failed') {
            $entry['failures']++;
            $entry['last_failure'] = isset($event['ts']) ? (string) $event['ts'] : null;
        } elseif ($event['status'] === 'skipped') {
            $entry['skipped']++;
        }
        $duration = isset($event['duration_ms']) && is_numeric($event['duration_ms']) ? (int) $event['duration_ms'] : 0;
        $entry['total_ms'] += $duration;
        $entry['max_ms'] = max($entry['max_ms'], $duration);
        $stats[$control] = $entry;
    }

    return array_map(static function (array $e): array {
        $avg = $e['runs'] > 0 ? round($e['total_ms'] / $e['runs'], 1) : 0.0;
        unset($e['total_ms']);

        return [
            'runs' => $e['runs'],
            'failures' => $e['failures'],
            'skipped' => $e['skipped'],
            'failure_rate' => $e['runs'] > 0 ? round($e['failures'] / $e['runs'], 3) : 0.0,
            'avg_ms' => $avg,
            'max_ms' => $e['max_ms'],
            'last_failure' => $e['last_failure'],
        ];
    }, $stats);
}

/**
 * @return list<string>
 */
function telemetry_read_events(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    return file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
}

// CLI entry point — skipped when included for its functions (tests).
if (realpath($argv[0] ?? '') !== __FILE__) {
    return;
}

$summary = false;
$format = 'text';
$limit = 20;
$consumerRoot = baseline_consumer_root() ?? dirname(__DIR__, 2);
$events = $consumerRoot.'/.cache/baseline/telemetry.jsonl';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--summary') {
        $summary = true;

        continue;
    }
    if ($arg === '--format=json' || $arg === '--json') {
        $format = 'json';

        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m) === 1) {
        $limit = max(1, (int) $m[1]);

        continue;
    }
    if (preg_match('/^--events=(.+)$/', $arg, $m) === 1) {
        $events = $m[2];

        continue;
    }
    if (in_array($arg, ['--format=text', '--help', '-h'], true)) {
        continue;
    }
    fwrite(STDERR, sprintf('unknown argument: %s%s', $arg, PHP_EOL));

    exit(EXIT_USAGE);
}

if (! $summary) {
    fwrite(STDERR, 'usage: telemetry.php --summary [--format=text|json] [--events=file] [--limit=N]'.PHP_EOL);

    exit(EXIT_USAGE);
}

$stats = telemetry_aggregate(telemetry_read_events($events));
uasort($stats, static fn (array $a, array $b): int => $b['failures'] <=> $a['failures'] ?: strcmp(array_search($a, $stats, true), array_search($b, $stats, true)));
$top = array_slice($stats, 0, $limit, true);

if ($format === 'json') {
    echo json_encode(['events_file' => $events, 'controls' => count($stats), 'entries' => $top], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

    exit(EXIT_OK);
}

if ($stats === []) {
    echo "no telemetry events recorded yet (enable with BASELINE_TELEMETRY=1)\n";

    exit(EXIT_OK);
}

printf('%-28s %6s %8s %7s %9s %9s  %s%s', 'control', 'runs', 'failures', 'skip', 'avg_ms', 'max_ms', 'last_failure', PHP_EOL);
foreach ($top as $control => $s) {
    printf(
        '%-28s %6d %8d %7d %9.1f %9d  %s%s',
        $control,
        $s['runs'],
        $s['failures'],
        $s['skipped'],
        $s['avg_ms'],
        $s['max_ms'],
        $s['last_failure'] ?? '—',
        PHP_EOL,
    );
}
