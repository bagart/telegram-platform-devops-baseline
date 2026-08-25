<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Commit message validator (02-developer-tooling.md §21).
 *
 * The prefix list defined here is the single definition site of the project
 * commit convention; other documents reference it.
 *
 * Usage: php controls/commit-msg.php <path-to-commit-msg-file>
 *
 * Exit codes: 0 valid, 1 invalid, 2 usage error.
 */

const EXIT_OK = 0;
const EXIT_CHECK = 1;
const EXIT_USAGE = 2;

/**
 * Commit policy (prefixes + subject pattern) is consumer-overridable data:
 * tools/baseline/commit-policy.json overrides defaults/commit-policy.json.
 *
 * @return array{prefixes: list<string>, pattern: string}
 */
function loadCommitPolicy(): array
{
    $raw = file_get_contents(baseline_policy_path('commit-policy.json'));
    $policy = json_decode($raw !== false ? $raw : '', true);

    if (! is_array($policy) || ! isset($policy['prefixes']) || ! is_array($policy['prefixes'])) {
        fwrite(STDERR, "Invalid commit-policy.json — falling back to built-in defaults.\n");

        return [
            'prefixes' => ['feat', 'fix', 'refactor', 'perf', 'test', 'docs', 'build', 'ci', 'chore', 'security'],
            'pattern' => '/^(feat|fix|refactor|perf|test|docs|build|ci|chore|security)(\([^)]+\))?!?: \S.+$/',
        ];
    }

    return [
        'prefixes' => array_values(array_map(strval(...), $policy['prefixes'])),
        'pattern' => is_string($policy['pattern'] ?? null) ? $policy['pattern'] : '/^(?:'.implode('|', $policy['prefixes']).')(\([^)]+\))?!?: \S.+$/',
    ];
}

$COMMIT_POLICY = loadCommitPolicy();
$PREFIXES = $COMMIT_POLICY['prefixes'];
$SUBJECT_PATTERN = $COMMIT_POLICY['pattern'];

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php controls/commit-msg.php <commit-msg-file>\n");
    exit(EXIT_USAGE);
}

$path = $argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "Commit message file not found: {$path}\n");
    exit(EXIT_USAGE);
}

$lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
$subject = '';
foreach ($lines as $line) {
    $trimmed = ltrim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
        continue;
    }
    $subject = rtrim($line);
    break;
}

if ($subject === '') {
    fwrite(STDERR, "Commit message is empty.\n");
    exit(EXIT_CHECK);
}

if (preg_match('/^(Merge |Revert |fixup! |squash! )/', $subject) === 1) {
    exit(EXIT_OK);
}

if (preg_match($SUBJECT_PATTERN, $subject) === 1) {
    exit(EXIT_OK);
}

fwrite(STDERR, "Invalid commit message: {$subject}\n\n");
fwrite(STDERR, 'Expected format: <prefix>(<scope>)?!: description, prefix is one of: ' . implode(' ', $PREFIXES) . "\n");
fwrite(STDERR, "Examples:\n  security: harden dependency policy\n  feat(tg-webhook): validate secret before resolution\n\n");

$lower = strtolower($subject);
foreach ($PREFIXES as $prefix) {
    if (str_starts_with($lower, $prefix)) {
        fwrite(STDERR, "Suggestion: \"{$prefix}" . substr($subject, strlen($prefix)) . "\"\n");
        break;
    }
}

exit(EXIT_CHECK);
