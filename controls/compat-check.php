<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Compatibility matrix validation (04-qa-and-testing.md §12).
 *
 * Validates the current runtime against the declared environment contracts in
 * compat-matrix.json and detects drift between the matrix and each
 * component's composer.json:
 *
 *   - current PHP version satisfies the composer php constraint;
 *   - required extensions (composer ext-*) are loaded;
 *   - every matrix-declared PHP version satisfies the composer constraint;
 *   - the matrix ext list matches the composer ext-* requires;
 *   - botApi declarations agree across entries and with the component's
 *     composer.json extra.bot-api.version when it declares one;
 *   - the root shell block: current OS family is declared and bash satisfies
 *     the declared minimum (02-developer-tooling.md §43).
 *
 * Usage:
 *   php controls/compat-check.php [--format=text|json] [--matrix=<path>]
 *
 * Exit codes: 0 valid, 1 violations found, 2 usage/config error.
 */

const EXIT_OK = 0;
const EXIT_VIOLATIONS = 1;
const EXIT_ERROR = 2;

$format = 'text';
$matrixOverride = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--format=json' || $arg === '--json') {
        $format = 'json';
    } elseif ($arg === '--format=text') {
        $format = 'text';
    } elseif (str_starts_with($arg, '--matrix=')) {
        $matrixOverride = substr($arg, 9);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\nUsage: php controls/compat-check.php [--format=text|json] [--matrix=<path>]\n");
        exit(EXIT_ERROR);
    }
}

$repoRoot = findRepoRoot();
if ($repoRoot === null) {
    fwrite(STDERR, "Unable to locate repository root\n");
    exit(EXIT_ERROR);
}

$matrixFile = $matrixOverride ?? baseline_policy_path('compat-matrix.json');
if (! is_file($matrixFile)) {
    fwrite(STDERR, "Missing {$matrixFile}\n");
    exit(EXIT_ERROR);
}

$matrix = json_decode((string) file_get_contents($matrixFile), true);
if (! is_array($matrix) || ! isset($matrix['entries']) || ! is_array($matrix['entries'])) {
    fwrite(STDERR, "Invalid matrix: expected top-level \"entries\" array\n");
    exit(EXIT_ERROR);
}

$results = [];
foreach ($matrix['entries'] as $entry) {
    foreach (checkEntry($repoRoot, $entry) as $violation) {
        $results[] = ['name' => $entry['name'] ?? '?', 'violation' => $violation];
    }
}

foreach (checkShell($matrix['shell'] ?? []) as $violation) {
    $results[] = ['name' => 'shell', 'violation' => $violation];
}

foreach (checkBotApiAgreement($matrix['entries']) as $violation) {
    $results[] = ['name' => 'botApi', 'violation' => $violation];
}

foreach ($results as $r) {
    fwrite(STDERR, "COMPAT: [{$r['name']}] {$r['violation']}\n");
}

if ($format === 'json') {
    echo json_encode(['violations' => count($results), 'results' => $results]), "\n";
} elseif ($results === []) {
    echo 'compat-matrix OK (' . count($matrix['entries']) . " entries)\n";
} else {
    echo "compat-matrix FAILED (" . count($results) . " violation(s))\n";
}

exit($results === [] ? EXIT_OK : EXIT_VIOLATIONS);

/**
 * @param array<string, mixed> $entry
 * @return list<string>
 */
function checkEntry(string $repoRoot, array $entry): array
{
    $name = (string) ($entry['name'] ?? '?');
    $path = $repoRoot . '/' . ($entry['path'] ?? '.');
    $composerFile = rtrim($path, '/') . '/composer.json';

    if (! is_file($composerFile)) {
        return ["composer.json not found at {$entry['path']}"];
    }

    $composer = json_decode((string) file_get_contents($composerFile), true);
    if (! is_array($composer)) {
        return ["unparseable composer.json at {$entry['path']}"];
    }

    $require = $composer['require'] ?? [];
    $phpConstraint = (string) ($require['php'] ?? '*');
    $composerExt = [];
    foreach (array_keys(is_array($require) ? $require : []) as $pkg) {
        if (str_starts_with((string) $pkg, 'ext-')) {
            $composerExt[] = substr((string) $pkg, 4);
        }
    }

    $violations = [];

    if (! phpVersionSatisfies(PHP_VERSION, $phpConstraint)) {
        $violations[] = "current PHP " . PHP_VERSION . " does not satisfy composer constraint '{$phpConstraint}'";
    }

    $declaredExt = array_values((array) ($entry['ext'] ?? []));
    sort($composerExt);
    sort($declaredExt);
    if ($composerExt !== $declaredExt) {
        $violations[] = sprintf(
            'ext drift: composer requires [%s], matrix declares [%s]',
            implode(',', $composerExt),
            implode(',', $declaredExt),
        );
    }

    foreach ($composerExt as $ext) {
        if (! extension_loaded($ext)) {
            $violations[] = "required extension ext-{$ext} is not loaded";
        }
    }

    foreach ((array) ($entry['php'] ?? []) as $version) {
        if (! phpVersionSatisfies((string) $version, $phpConstraint)) {
            $violations[] = "matrix version {$version} does not satisfy composer constraint '{$phpConstraint}'";
        }
    }

    foreach (checkBotApiDeclaration($name, $entry, $composer) as $violation) {
        $violations[] = $violation;
    }

    return $violations;
}

/**
 * botApi declared on an entry must be well-formed and must match the
 * component's own composer.json extra.bot-api.version when it declares one.
 *
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $composer
 * @return list<string>
 */
function checkBotApiDeclaration(string $name, array $entry, array $composer): array
{
    $declared = $entry['botApi'] ?? null;
    if ($declared === null) {
        return [];
    }

    $declared = (string) $declared;
    if (preg_match('/^\d+\.\d+(\.\d+)?$/', $declared) !== 1) {
        return ["invalid botApi '{$declared}' — expected X.Y or X.Y.Z"];
    }

    $extraVersion = $composer['extra']['bot-api']['version'] ?? null;
    if ($extraVersion !== null && (string) $extraVersion !== $declared) {
        return ["botApi drift: matrix declares {$declared}, composer.json extra declares {$extraVersion}"];
    }

    return [];
}

/**
 * All botApi declarations across entries must agree — the platform targets
 * exactly one Telegram Bot API version at a time.
 *
 * @param list<array<string, mixed>> $entries
 * @return list<string>
 */
function checkBotApiAgreement(array $entries): array
{
    $byName = [];
    foreach ($entries as $entry) {
        if (isset($entry['botApi'])) {
            $byName[(string) ($entry['name'] ?? '?')] = (string) $entry['botApi'];
        }
    }

    if (count(array_unique($byName)) > 1) {
        $parts = [];
        foreach ($byName as $name => $version) {
            $parts[] = "{$name}={$version}";
        }

        return ['botApi disagreement across entries: '.implode(', ', $parts)];
    }

    return [];
}

/**
 * Root shell contract: OS family allowlist + bash minimum version.
 *
 * @param array<string, mixed> $shell
 * @return list<string>
 */
function checkShell(array $shell): array
{
    if ($shell === []) {
        return [];
    }

    $violations = [];

    $osFamily = PHP_OS_FAMILY;
    $declaredOs = array_values((array) ($shell['os'] ?? []));
    if ($declaredOs !== [] && ! in_array($osFamily, $declaredOs, true)) {
        $violations[] = "current OS family '{$osFamily}' is not in the declared os list [" . implode(',', $declaredOs) . ']';
    }

    $bashMin = $shell['bashMin'] ?? null;
    if ($bashMin !== null) {
        $current = currentBashVersion();
        if ($current === null) {
            $violations[] = 'bash is not available but the shell contract requires >= ' . (string) $bashMin;
        } elseif (! phpVersionSatisfies($current, '>=' . (string) $bashMin)) {
            $violations[] = "bash {$current} does not satisfy the declared minimum " . (string) $bashMin;
        }
    }

    return $violations;

}

/** @return null|string Major.Minor of the host bash, null when unavailable */
function currentBashVersion(): ?string
{
    $output = @shell_exec('bash --version 2>&1');
    if (! is_string($output) || preg_match('/version\s+(\d+)\.(\d+)/', $output, $m) !== 1) {
        return null;
    }

    return $m[1] . '.' . $m[2];
}

/**
 * Evaluates a composer version subset: alternatives on " | ", operators
 * ^ >= <= > < = and bare exact versions. Sufficient for the constraints
 * used by this repository's components.
 */
function phpVersionSatisfies(string $version, string $constraint): bool
{
    foreach (explode('|', $constraint) as $part) {
        $part = trim($part);
        if ($part === '*' || $part === '') {
            return true;
        }
        if (constraintMatches($version, $part)) {
            return true;
        }
    }

    return false;
}

function constraintMatches(string $version, string $constraint): bool
{
    if (str_starts_with($constraint, '^')) {
        return compareVersions($version, substr($constraint, 1)) >= 0 && bumpCaretFloor($version, substr($constraint, 1));
    }
    if (preg_match('/^(>=|<=|>|<|=)\s*(.+)$/', $constraint, $m) === 1) {
        $cmp = compareVersions($version, trim($m[2]));
        [, $op, $operand] = $m;

        return match ($op) {
            '>=' => $cmp >= 0,
            '<=' => $cmp <= 0,
            '>' => $cmp > 0,
            '<' => $cmp < 0,
            '=' => $cmp === 0,
        };
    }

    return compareVersions($version, $constraint) === 0;
}

/**
 * Caret semantics: >=floor and < next breaking version. For PHP runtimes the
 * minor release never breaks, so "^8.5" admits any 8.* >= 8.5.
 */
function bumpCaretFloor(string $version, string $floor): bool
{
    $floorParts = explode('.', $floor);

    return majorOf($version) === (int) $floorParts[0];
}

function majorOf(string $version): int
{
    return (int) explode('.', $version)[0];
}

/**
 * Numeric dot-separated comparison; missing segments count as 0.
 *
 * @return -1|0|1
 */
function compareVersions(string $left, string $right): int
{
    $l = array_map('intval', explode('.', $left));
    $r = array_map('intval', explode('.', $right));
    $width = max(count($l), count($r));
    for ($i = 0; $i < $width; $i++) {
        $lv = $l[$i] ?? 0;
        $rv = $r[$i] ?? 0;
        if ($lv !== $rv) {
            return $lv < $rv ? -1 : 1;
        }
    }

    return 0;
}

/** @return null|string */
function findRepoRoot(): ?string
{
    $dir = __DIR__;
    for ($i = 0; $i < 10; $i++) {
        if (is_file($dir . '/artisan') && is_dir($dir . '/tools/baseline')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            return null;
        }
        $dir = $parent;
    }

    return null;
}
