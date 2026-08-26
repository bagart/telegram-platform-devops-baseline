<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Composer supply-chain policy (03-security-and-supply-chain.md §15, §16).
 *
 * plugins check — every enabled entry in composer.json config.allow-plugins
 * must be explicitly approved in composer-plugin-allowlist.json; a wildcard
 * entry is always a violation. Stale allowlist entries are warnings.
 *
 * scripts check — composer scripts are identified, content is scanned for
 * high-risk patterns (network fetch, shell invocation, decode pipes) and the
 * set is compared against composer-scripts-baseline.json. Added or modified
 * entries are violations requiring security review; removed entries are
 * warnings. Regenerate the baseline with --update after review.
 *
 * Usage:
 *   php controls/composer-policy.php --check=plugins|scripts|all
 *       [--composer=path] [--allowlist=path] [--baseline=path]
 *       [--format=text|json] [--update]
 *
 * Exit codes: 0 clean, 1 violation, 2 usage error.
 */

const EXIT_OK = 0;
const EXIT_CHECK = 1;
const EXIT_USAGE = 2;

const SCRIPT_DANGER_PATTERNS = [
    'network-fetch' => '/\b(curl|wget|nc|netcat)\b/i',
    'shell-invocation' => '/\b(bash|zsh)\b|\bsh\s+-c\b/i',
    'code-eval' => '/\b(eval|exec)\b/i',
    'decode-pipe' => '/base64\s+(-d|--decode)|\|\s*sh\b|\|\s*bash\b/i',
    'powershell-download' => '/Invoke-(Expression|WebRequest)|\biex\b/i',
    'remote-url' => '/https?:\/\//i',
];

/**
 * @param array<string, bool> $enabled
 * @param array<string, string> $approved
 *
 * @return array<int, array{severity: string, message: string}>
 */
function composer_policy_plugins(array $enabled, array $approved): array
{
    $results = [];

    if (array_key_exists('*', $enabled)) {
        $results[] = ['severity' => 'violation', 'message' => 'allow-plugins contains a wildcard "*" entry'];
    }

    foreach ($enabled as $name => $allowed) {
        if ($name === '*') {
            continue;
        }
        if ($allowed === true && ! array_key_exists($name, $approved)) {
            $results[] = ['severity' => 'violation', 'message' => sprintf(
                'unapproved composer plugin enabled: %s (add to composer-plugin-allowlist.json after review)',
                $name
            )];
        } elseif ($allowed === false && array_key_exists($name, $approved)) {
            $results[] = ['severity' => 'warning', 'message' => sprintf(
                'plugin %s is disabled in composer.json but still allowlisted (stale entry)',
                $name
            )];
        }
    }

    foreach (array_keys($approved) as $name) {
        if (! array_key_exists($name, $enabled)) {
            $results[] = ['severity' => 'warning', 'message' => sprintf(
                'allowlisted plugin %s is not present in composer.json (stale entry)',
                $name
            )];
        }
    }

    return $results;
}

/**
 * @param array<string, array<int, string>> $scripts
 * @param array<string, array<int, string>> $baseline
 *
 * @return array<int, array{severity: string, message: string}>
 */
function composer_policy_scripts(array $scripts, array $baseline): array
{
    $results = [];

    foreach ($scripts as $event => $commands) {
        foreach ($commands as $command) {
            foreach (SCRIPT_DANGER_PATTERNS as $rule => $pattern) {
                if (preg_match($pattern, $command) === 1) {
                    $results[] = ['severity' => 'violation', 'message' => sprintf(
                        'composer script %s matches dangerous pattern "%s": %s',
                        $event,
                        $rule,
                        $command
                    )];
                }
            }
        }

        if (! array_key_exists($event, $baseline)) {
            $results[] = ['severity' => 'violation', 'message' => sprintf(
                'new composer script "%s" is not in the reviewed baseline (review, then run with --update)',
                $event
            )];

            continue;
        }

        if ($baseline[$event] !== $commands) {
            $results[] = ['severity' => 'violation', 'message' => sprintf(
                'composer script "%s" differs from the reviewed baseline (review, then run with --update)',
                $event
            )];
        }
    }

    foreach (array_keys($baseline) as $event) {
        if (! array_key_exists($event, $scripts)) {
            $results[] = ['severity' => 'warning', 'message' => sprintf(
                'baselined composer script "%s" no longer exists (stale entry, run with --update)',
                $event
            )];
        }
    }

    return $results;
}

/**
 * @return array<string, mixed>
 */
function composer_policy_load_json(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, sprintf('unable to read %s%s', $path, PHP_EOL));

        exit(EXIT_USAGE);
    }

    $data = json_decode($raw, true);
    if (! is_array($data)) {
        fwrite(STDERR, sprintf('invalid JSON in %s%s', $path, PHP_EOL));

        exit(EXIT_USAGE);
    }

    return $data;
}

/**
 * @return array{composer: string, allowlist: string, baseline: string, check: string, format: string, update: bool}
 */
function composer_policy_parse_args(array $argv): array
{
    $options = [
        'composer' => getcwd().'/composer.json',
        'allowlist' => baseline_policy_path('composer-plugin-allowlist.json'),
        'baseline' => baseline_policy_path('composer-scripts-baseline.json'),
        'check' => 'all',
        'format' => 'text',
        'update' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--update') {
            $options['update'] = true;

            continue;
        }
        if (preg_match('/^--(composer|allowlist|baseline|check|format)=(.+)$/', $arg, $m) === 1 && array_key_exists($m[1], $options)) {
            $options[$m[1]] = $m[2];

            continue;
        }
        if (in_array($arg, ['--help', '-h'], true)) {
            composer_policy_usage();
        }

        composer_policy_usage(sprintf('unknown argument: %s', $arg));
    }

    if (! in_array($options['check'], ['plugins', 'scripts', 'all'], true)
        || ! in_array($options['format'], ['text', 'json'], true)) {
        composer_policy_usage('invalid --check or --format value');
    }

    return $options;
}

/**
 * @never-return
 */
function composer_policy_usage(?string $error = null): void
{
    if ($error !== null) {
        fwrite(STDERR, $error.PHP_EOL);
    }
    fwrite(STDERR, 'usage: composer-policy.php --check=plugins|scripts|all [--composer=path] [--allowlist=path] [--baseline=path] [--format=text|json] [--update]'.PHP_EOL);

    exit(EXIT_USAGE);
}

$options = composer_policy_parse_args($argv);
$results = [];

if ($options['check'] !== 'scripts') {
    $composer = composer_policy_load_json($options['composer']);
    $enabled = $composer['config']['allow-plugins'] ?? [];
    if (! is_array($enabled)) {
        $enabled = [];
    }
    $approvedData = composer_policy_load_json($options['allowlist']);
    $approved = $approvedData['plugins'] ?? [];
    $results = array_merge($results, composer_policy_plugins($enabled, $approved));
}

if ($options['check'] !== 'plugins') {
    $composer = composer_policy_load_json($options['composer']);
    $scripts = $composer['scripts'] ?? [];
    foreach ($scripts as $event => $commands) {
        $scripts[$event] = array_values((array) $commands);
    }
    $baselineData = composer_policy_load_json($options['baseline']);
    $baseline = $baselineData['scripts'] ?? [];

    if ($options['update']) {
        $baselineData['scripts'] = $scripts;
        $baselineData['generated'] = date('Y-m-d');
        file_put_contents(
            $options['baseline'],
            json_encode($baselineData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
        fwrite(STDERR, sprintf('baseline updated: %s%s', $options['baseline'], PHP_EOL));

        exit(EXIT_OK);
    }

    $results = array_merge($results, composer_policy_scripts($scripts, $baseline));
}

$violations = array_values(array_filter($results, static fn (array $r): bool => $r['severity'] === 'violation'));
$warnings = array_values(array_filter($results, static fn (array $r): bool => $r['severity'] === 'warning'));

if ($options['format'] === 'json') {
    echo json_encode([
        'violations' => count($violations),
        'warnings' => count($warnings),
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    foreach ($results as $result) {
        printf('%s: %s%s', strtoupper($result['severity']), $result['message'], PHP_EOL);
    }
    printf('%d violation(s), %d warning(s)%s', count($violations), count($warnings), PHP_EOL);
}

exit($violations === [] ? EXIT_OK : EXIT_CHECK);
