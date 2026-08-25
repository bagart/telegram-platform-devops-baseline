<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Baseline-owned file manifest (11 §6–§7 versioning, §64–§66 drift).
 *
 * Generates a SHA-256 manifest of every file the security baseline owns so
 * drift between the recorded state and the working tree is detectable
 * (cmd/baseline/drift-report) and updates can be packed and distributed
 * (cmd/baseline/pack).
 *
 * Usage:
 *   php controls/manifest.php --generate
 *   php controls/manifest.php --verify [--format=text|json]
 *
 * Exit codes: 0 identical, 1 drift detected, 2 usage error.
 */

const EXIT_OK = 0;
const EXIT_DRIFT = 1;
const EXIT_USAGE = 2;

require_once __DIR__.'/baseline-policy.php';

// Operates on the CONSUMER repository: walk up from CWD for composer.json + .git.
$root = getcwd();
while ($root !== false && ! (is_file("{$root}/composer.json") && is_dir("{$root}/.git"))) {
    $parent = dirname($root);
    if ($parent === $root) {
        $root = false;

        break;
    }
    $root = $parent;
}
if ($root === false) {
    fwrite(STDERR, "Unable to locate consumer repository root (composer.json + .git)\n");
    exit(EXIT_USAGE);
}
$manifestFile = "{$root}/tools/baseline/MANIFEST.json";
$consumerVersion = "{$root}/tools/baseline/VERSION";
$version = is_file($consumerVersion)
    ? trim((string) file_get_contents($consumerVersion))
    : trim((string) file_get_contents(baseline_policy_path('VERSION')));

$ownedGlobs = [
    'cmd/lib/*',
    'cmd/dev/*',
    'cmd/git/*',
    'cmd/deps/*',
    'cmd/ci/*',
    'cmd/ops/*',
    'cmd/release/*',
    'cmd/docker/*',
    'cmd/baseline/*',
    'cmd/help',
    'tools/baseline/*',
    'tools/git-hooks/*',
    '.github/workflows/*.yml',
    'deploy/systemd/*',
    'deploy/monitoring/*',
];
$excluded = [
    'tools/baseline/MANIFEST.json',
    'tools/baseline/perf-baselines.json',
];

function owned_files(array $globs, array $excluded, string $root): array
{
    $files = [];
    foreach ($globs as $glob) {
        foreach (glob($root.'/'.$glob) ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr((string) $path, strlen($root))), '/');
            if (in_array($rel, $excluded, true) || str_ends_with($rel, '~') || str_ends_with($rel, '.bak')) {
                continue;
            }
            $files[$rel] = hash_file('sha256', (string) $path);
        }
    }
    ksort($files);

    return $files;
}

$mode = $argv[1] ?? '';
switch ($mode) {
    case '--generate':
        $files = owned_files($ownedGlobs, $excluded, $root);
        $payload = [
            'baseline_version' => $version,
            'generated_at' => date('c'),
            'file_count' => count($files),
            'files' => $files,
        ];
        file_put_contents($manifestFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        printf("manifest generated: %d files, baseline v%s\n", count($files), $version);
        exit(EXIT_OK);

    case '--verify':
        if (! is_file($manifestFile)) {
            fwrite(STDERR, "No MANIFEST.json — run --generate first.\n");
            exit(EXIT_DRIFT);
        }
        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (! is_array($manifest) || ! isset($manifest['files']) || ! is_array($manifest['files'])) {
            fwrite(STDERR, "MANIFEST.json malformed.\n");
            exit(EXIT_DRIFT);
        }
        $current = owned_files($ownedGlobs, $excluded, $root);
        $missing = [];
        $changed = [];
        $added = [];
        foreach ($manifest['files'] as $rel => $hash) {
            if (! isset($current[$rel])) {
                $missing[] = $rel;
            } elseif ($current[$rel] !== $hash) {
                $changed[] = $rel;
            }
        }
        foreach (array_keys($current) as $rel) {
            if (! isset($manifest['files'][$rel])) {
                $added[] = $rel;
            }
        }
        sort($missing);
        sort($changed);
        sort($added);
        $clean = $missing === [] && $changed === [];

        $jsonRequested = in_array('--format=json', $argv, true);
        if ($jsonRequested) {
            echo json_encode([
                'baseline_version' => $manifest['baseline_version'] ?? null,
                'clean' => $clean,
                'missing' => $missing,
                'changed' => $changed,
                'untracked_by_manifest' => $added,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        } else {
            printf("baseline v%s — %s\n", $manifest['baseline_version'] ?? '?', $clean ? 'in sync' : 'DRIFT DETECTED');
            foreach ($missing as $rel) {
                printf("  MISSING  %s\n", $rel);
            }
            foreach ($changed as $rel) {
                printf("  CHANGED  %s\n", $rel);
            }
            foreach ($added as $rel) {
                printf("  NEW      %s (not in manifest — regenerate to adopt)\n", $rel);
            }
        }

        exit($clean ? EXIT_OK : EXIT_DRIFT);

    default:
        fwrite(STDERR, "Usage: manifest.php --generate|--verify [--format=json]\n");
        exit(EXIT_USAGE);
}
