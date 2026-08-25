<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Per-suite test time budgets (04-qa-and-testing.md §23).
 *
 * Reads a JUnit XML log produced by the test runner and compares each
 * suite's wall time against tools/baseline/test-budgets.json. Report-only
 * by default; set BASELINE_TEST_BUDGETS=enforce to fail on budget overrun.
 *
 * Usage:
 *   php controls/test-budgets.php <junit.xml> [--format=text|json]
 *
 * Exit codes: 0 within budgets (or warnings), 1 enforce-mode overrun,
 * 2 usage/parse error.
 */

const EXIT_OK = 0;
const EXIT_CHECK = 1;
const EXIT_USAGE = 2;

$file = $argv[1] ?? '';
$jsonRequested = in_array('--format=json', $argv, true);
if ($file === '' || ! is_file($file)) {
    fwrite(STDERR, "Usage: test-budgets.php <junit.xml> [--format=json]\n");
    exit(EXIT_USAGE);
}

$xml = @simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "JUnit XML unreadable: {$file}\n");
    exit(EXIT_USAGE);
}

$budgetsFile = __DIR__.'/test-budgets.json';
$budgets = is_file($budgetsFile)
    ? (json_decode((string) file_get_contents($budgetsFile), true) ?: [])
    : [];

$suites = [];
foreach ($xml->xpath('//testsuite') ?: [] as $suite) {
    $name = (string) ($suite['name'] ?? 'unknown');
    $time = (float) ($suite['time'] ?? 0);
    $suites[$name] = ($suites[$name] ?? 0) + $time;
}
arsort($suites);

$enforce = (getenv('BASELINE_TEST_BUDGETS') ?: '') === 'enforce';
$rows = [];
$overruns = 0;
foreach ($suites as $name => $time) {
    $budget = null;
    foreach ($budgets as $prefix => $limit) {
        if (str_starts_with($name, (string) $prefix)) {
            $budget = (float) $limit;
            break;
        }
    }
    if ($budget === null) {
        continue;
    }
    $status = $time <= $budget ? 'ok' : 'over';
    if ($status === 'over') {
        $overruns++;
    }
    $rows[] = ['suite' => $name, 'seconds' => round($time, 2), 'budget_seconds' => $budget, 'status' => $status];
}

if ($jsonRequested) {
    echo json_encode(['enforce' => $enforce, 'rows' => $rows], JSON_PRETTY_PRINT), "\n";
} else {
    foreach ($rows as $r) {
        printf(
            "%-55s %7.2fs / budget %5.1fs  [%s]\n",
            $r['suite'],
            $r['seconds'],
            $r['budget_seconds'],
            $r['status'] === 'ok' ? 'ok' : 'OVER BUDGET'
        );
    }
    if ($rows === []) {
        echo "no suite matched a configured budget\n";
    }
}

exit($enforce && $overruns > 0 ? EXIT_CHECK : EXIT_OK);
