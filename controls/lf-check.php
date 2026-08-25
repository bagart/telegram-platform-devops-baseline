<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Line-ending control (02-developer-tooling.md §37): LF everywhere, never CRLF.
 *
 * Files opted out via `.gitattributes -text` (intentional non-LF content such
 * as negative fixtures) are exempt; --ignore-attributes reports them anyway
 * for scanner self-tests.
 *
 * Usage:
 *   php controls/lf-check.php --staged|--all|--paths=a,b [--fix] [--ignore-attributes] [--format=text|json]
 *
 * Exit codes: 0 clean (or fixed), 1 CRLF found, 2 usage error.
 */

const EXIT_OK = 0;
const EXIT_CHECK = 1;
const EXIT_USAGE = 2;

const MAX_FILE_SIZE = 2 * 1024 * 1024;

const BINARY_EXTENSIONS = [
    'png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'woff', 'woff2', 'ttf', 'eot',
    'otf', 'pdf', 'zip', 'gz', 'mp3', 'mp4', 'sqlite', 'phar', 'map',
];

$format = 'text';
$fix = false;
$mode = null;
$explicitPaths = [];
$ignoreAttributes = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--staged' || $arg === '--all') {
        $mode = substr($arg, 2);
    } elseif (str_starts_with($arg, '--paths=')) {
        $mode = 'paths';
        $explicitPaths = array_filter(explode(',', substr($arg, 8)));
    } elseif ($arg === '--fix') {
        $fix = true;
    } elseif ($arg === '--ignore-attributes') {
        // Scanner self-test mode (11 §84 negative fixtures): planted CRLF
        // must be reported even where .gitattributes exempts the file.
        $ignoreAttributes = true;
    } elseif ($arg === '--format=json' || $arg === '--json') {
        $format = 'json';
    } elseif ($arg === '--format=text') {
        $format = 'text';
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\nUsage: php controls/lf-check.php --staged|--all|--paths=a,b [--fix] [--ignore-attributes]\n");
        exit(EXIT_USAGE);
    }
}

if ($mode === null) {
    fwrite(STDERR, "Mode required: --staged, --all or --paths=...\n");
    exit(EXIT_USAGE);
}

$repoRoot = findRepoRoot();
if ($repoRoot === null) {
    fwrite(STDERR, "Unable to locate repository root (no .git directory found)\n");
    exit(EXIT_USAGE);
}

$files = match ($mode) {
    'staged' => gitFileList($repoRoot, ['diff', '--cached', '--name-only', '--diff-filter=ACM']),
    'all' => gitFileList($repoRoot, ['ls-files']),
    'paths' => $explicitPaths,
};

$exempt = array_flip($ignoreAttributes ? [] : gitEolExemptFiles($repoRoot, $files));

$violations = [];
$fixed = [];
foreach ($files as $relative) {
    if (isset($exempt[$relative])) {
        continue;
    }
    $absolute = "{$repoRoot}/{$relative}";
    if (!is_file($absolute) || isBinary($absolute)) {
        continue;
    }
    $contents = file_get_contents($absolute);
    if ($contents === false || !str_contains($contents, "\r\n")) {
        continue;
    }
    if ($fix) {
        file_put_contents($absolute, str_replace("\r\n", "\n", $contents));
        $fixed[] = $relative;
    } else {
        $violations[] = $relative;
    }
}

if ($format === 'json') {
    echo json_encode([
        'exit_code' => $violations === [] ? EXIT_OK : EXIT_CHECK,
        'fixed' => $fixed,
        'violations' => $violations,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    foreach ($fixed as $path) {
        fwrite(STDERR, "LF fix: {$path}\n");
    }
    foreach ($violations as $path) {
        fwrite(STDERR, "CRLF found: {$path} — run: php controls/lf-check.php --all --fix\n");
    }
    if ($violations === [] && $fixed === []) {
        fwrite(STDERR, "Line endings OK.\n");
    }
}

exit($violations === [] ? EXIT_OK : EXIT_CHECK);

function findRepoRoot(): ?string
{
    $dir = getcwd();
    while ($dir !== false && !is_dir("{$dir}/.git")) {
        $parent = dirname($dir);
        if ($parent === $dir) {
            return null;
        }
        $dir = $parent;
    }

    return $dir ?: null;
}

/**
 * @return string[]
 */
function gitFileList(string $root, array $args): array
{
    $cmd = array_merge(['git', '-C', $root], $args, ['-z']);
    $pipe = popen(implode(' ', array_map('escapeshellarg', $cmd)), 'r');
    $out = stream_get_contents($pipe);
    pclose($pipe);

    return $out === false ? [] : array_filter(explode("\0", $out));
}

/**
 * Paths where .gitattributes explicitly opts out of eol management (-text);
 * these declare intentional non-LF content (e.g. negative fixtures).
 *
 * @param string[] $files
 * @return string[]
 */
function gitEolExemptFiles(string $root, array $files): array
{
    if ($files === []) {
        return [];
    }
    $cmd = array_merge(['git', '-C', $root, 'check-attr', 'text', '-z', '--'], array_values($files));
    $pipe = popen(implode(' ', array_map('escapeshellarg', $cmd)), 'r');
    $out = stream_get_contents($pipe);
    pclose($pipe);

    if ($out === false || $out === '') {
        return [];
    }

    $exempt = [];
    foreach (array_chunk(explode("\0", $out), 3) as $record) {
        if (count($record) < 3) {
            continue;
        }
        [$path, , $value] = $record;
        if ($path !== '' && $value === 'unset') {
            $exempt[$path] = true;
        }
    }

    return array_keys($exempt);
}

function isBinary(string $absolute): bool
{
    if (filesize($absolute) > MAX_FILE_SIZE) {
        return true;
    }
    $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
    if (in_array($ext, BINARY_EXTENSIONS, true)) {
        return true;
    }
    $handle = fopen($absolute, 'rb');
    if ($handle === false) {
        return true;
    }
    $chunk = (string) fread($handle, 8192);
    fclose($handle);

    return str_contains($chunk, "\0");
}
