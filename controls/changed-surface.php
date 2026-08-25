<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Changed-surface detection (02-developer-tooling.md §14).
 *
 * Maps changed files to repository surfaces so checks can select the minimum
 * applicable control set. Conservative for security: unknown paths map to
 * "unknown", and callers must run the broader check then.
 *
 * Usage:
 *   php controls/changed-surface.php [--base=HEAD] [--format=text|json]
 *
 * Output (text): space-separated surface names, one line.
 * Exit codes: 0 success, 2 usage error.
 */

const EXIT_OK = 0;
const EXIT_USAGE = 2;

const SURFACE_RULES = [
    'php' => ['*.php'],
    'frontend' => ['resources/js/*', 'resources/views/*', '*.ts', '*.tsx', '*.jsx', '*.css', '*.scss', 'package.json', 'pnpm-lock.yaml', 'package-lock.json'],
    'docker' => ['Dockerfile*', 'docker/*', 'docker-compose*.yaml', 'docker-compose*.yml', '.dockerignore'],
    'ci' => ['.github/*', '.github/workflows/*'],
    'deps' => ['composer.json', 'composer.lock', 'package.json', 'pnpm-lock.yaml', 'package-lock.json'],
    'database' => ['database/*'],
    'async' => ['misc/BAGArt/php-async-kernel-lib/*', 'misc/BAGArt/php-async-kernel-client*/*'],
    'telegram' => ['misc/BAGArt/telegram-bot-lib/*', 'misc/BAGArt/telegram-bot-basic-lib/*', 'misc/BAGArt/telegram-bot-management/*'],
    'docs' => ['docs/*', '*.md'],
];

$base = 'HEAD';
$format = 'text';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = substr($arg, 7);
    } elseif ($arg === '--format=json' || $arg === '--json') {
        $format = 'json';
    } elseif ($arg === '--format=text') {
        $format = 'text';
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\nUsage: php controls/changed-surface.php [--base=HEAD] [--format=text|json]\n");
        exit(EXIT_USAGE);
    }
}

$repoRoot = findRepoRoot();
if ($repoRoot === null) {
    fwrite(STDERR, "Unable to locate repository root\n");
    exit(EXIT_USAGE);
}

$changed = gitChangedFiles($repoRoot, $base);
$surfaces = [];
foreach ($changed as $path) {
    foreach (matchSurface($path) as $surface) {
        $surfaces[$surface] = true;
    }
}
$surfaces = array_keys($surfaces);
sort($surfaces);

if ($format === 'json') {
    echo json_encode(['base' => $base, 'files' => count($changed), 'surfaces' => $surfaces]), "\n";
} else {
    echo implode(' ', $surfaces), "\n";
}

exit(EXIT_OK);

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
function gitChangedFiles(string $root, string $base): array
{
    exec(sprintf('git -C %s diff --name-only -z %s 2>/dev/null', escapeshellarg($root), escapeshellarg($base)), $out, $code);
    if ($code !== 0) {
        return [];
    }
    $joined = implode('', $out);

    return array_values(array_filter(explode("\0", $joined)));
}

/**
 * @return string[] surfaces for one path; empty when no rule matches (unknown).
 */
function matchSurface(string $path): array
{
    $normalized = str_replace('\\', '/', $path);
    $matched = [];
    foreach (SURFACE_RULES as $surface => $patterns) {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $normalized)) {
                $matched[] = $surface;
                break;
            }
        }
    }

    return $matched;
}
