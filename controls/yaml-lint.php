<?php

require __DIR__.'/../../vendor/autoload.php';

$files = glob(__DIR__.'/../../.github/workflows/*.yml');
$files[] = __DIR__.'/prometheus-alerts.example.yml';
foreach ($files as $file) {
    Symfony\Component\Yaml\Yaml::parseFile($file);
    echo basename($file), " OK\n";
}
