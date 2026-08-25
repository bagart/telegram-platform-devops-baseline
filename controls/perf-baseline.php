<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Performance baseline harness (08 §49–§56, 09 §55–§63, 11 §36–§37).
 *
 * Runs a deterministic micro-benchmark suite, stores per-host reference
 * values in perf-baselines.json and compares fresh runs against them.
 * Throughput metrics are "higher is better"; memory deltas are "lower is
 * better". Baselines are host-specific by design — CI comparison is opt-in
 * via BASELINE_BENCH_COMPARE=1 on a stable runner.
 *
 * Usage:
 *   php controls/perf-baseline.php run                 JSON to stdout
 *   php controls/perf-baseline.php record              merge into baselines file
 *   php controls/perf-baseline.php compare [--pct=20]  exit 1 on regression
 *   php controls/perf-baseline.php soak [--rounds=5]   memory-drift leak check
 */

const EXIT_OK = 0;
const EXIT_CHECK = 1;
const EXIT_USAGE = 2;

$baselinesFile = __DIR__.'/perf-baselines.json';

$mode = $argv[1] ?? 'run';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z]+)=(.+)$/', (string) $arg, $m)) {
        $options[$m[1]] = $m[2];
    }
}

/** Round-stable micro benchmarks. Values are relative, per-host references. */
function measure(): array
{
    $payload = json_encode([
        'update_id' => 123456789, 'message' => ['chat' => ['id' => 42], 'text' => str_repeat('x', 256)],
    ]);
    $rounds = 20000;
    $t = hrtime(true);
    for ($i = 0; $i < $rounds; $i++) {
        $decoded = json_decode((string) $payload, true);
        $encoded = json_encode($decoded);
    }
    $jsonOps = (int) ($rounds / max(1e-9, (hrtime(true) - $t) / 1e9));

    $data = random_bytes(1024 * 512);
    $t = hrtime(true);
    $bytes = 0;
    for ($i = 0; $i < 300; $i++) {
        $data = hash('sha256', $data, true);
        $bytes += 512 * 1024;
    }
    $shaMbps = (int) ($bytes / (1024 * 1024) / max(1e-9, (hrtime(true) - $t) / 1e9));

    $t = hrtime(true);
    $acc = '';
    for ($i = 0; $i < 50000; $i++) {
        $acc .= 'x';
    }
    $concatOps = (int) (50000 / max(1e-9, (hrtime(true) - $t) / 1e9));
    strlen($acc) === 50000 || throw new RuntimeException('concat sanity');

    $t = hrtime(true);
    $arr = range(1, 1000);
    for ($i = 0; $i < 500; $i++) {
        $arr = array_map(static fn ($v) => $v * 2 + 1, $arr);
    }
    $mapOps = (int) (500 * 1000 / max(1e-9, (hrtime(true) - $t) / 1e9));

    // Memory drift over repeated allocations+free: expected ~0 on healthy runtime.
    gc_collect_cycles();
    $memStart = memory_get_usage(true);
    for ($i = 0; $i < 50; $i++) {
        $tmp = str_repeat('a', 64 * 1024);
        strlen($tmp);
        unset($tmp);
    }
    gc_collect_cycles();
    $memDelta = memory_get_usage(true) - $memStart;

    return [
        'json_roundtrip_ops_per_s' => ['value' => $jsonOps, 'direction' => 'higher'],
        'sha256_mb_per_s' => ['value' => $shaMbps, 'direction' => 'higher'],
        'string_concat_ops_per_s' => ['value' => $concatOps, 'direction' => 'higher'],
        'array_map_ops_per_s' => ['value' => $mapOps, 'direction' => 'higher'],
        'memory_drift_bytes' => ['value' => max(0, $memDelta), 'direction' => 'lower'],
    ];
}

switch ($mode) {
    case 'run':
        echo json_encode(['metrics' => measure(), 'php' => PHP_VERSION, 'recorded_at' => date('c')], JSON_PRETTY_PRINT), "\n";
        exit(EXIT_OK);

    case 'record':
        $fresh = measure();
        $stored = is_file($baselinesFile)
            ? (json_decode((string) file_get_contents($baselinesFile), true) ?: [])
            : [];
        foreach ($fresh as $name => $entry) {
            $stored[$name] = [
                'value' => $entry['value'],
                'direction' => $entry['direction'],
                'php' => PHP_VERSION,
                'recorded_at' => date('c'),
            ];
        }
        file_put_contents($baselinesFile, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        printf("recorded %d baseline(s) into %s\n", count($fresh), basename($baselinesFile));
        exit(EXIT_OK);

    case 'soak':
        $rounds = max(2, (int) ($options['rounds'] ?? 5));
        $drifts = [];
        for ($r = 0; $r < $rounds; $r++) {
            $m = measure();
            $drifts[] = $m['memory_drift_bytes']['value'];
            printf("round %d/%d memory_drift=%d bytes\n", $r + 1, $rounds, $m['memory_drift_bytes']['value']);
        }
        $totalDrift = array_sum($drifts);
        $threshold = (int) ($options['max-bytes'] ?? 8 * 1024 * 1024);
        if ($totalDrift > $threshold) {
            printf("SOAK FAIL: cumulative drift %d bytes > %d\n", $totalDrift, $threshold);
            exit(EXIT_CHECK);
        }
        printf("SOAK OK: cumulative drift %d bytes <= %d\n", $totalDrift, $threshold);
        exit(EXIT_OK);

    case 'compare':
        if (! is_file($baselinesFile)) {
            fwrite(STDERR, "No baselines recorded yet — run 'record' first.\n");
            exit(EXIT_CHECK);
        }
        $stored = json_decode((string) file_get_contents($baselinesFile), true);
        if (! is_array($stored) || $stored === []) {
            fwrite(STDERR, "Baselines file malformed.\n");
            exit(EXIT_CHECK);
        }
        $pct = min(95, max(1, (int) ($options['pct'] ?? (int) (getenv('BASELINE_REGRESSION_PCT') ?: 20))));
        $fresh = measure();
        $regressions = 0;
        foreach ($fresh as $name => $entry) {
            if (! isset($stored[$name]['value'])) {
                continue;
            }
            $base = (float) $stored[$name]['value'];
            $now = (float) $entry['value'];
            $worse = $stored[$name]['direction'] === 'higher' ? $now < $base : $now > $base;
            $deltaPct = $base != 0.0 ? abs($now - $base) / $base * 100 : 0.0;
            $flag = $worse && $deltaPct >= $pct;
            printf(
                "%-28s base=%-10.0f now=%-10.0f delta=%+6.1f%%%s\n",
                $name,
                $base,
                $now,
                ($stored[$name]['direction'] === 'higher' ? -1 : 1) * ($now - $base) / max(1e-9, $base) * 100,
                $flag ? '  REGRESSION' : ''
            );
            if ($flag) {
                $regressions++;
            }
        }
        if ($regressions > 0) {
            fwrite(STDERR, "PERF REGRESSION: {$regressions} metric(s) degraded beyond {$pct}%\n");
            exit(EXIT_CHECK);
        }
        echo "no regressions beyond {$pct}%\n";
        exit(EXIT_OK);

    default:
        fwrite(STDERR, "Usage: perf-baseline.php run|record|compare|soak\n");
        exit(EXIT_USAGE);
}
