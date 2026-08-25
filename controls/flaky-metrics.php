<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Flaky-test metrics from run history (04-qa-and-testing.md §24).
 *
 * Appends a per-run failure record to storage/app/baseline/test-history.jsonl
 * and reports the flaky rate over the retention window. A test is "flaky"
 * when it failed in some runs and passed in others within the window.
 *
 * Usage:
 *   php controls/flaky-metrics.php record <junit.xml>
 *   php controls/flaky-metrics.php report [--window=30] [--format=text|json]
 *
 * Exit codes: 0 success, 2 usage error.
 */

const EXIT_OK = 0;
const EXIT_USAGE = 2;

$root = dirname(__DIR__, 2);
$historyFile = $root.'/storage/app/baseline/test-history.jsonl';

$mode = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z]+)=(.+)$/', (string) $arg, $m)) {
        $options[$m[1]] = $m[2];
    } elseif ($arg !== '--format=json') {
        $positional[] = $arg;
    }
}

/** @return array<string,true> failing test ids in the junit file */
function junit_failures(string $file): array
{
    $xml = @simplexml_load_file($file);
    if ($xml === false) {
        return [];
    }
    $failed = [];
    foreach ($xml->xpath('//testcase[failure or error]') ?: [] as $case) {
        $failed[((string) $case['classname']).'::'.((string) $case['name'])] = true;
    }

    return $failed;
}

switch ($mode) {
    case 'record':
        $junit = $positional[0] ?? '';
        if ($junit === '' || ! is_file($junit)) {
            fwrite(STDERR, "Usage: flaky-metrics.php record <junit.xml>\n");
            exit(EXIT_USAGE);
        }
        $dir = dirname($historyFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $entry = [
            'date' => date('c'),
            'failures' => array_keys(junit_failures($junit)),
        ];
        file_put_contents($historyFile, json_encode($entry)."\n", FILE_APPEND);
        printf("recorded run with %d failure(s)\n", count($entry['failures']));
        exit(EXIT_OK);

    case 'report':
        $window = max(1, (int) ($options['window'] ?? 30));
        if (! is_file($historyFile)) {
            echo "no test history recorded yet\n";
            exit(EXIT_OK);
        }
        $lines = file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $recent = array_slice($lines, -$window);
        $runs = [];
        $seen = [];
        foreach ($recent as $line) {
            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                continue;
            }
            $runs[] = $entry;
            foreach ($entry['failures'] ?? [] as $test) {
                $seen[$test][] = count($runs) - 1;
            }
        }
        $flaky = [];
        foreach ($seen as $test => $runIdx) {
            // Failed at least once but not in every run of the window.
            if (count($runIdx) > 0 && count($runIdx) < count($runs)) {
                $flaky[$test] = count($runIdx);
            }
        }
        ksort($flaky);
        $distinctFailing = count($seen);
        $rate = $distinctFailing > 0 ? round(count($flaky) / $distinctFailing * 100, 1) : 0.0;

        if (isset($options['format']) && $options['format'] === 'json') {
            echo json_encode(['runs_in_window' => count($runs), 'flaky' => $flaky], JSON_PRETTY_PRINT), "\n";
        } else {
            printf("window: last %d recorded run(s)\n", count($runs));
            if ($flaky === []) {
                echo "no flaky tests detected\n";
            } else {
                foreach ($flaky as $test => $fails) {
                    printf("FLAKY %-90s failed %d/%d runs\n", $test, $fails, count($runs));
                }
                printf("flaky rate: %.1f%% of distinct failing tests\n", $rate);
            }
        }
        exit(EXIT_OK);

    default:
        fwrite(STDERR, "Usage: flaky-metrics.php record <junit.xml> | report [--window=30]\n");
        exit(EXIT_USAGE);
}
