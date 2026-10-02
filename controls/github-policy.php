<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * GitHub-side supply-chain policy (03-security-and-supply-chain.md §64,
 * 05-ci-cd-and-release.md §61, 08-developer-experience-and-ai.md §65).
 *
 * workflows check — every .github/workflows/*.yml must declare minimal
 * top-level permissions (write-all is always a violation), pin every
 * third-party action to a full commit SHA (local "./..." refs are exempt)
 * and declare a top-level concurrency group when triggered by pull_request*
 * (05 §61: retry/concurrency/caching policy).
 *
 * codeowners check — .github/CODEOWNERS must exist and cover the required
 * security-sensitive paths (03 §64) either through an explicit directory
 * rule or the global "*" rule.
 *
 * Usage:
 *   php controls/github-policy.php [--root=path] [--format=text|json]
 *
 * Exit codes: 0 clean, 1 violation, 2 usage error, 3 autoload unavailable.
 */

$consumerRoot = baseline_consumer_root();
$autoloadCandidates = [
    getcwd().'/vendor/autoload.php',
    $consumerRoot === null ? null : $consumerRoot.'/vendor/autoload.php',
    // Legacy layout: engine lives inside the consumer repo tree.
    dirname(__DIR__).'/../vendor/autoload.php',
];
$loaded = false;
foreach ($autoloadCandidates as $candidate) {
    if (is_string($candidate) && is_file($candidate)) {
        require $candidate;
        $loaded = true;

        break;
    }
}
if (! $loaded) {
    fwrite(STDERR, "vendor/autoload.php not found — run 'composer install' first\n");
    exit(3);
}
if (! class_exists(Symfony\Component\Yaml\Yaml::class)) {
    fwrite(STDERR, "symfony/yaml is required for the github-policy control\n");
    exit(3);
}

const EXIT_OK = 0;
const EXIT_CHECK = 1;
const EXIT_USAGE = 2;

const SHA_PATTERN = '/^[a-f0-9]{40}$/';

const REQUIRED_CODEOWNERS_PATHS = ['.github', 'tools/baseline'];

/**
 * @param array<string, mixed> $workflow
 *
 * @return array<int, array{severity: string, message: string}>
 */
function github_policy_check_workflow(string $name, array $workflow): array
{
    $results = [];

    $permissions = $workflow['permissions'] ?? null;
    if ($permissions === null) {
        $results[] = ['severity' => 'violation', 'message' => sprintf(
            '%s: missing top-level permissions declaration',
            $name
        )];
    } elseif (is_string($permissions) && strcasecmp($permissions, 'write-all') === 0) {
        $results[] = ['severity' => 'violation', 'message' => sprintf(
            '%s: permissions "write-all" violates least privilege',
            $name
        )];
    }

    // Symfony YAML parses the unquoted "on:" key as boolean true.
    $on = $workflow['on'] ?? ($workflow[true] ?? null);
    if (! is_array($on)) {
        $on = [];
    }
    $pullTriggered = array_key_exists('pull_request', $on) || array_key_exists('pull_request_target', $on);
    if ($pullTriggered && ! array_key_exists('concurrency', $workflow)) {
        $results[] = ['severity' => 'violation', 'message' => sprintf(
            '%s: pull-request-triggered workflow without a concurrency group',
            $name
        )];
    }

    foreach (github_policy_collect_uses($workflow) as $uses) {
        $results = array_merge($results, github_policy_check_uses($name, $uses));
    }

    return $results;
}

/**
 * @return array<int, string>
 */
function github_policy_collect_uses(array $node): array
{
    $found = [];
    if (! is_array($node)) {
        return $found;
    }

    foreach ($node as $key => $value) {
        if (($key === 'uses' || $key === 'uses:') && is_string($value)) {
            $found[] = $value;
        }
        if (is_array($value)) {
            $found = array_merge($found, github_policy_collect_uses($value));
        }
    }

    return $found;
}

/**
 * @return array<int, array{severity: string, message: string}>
 */
function github_policy_check_uses(string $name, string $uses): array
{
    if (str_starts_with($uses, './') || str_starts_with($uses, 'docker://')) {
        return [];
    }

    $at = strrpos($uses, '@');
    if ($at === false) {
        return [['severity' => 'violation', 'message' => sprintf(
            '%s: action "%s" has no version ref at all',
            $name,
            $uses
        )]];
    }

    // Tolerate inline version comments inside quoted scalars ("@<sha> # v1").
    $ref = trim((string) preg_split('/\s+#/', substr($uses, $at + 1))[0]);
    if (preg_match(SHA_PATTERN, $ref) !== 1) {
        return [['severity' => 'violation', 'message' => sprintf(
            '%s: action "%s" is not pinned to a full commit SHA',
            $name,
            $uses
        )]];
    }

    return [];
}

/**
 * @return array<int, array{severity: string, message: string}>
 */
function github_policy_check_codeowners(string $path): array
{
    if (! is_file($path)) {
        return [['severity' => 'violation', 'message' => '.github/CODEOWNERS is missing']];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $rules = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = preg_split('/\s+/', $line) ?: [];
        $pattern = ltrim((string) ($parts[0] ?? ''), '/');
        if ($pattern === '') {
            continue;
        }
        if (count($parts) < 2) {
            continue;
        }
        $rules[] = rtrim($pattern, '/');
    }

    $results = [];
    foreach (REQUIRED_CODEOWNERS_PATHS as $required) {
        $covered = false;
        foreach ($rules as $rule) {
            if ($rule === '*' || str_starts_with($required.'/', $rule.'/') || $rule === $required) {
                $covered = true;

                break;
            }
        }
        if (! $covered) {
            $results[] = ['severity' => 'violation', 'message' => sprintf(
                'CODEOWNERS has no rule covering /%s',
                $required
            )];
        }
    }

    return $results;
}

/**
 * @return array<string, mixed>
 */
function github_policy_parse_args(array $argv): array
{
    $options = [
        'root' => baseline_consumer_root() ?? dirname(__DIR__, 2),
        'format' => 'text',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--(root|format)=(.+)$/', $arg, $m) === 1 && array_key_exists($m[1], $options)) {
            $options[$m[1]] = $m[2];

            continue;
        }
        if (in_array($arg, ['--help', '-h'], true)) {
            github_policy_usage();
        }

        github_policy_usage(sprintf('unknown argument: %s', $arg));
    }

    if (! in_array($options['format'], ['text', 'json'], true)) {
        github_policy_usage('invalid --format value');
    }

    return $options;
}

/**
 * @never-return
 */
function github_policy_usage(?string $error = null): void
{
    if ($error !== null) {
        fwrite(STDERR, $error.PHP_EOL);
    }
    fwrite(STDERR, 'usage: github-policy.php [--root=path] [--format=text|json]'.PHP_EOL);

    exit(EXIT_USAGE);
}

$options = github_policy_parse_args($argv);
$results = [];

$workflowFiles = glob(rtrim($options['root'], '/').'/.github/workflows/*.yml') ?: [];
if ($workflowFiles === []) {
    $results[] = ['severity' => 'warning', 'message' => 'no workflow files found under .github/workflows'];
}

foreach ($workflowFiles as $file) {
    $name = basename($file);
    try {
        $parsed = Symfony\Component\Yaml\Yaml::parseFile($file);
    } catch (Throwable $e) {
        $results[] = ['severity' => 'violation', 'message' => sprintf('%s: invalid YAML (%s)', $name, $e->getMessage())];

        continue;
    }
    if (! is_array($parsed)) {
        $results[] = ['severity' => 'violation', 'message' => sprintf('%s: workflow is empty', $name)];

        continue;
    }
    $results = array_merge($results, github_policy_check_workflow($name, $parsed));
}

$results = array_merge($results, github_policy_check_codeowners(rtrim($options['root'], '/').'/.github/CODEOWNERS'));

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
