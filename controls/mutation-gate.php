<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Mutation-score gate (04-qa-and-testing.md §14, devops3 A2).
 *
 * Resolves the reviewed minimum mutation score for a library from
 * mutation-baseline.json. Floors stay at zero while nightly baselines
 * accumulate (report-only); promoting a library to enforcement means setting
 * its floor here and dropping continue-on-error on the CI job. The score is
 * enforced natively by `pest --mutate --min=<floor>` (cmd/dev/mutate).
 *
 * Usage:
 *   php controls/mutation-gate.php --library=bagart/async-kernel [--format=text|json]
 *
 * Exit codes: 0 ok, 1 no entry configured, 2 usage error.
 */

const EXIT_OK = 0;
const EXIT_NO_ENTRY = 1;
const EXIT_USAGE = 2;

/**
 * @param  array<string, mixed> $baseline decoded mutation-baseline.json
 *
 * @return array{library: string, min_score: float, enforced: bool}
 */
function mutation_gate_resolve(array $baseline, string $library): array
{
    $floors = $baseline['libraries'][$library] ?? [];
    $min = (float) ($floors['msi_floor'] ?? 0);

    return ['library' => $library, 'min_score' => $min, 'enforced' => $min > 0];
}

function mutation_gate_load_json(string $path): array
{
    $decoded = json_decode((string) @file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

// CLI entry point — skipped when included for its functions (tests).
if (realpath($argv[0] ?? '') !== __FILE__) {
    return;
}

$format = 'text';
$library = null;
$baselineFile = dirname(__DIR__).'/baseline/mutation-baseline.json';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--format=json' || $arg === '--json') {
        $format = 'json';

        continue;
    }
    if (preg_match('/^--library=(.+)$/', $arg, $m) === 1) {
        $library = $m[1];

        continue;
    }
    if (preg_match('/^--baseline=(.+)$/', $arg, $m) === 1) {
        $baselineFile = $m[1];

        continue;
    }
    if (in_array($arg, ['--format=text', '--help', '-h'], true)) {
        continue;
    }
    fwrite(STDERR, sprintf('unknown argument: %s%s', $arg, PHP_EOL));

    exit(EXIT_USAGE);
}

if ($library === null) {
    fwrite(STDERR, '--library is required'.PHP_EOL);

    exit(EXIT_USAGE);
}

$baseline = mutation_gate_load_json($baselineFile);
if (! isset($baseline['libraries'][$library])) {
    fwrite(STDERR, sprintf('no floors configured for %s in %s%s', $library, $baselineFile, PHP_EOL));

    exit(EXIT_NO_ENTRY);
}

$result = mutation_gate_resolve($baseline, $library);
if ($format === 'json') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    printf('%d%s', (int) $result['min_score'], PHP_EOL);
}

exit(EXIT_OK);
