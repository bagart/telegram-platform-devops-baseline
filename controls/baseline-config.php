<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Central baseline configuration (11-implementation-and-rollout.md §12–§13).
 *
 * Single place that composes the effective policy: detected profiles, pinned
 * tool versions, thresholds, budgets and check-level behavior, read from the
 * individual reviewed policy files. Repositories may override a limited set
 * of tunable parameters via tools/baseline/config-overrides.json — overrides
 * are deep-merged but restricted to the allowlist below; security-critical
 * controls (scanners, exception rules) can never be disabled here.
 *
 * Nothing in this file is magic: every input is a visible file in the repo
 * (§13 no hidden configuration).
 *
 * Usage:
 *   php controls/baseline-config.php --show [--format=text|json]
 *   php controls/baseline-config.php --validate
 *
 * Exit codes: 0 ok, 1 invalid override, 2 usage error.
 */

const EXIT_OK = 0;
const EXIT_CHECK = 1;
const EXIT_USAGE = 2;

const OVERRIDABLE_KEYS = [
    'thresholds.coverage_min',
    'thresholds.mutation.msi_floor',
    'thresholds.mutation.covered_code_msi_floor',
    'budgets.test_suites',
    'levels.quick.controls',
    'levels.full.controls',
];

/**
 * Recursive merge: scalar/list values from $overrides win, nested maps merge.
 */
function baseline_config_merge(array $base, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)) {
            $base[$key] = baseline_config_merge($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }

    return $base;
}

/**
 * @return list<string> keys from $overrides not present in the allowlist
 */
function baseline_config_forbidden(array $overrides, string $prefix = ''): array
{
    $forbidden = [];
    foreach ($overrides as $key => $value) {
        $path = $prefix === '' ? (string) $key : "$prefix.$key";
        // A whitelisted branch is accepted wholesale (e.g. budgets.test_suites
        // may add or retune per-suite entries).
        if (in_array($path, OVERRIDABLE_KEYS, true)) {
            continue;
        }
        if (is_array($value) && ! array_is_list($value)) {
            $forbidden = [...$forbidden, ...baseline_config_forbidden($value, $path)];

            continue;
        }
        if (! in_array($path, OVERRIDABLE_KEYS, true)) {
            $forbidden[] = $path;
        }
    }

    return $forbidden;
}

function baseline_config_get(array $config, string $path): mixed
{
    $node = $config;
    foreach (explode('.', $path) as $segment) {
        if (! is_array($node) || ! array_key_exists($segment, $node)) {
            return null;
        }
        $node = $node[$segment];
    }

    return $node;
}

/**
 * Composes the effective configuration from the visible policy files.
 *
 * @param  array{profiles: list<string>, toolVersions: array<string, mixed>, testBudgets: array<string, int>, mutationFloors: array<string, mixed>, coverageMin: ?string, overrides: array<string, mixed>} $input
 */
function baseline_config_compose(array $input): array
{
    $floors = $input['mutationFloors']['libraries']['bagart/async-kernel'] ?? [];

    $effective = [
        'profiles' => [
            'detected' => $input['profiles'],
            'composition' => 'telegram implies async-runtime + laravel (10 §6)',
        ],
        'tools' => $input['toolVersions'],
        'thresholds' => [
            'coverage_min' => $input['coverageMin'] !== null ? (float) $input['coverageMin'] : null,
            'mutation' => [
                'library' => 'bagart/async-kernel',
                'msi_floor' => (float) ($floors['msi_floor'] ?? 0),
                'covered_code_msi_floor' => (float) ($floors['covered_code_msi_floor'] ?? 0),
                'enforced' => (float) ($floors['msi_floor'] ?? 0) > 0 || (float) ($floors['covered_code_msi_floor'] ?? 0) > 0,
            ],
        ],
        'budgets' => [
            'test_suites' => $input['testBudgets'],
            'enforce' => 'BASELINE_TEST_BUDGETS=enforce',
        ],
        'levels' => [
            'quick' => ['controls' => ['line-endings', 'secret-scan']],
            'default' => ['controls' => ['changed-surface selection', 'targeted lib suites when profile active']],
            'full' => ['controls' => ['pint', 'phpstan', 'eslint', 'prettier', 'typescript', 'compat-matrix', 'tests']],
            'ci' => ['extends' => 'full', 'extra' => ['composer-audit', 'npm-audit']],
        ],
    ];

    return baseline_config_merge($effective, $input['overrides']);
}

/**
 * Gathers composition inputs for the repository at $root.
 *
 * @return array{profiles: list<string>, toolVersions: array<string, mixed>, testBudgets: array<string, int>, mutationFloors: array<string, mixed>, coverageMin: ?string, overrides: array<string, mixed>}
 */
function baseline_config_collect(string $root): array
{
    $json = static function (string $path): array {
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    };

    exec('bash '.escapeshellarg($root.'/tools/baseline/profiles.sh').' 2>/dev/null', $out, $code);
    $profiles = $code === 0 && isset($out[0]) ? explode(' ', trim((string) $out[0])) : [];

    return [
        'profiles' => array_values(array_filter($profiles)),
        'toolVersions' => $json($root.'/tools/baseline/tool-versions.json'),
        'testBudgets' => $json($root.'/tools/baseline/test-budgets.json'),
        'mutationFloors' => $json($root.'/tools/baseline/mutation-baseline.json'),
        'coverageMin' => getenv('BASELINE_COVERAGE_MIN') ?: null,
        'overrides' => $json($root.'/tools/baseline/config-overrides.json'),
    ];
}

// CLI entry point — skipped when included for its functions (tests).
if (realpath($argv[0] ?? '') !== __FILE__) {
    return;
}

$mode = null;
$format = 'text';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--show') {
        $mode = 'show';

        continue;
    }
    if ($arg === '--validate') {
        $mode = 'validate';

        continue;
    }
    if ($arg === '--format=json' || $arg === '--json') {
        $format = 'json';

        continue;
    }
    if (in_array($arg, ['--format=text', '--help', '-h'], true)) {
        continue;
    }
    fwrite(STDERR, sprintf('unknown argument: %s%s', $arg, PHP_EOL));

    exit(EXIT_USAGE);
}

if ($mode === null) {
    fwrite(STDERR, 'usage: baseline-config.php (--show|--validate) [--format=text|json]'.PHP_EOL);

    exit(EXIT_USAGE);
}

$input = baseline_config_collect(baseline_consumer_root() ?? dirname(__DIR__, 2));
$forbidden = baseline_config_forbidden($input['overrides']);

if ($mode === 'validate') {
    if ($forbidden !== []) {
        printf(
            'invalid overrides (not overridable): %s%sAllowed keys: %s%s',
            implode(', ', $forbidden),
            PHP_EOL,
            implode(', ', OVERRIDABLE_KEYS),
            PHP_EOL,
        );

        exit(EXIT_CHECK);
    }
    echo "overrides valid\n";

    exit(EXIT_OK);
}

$config = baseline_config_compose($input);
if ($format === 'json') {
    echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    echo "profiles:      ".implode(', ', $config['profiles']['detected'])."\n";
    echo "tools:         ".count($config['tools'])." entries (tool-versions.json)\n";
    printf("coverage_min:  %s\n", $config['thresholds']['coverage_min'] === null ? '(unset — nightly report-only)' : $config['thresholds']['coverage_min']);
    printf("mutation msi:  %.1f / covered %.1f (%s)\n", $config['thresholds']['mutation']['msi_floor'], $config['thresholds']['mutation']['covered_code_msi_floor'], $config['thresholds']['mutation']['enforced'] ? 'enforced' : 'report-only');
    echo "test budgets:  ".json_encode($config['budgets']['test_suites'])."\n";
    echo "levels:        quick=".implode('+', $config['levels']['quick']['controls'])."; ci=full+".implode('+', $config['levels']['ci']['extra'])."\n";
    echo "overrides:     ".($config === [] ? 'none' : implode(', ', $forbidden === [] ? array_keys($input['overrides']) : $forbidden))."\n";
}

exit(EXIT_OK);
