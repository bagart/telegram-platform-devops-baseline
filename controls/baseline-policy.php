<?php

declare(strict_types=1);

/**
 * Consumer policy resolution for the baseline engine (RFC §4.3).
 *
 * Policy files are per-repo state; the package only ships defaults. The
 * first match wins:
 *   1. {$consumerRoot}/tools/baseline/{$name}   (consumer override)
 *   2. {BASELINE_DIR}/defaults/{$name}          (package default)
 *
 * When neither exists the consumer path is returned so callers produce their
 * natural "file not found" errors against the location the consumer owns.
 */
function baseline_policy_path(string $name): string
{
    static $consumerRoot = null;
    if ($consumerRoot === null) {
        $dir = getcwd();
        while ($dir !== false && ! is_file("{$dir}/composer.json")) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                $dir = false;

                break;
            }
            $dir = $parent;
        }
        $consumerRoot = $dir === false ? null : $dir;
    }

    $baselineDir = dirname(__DIR__);

    if ($consumerRoot !== null && is_file("{$consumerRoot}/tools/baseline/{$name}")) {
        return "{$consumerRoot}/tools/baseline/{$name}";
    }
    if (is_file("{$baselineDir}/defaults/{$name}")) {
        return "{$baselineDir}/defaults/{$name}";
    }

    return $consumerRoot !== null
        ? "{$consumerRoot}/tools/baseline/{$name}"
        : "{$baselineDir}/defaults/{$name}";
}
