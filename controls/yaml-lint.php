<?php

declare(strict_types=1);

/**
 * YAML lint control (02-developer-tooling.md §12): validates CI workflows and
 * config YAML. Engine-agnostic: runs against the consumer repository.
 *
 * Usage: php yaml-lint.php [file.yml | dir ...]
 *        (default: <repo>/.github/workflows/*.yml)
 */

$autoloadCandidates = [
    getcwd().'/vendor/autoload.php',
    // Legacy layout: engine lives inside the consumer repo tree.
    dirname(__DIR__).'/../vendor/autoload.php',
];
$loaded = false;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
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
    fwrite(STDERR, "symfony/yaml is required for the yaml-lint control\n");
    exit(3);
}

$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if (is_dir($arg)) {
        foreach (['*.yml', '*.yaml'] as $glob) {
            foreach (glob($arg.'/'.$glob) ?: [] as $file) {
                $files[] = $file;
            }
        }

        continue;
    }
    if (is_file($arg)) {
        $files[] = $arg;
    }
}

if ($files === []) {
    foreach (glob(getcwd().'/.github/workflows/*.yml') ?: [] as $file) {
        $files[] = $file;
    }
}

if ($files === []) {
    fwrite(STDERR, "No YAML files to lint\n");
    exit(0);
}

$status = 0;
foreach ($files as $file) {
    try {
        Symfony\Component\Yaml\Yaml::parseFile($file);
        echo basename($file), " OK\n";
    } catch (Throwable $e) {
        echo basename($file), ' FAIL: '.$e->getMessage()."\n";
        $status = 1;
    }
}

exit($status);
