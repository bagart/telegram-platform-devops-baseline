<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Architectural security-invariant audit (03-security-and-supply-chain.md §65).
 *
 * Machine-checks that the 12 SEC invariants stay wired into the repository:
 * scanners present and CI-connected, no silent failure paths, scoped and
 * expiring exceptions, policy files driving the tools, profile-based
 * framework checks. Deep per-file analysis stays with the dedicated tools
 * (secret-scan, github-policy, composer-policy); this audit verifies the
 * system-level invariants they implement are actually enforced.
 *
 * Usage:
 *   php controls/sec-invariants.php [--format=text|json]
 *
 * Exit codes: 0 all machine-checkable invariants hold, 1 violation,
 * 2 usage error.
 */

const EXIT_OK = 0;
const EXIT_CHECK = 1;
const EXIT_USAGE = 2;

/**
 * @param  array{workflows: array<string, string>, toolVersions: ?array<string, mixed>, allowlist: ?array<int, array<string, mixed>>, requiredPolicyFiles: array<string, bool>, profilesScript: ?string, configSecuritySource: ?string, githubPolicySource: ?string} $input
 * @return array<int, array{id: string, title: string, status: string, detail: string}>
 */
function sec_invariants_evaluate(array $input): array
{
    $results = [];
    $add = static function (string $id, string $title, bool $ok, string $detail) use (&$results): void {
        $results[] = ['id' => $id, 'title' => $title, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail];
    };

    $workflowText = implode("\n", $input['workflows']);

    // SEC-01 — No secrets in source: a secret scanner must be wired into CI.
    $scannersInCi = 0;
    foreach ($input['workflows'] as $name => $text) {
        if (str_contains($text, 'gitleaks') || str_contains($text, 'secret-scan.php')) {
            $scannersInCi++;
        }
    }
    $add('SEC-01', 'No secrets in source', $scannersInCi > 0, sprintf('%d workflow(s) run a secret scanner', $scannersInCi));

    // SEC-02 — .gitignore is not a security boundary: full-tree/history scan.
    $fullScan = str_contains($workflowText, 'fetch-depth: 0') && str_contains($workflowText, 'gitleaks')
        || str_contains($workflowText, 'secret-scan.php --all');
    $add('SEC-02', '.gitignore is not a security boundary', $fullScan, 'CI scans history (fetch-depth 0 + gitleaks) or the full working tree');

    // SEC-03 — No silent scanner failure: the mandatory secret-scanning
    // layer (gitleaks history scan + built-in tree scan) may never run under
    // continue-on-error, neither job-level nor step-level. SAST stays in its
    // staged rollout (11 §47) until the A2 promotion lands.
    $coe = static fn (string $s): bool => preg_match('/^\s*continue-on-error:\s*true\b/m', $s) === 1;
    $scanner = static fn (string $s): bool => str_contains($s, 'gitleaks') || str_contains($s, 'secret-scan.php');

    $silent = [];
    foreach ($input['workflows'] as $name => $text) {
        foreach (sec_invariants_job_slices($text) as $job) {
            ['prelude' => $prelude, 'steps' => $steps] = sec_invariants_step_chunks($job);

            foreach ($steps as $step) {
                if ($scanner($step) && $coe($step)) {
                    $silent[] = $name;
                }
            }
            if ($coe($prelude) && count(array_filter($steps, $scanner)) > 0) {
                $silent[] = $name;
            }
        }
    }
    $silent = array_values(array_unique($silent));
    $add('SEC-03', 'No silent scanner failure', $silent === [], $silent === [] ? 'mandatory secret scans never continue-on-error' : sprintf('continue-on-error around secret scans in %s', implode(', ', $silent)));

    // SEC-04 — CI enforcement: a security workflow exists with triggers.
    $hasSecurityWorkflow = false;
    foreach ($input['workflows'] as $name => $text) {
        if (str_contains($text, 'gitleaks') && preg_match('/^on:/m', $text) === 1) {
            $hasSecurityWorkflow = true;
        }
    }
    $add('SEC-04', 'CI enforcement', $hasSecurityWorkflow, 'triggered security workflow present');

    // SEC-05 — Immutable production identity: actions SHA-pinned by policy.
    $pinningEnforced = $input['githubPolicySource'] !== null
        && str_contains($input['githubPolicySource'], 'SHA_PATTERN');
    $add('SEC-05', 'Immutable production identity', $pinningEnforced, 'github-policy enforces full-SHA action pinning');

    // SEC-06 — Explicit exceptions: every allowlist entry is fully scoped and
    // unexpired.
    $badEntries = [];
    $today = strtotime('today');
    foreach ($input['allowlist'] ?? [] as $i => $entry) {
        foreach (['rule', 'path', 'reason', 'expires'] as $key) {
            if (! isset($entry[$key]) || ! is_string($entry[$key]) || trim($entry[$key]) === '') {
                $badEntries[] = sprintf('#%d missing %s', $i, $key);
            }
        }
        $expires = strtotime((string) ($entry['expires'] ?? ''));
        if ($expires === false || $expires < $today) {
            $badEntries[] = sprintf('#%s expired or unparsable expiry', (string) $i);
        }
    }
    $add('SEC-06', 'Explicit exceptions', $badEntries === [], $badEntries === [] ? 'all allowlist entries carry reason and valid expiry' : implode('; ', $badEntries));

    // SEC-07 — Narrow suppression: exception paths may not be global.
    $global = [];
    foreach ($input['allowlist'] ?? [] as $i => $entry) {
        $path = trim((string) ($entry['path'] ?? ''));
        if ($path === '' || $path === '*' || $path === '/*') {
            $global[] = sprintf('#%d global path', $i);
        }
    }
    $add('SEC-07', 'Narrow suppression', $global === [], $global === [] ? 'every exception path is scoped' : implode('; ', $global));

    // SEC-08 — Dependencies are code: lockfiles reviewed + CI review gate.
    $depsWired = ($input['requiredPolicyFiles']['composer.lock'] ?? false)
        && ($input['requiredPolicyFiles']['.github/workflows/dependency-review.yml'] ?? false);
    $add('SEC-08', 'Dependencies are code', $depsWired, 'composer.lock tracked and dependency-review workflow present');

    // SEC-09 — CI is production code: workflows linted via baseline tooling.
    $ciIsCode = ($input['requiredPolicyFiles']['tools/baseline/yaml-lint.php'] ?? false)
        && $input['githubPolicySource'] !== null;
    $add('SEC-09', 'CI is production code', $ciIsCode, 'yaml-lint + github-policy cover .github/workflows');

    // SEC-10 — Security tooling is supply chain: versions pinned centrally.
    $unpinned = [];
    foreach (($input['toolVersions'] ?? []) ?: [] as $tool => $spec) {
        if (str_starts_with((string) $tool, '_')) {
            continue;
        }
        if (! is_array($spec) || ! isset($spec['pinned']) && ! isset($spec['min'])) {
            $unpinned[] = (string) $tool;
        }
    }
    $versionsPresent = is_array($input['toolVersions']) && $input['toolVersions'] !== [];
    $add('SEC-10', 'Security tooling is supply chain', $versionsPresent && $unpinned === [], $versionsPresent ? ($unpinned === [] ? 'tool versions pinned in tool-versions.json' : sprintf('unpinned: %s', implode(', ', $unpinned))) : 'tool-versions.json missing or empty');

    // SEC-11 — Framework-specific security is profile-based.
    $profileBased = $input['profilesScript'] !== null
        && str_contains($input['profilesScript'], 'PROFILES')
        && $input['configSecuritySource'] !== null
        && str_contains($input['configSecuritySource'], '--profile');
    $add('SEC-11', 'Framework-specific security is profile-based', $profileBased, 'profile detection exists; framework checks gated behind --profile');

    // SEC-12 — Security controls are policy-driven: policy JSONs exist.
    $missingPolicy = [];
    foreach (array_diff_key($input['requiredPolicyFiles'], ['composer.lock' => true, 'dependency-review.yml' => true, 'yaml-lint.php' => true]) as $file => $exists) {
        if (! $exists) {
            $missingPolicy[] = $file;
        }
    }
    $add('SEC-12', 'Security controls are policy-driven', $missingPolicy === [], $missingPolicy === [] ? 'policy files present' : sprintf('missing: %s', implode(', ', $missingPolicy)));

    return $results;
}

/**
 * Splits a job slice into the prelude (job-level keys before the first step)
 * and per-step chunks. Step markers are YAML list dashes at any indent.
 *
 * @return array{prelude: string, steps: list<string>}
 */
function sec_invariants_step_chunks(string $job): array
{
    $parts = preg_split('/^\s*-\s+/m', $job) ?: [$job];
    $prelude = (string) array_shift($parts);

    return ['prelude' => $prelude, 'steps' => array_values($parts)];
}

/**
 * Splits a workflow into per-job text slices (2-space-indented keys under
 * jobs:). Deliberately lightweight — the audit needs containment, not a full
 * YAML parse.
 *
 * @return list<string>
 */
function sec_invariants_job_slices(string $text): array
{
    $slices = [];
    $current = null;
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (preg_match('/^  [A-Za-z0-9_-]+:\s*$/', $line) === 1) {
            if ($current !== null) {
                $slices[] = $current;
            }
            $current = $line;
        } elseif ($current !== null) {
            // A dedent to column 0 ends the jobs section.
            if ($line !== '' && preg_match('/^\S/', $line) === 1) {
                $slices[] = $current;
                $current = null;

                continue;
            }
            $current .= "\n".$line;
        }
    }
    if ($current !== null) {
        $slices[] = $current;
    }

    return $slices;
}

/**
 * Gathers the audit input from the repository rooted at $root.
 *
 * @return array{workflows: array<string, string>, toolVersions: ?array<string, mixed>, allowlist: ?array<int, array<string, mixed>>, requiredPolicyFiles: array<string, bool>, profilesScript: ?string, configSecuritySource: ?string, githubPolicySource: ?string}
 */
function sec_invariants_collect(string $root): array
{
    $read = static function (string $path): ?string {
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    };
    $json = static function (string $path): ?array {
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    };

    $workflows = [];
    foreach (glob($root.'/.github/workflows/*.yml') ?: [] as $file) {
        $name = basename($file);
        $text = $read($file);
        if ($text !== null) {
            $workflows[$name] = $text;
        }
    }

    $policyFiles = [];
    foreach (['composer.lock', '.github/workflows/dependency-review.yml', 'tools/baseline/yaml-lint.php', 'tools/baseline/secret-allowlist.json', 'tools/baseline/composer-plugin-allowlist.json', 'tools/baseline/composer-scripts-baseline.json'] as $rel) {
        $policyFiles[$rel] = is_file($root.'/'.$rel);
    }

    // Post-extraction, consumer tools/baseline/<control> may be a delegation
    // stub while the logic lives in the engine. The invariant must audit the
    // logic actually executed, so both sources are considered.
    $sourceOf = static function (string $name) use ($read, $root): ?string {
        $consumer = $read($root.'/tools/baseline/'.$name);
        $engine = $read(dirname(__DIR__).'/controls/'.$name);

        return match (true) {
            $consumer === null => $engine,
            $engine === null => $consumer,
            default => $consumer."\n".$engine,
        };
    };

    return [
        'workflows' => $workflows,
        'toolVersions' => $json($root.'/tools/baseline/tool-versions.json'),
        'allowlist' => $json($root.'/tools/baseline/secret-allowlist.json'),
        'requiredPolicyFiles' => $policyFiles,
        'profilesScript' => $sourceOf('profiles.sh'),
        'configSecuritySource' => $sourceOf('config-security.php'),
        'githubPolicySource' => $sourceOf('github-policy.php'),
    ];
}

// CLI entry point — skipped when the file is included for its functions
// (tests require this file directly to exercise sec_invariants_evaluate()).
if (realpath($argv[0] ?? '') !== __FILE__) {
    return;
}

$format = 'text';
foreach (array_slice($argv, 1) as $arg) {
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

$results = sec_invariants_evaluate(sec_invariants_collect(baseline_consumer_root() ?? dirname(__DIR__, 2)));
$failures = array_values(array_filter($results, static fn (array $r): bool => $r['status'] === 'fail'));

if ($format === 'json') {
    echo json_encode(['violations' => count($failures), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    foreach ($results as $result) {
        printf('%s %s (%s) — %s%s', strtoupper($result['status']), $result['id'], $result['title'], $result['detail'], PHP_EOL);
    }
    printf('%d/%d invariants hold%s', count($results) - count($failures), count($results), PHP_EOL);
}

exit($failures === [] ? EXIT_OK : EXIT_CHECK);
