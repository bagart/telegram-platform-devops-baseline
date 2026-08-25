<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Test quarantine registry validator (04-qa-and-testing.md §18).
 *
 * Quarantined flaky tests are listed in test-quarantine.json with a mandatory
 * expiry. Expired entries are a policy failure: fix the test or renew the
 * quarantine with an explicit reason — never silently.
 *
 * Exit codes: 0 valid, 1 malformed entry, 5 expired entry present.
 */

const EXIT_OK = 0;
const EXIT_CHECK = 1;
const EXIT_USAGE = 2;
const EXIT_POLICY = 5;

$path = __DIR__.'/test-quarantine.json';
if (! is_file($path)) {
    fwrite(STDERR, "No quarantine registry — nothing to validate.\n");
    exit(EXIT_OK);
}

$entries = json_decode((string) file_get_contents($path), true);
if (! is_array($entries)) {
    fwrite(STDERR, "Quarantine registry is not valid JSON.\n");
    exit(EXIT_CHECK);
}

$expired = 0;
$today = date('Y-m-d');
foreach ($entries as $i => $entry) {
    foreach (['suite', 'test', 'reason', 'expires'] as $field) {
        if (! isset($entry[$field]) || ! is_string($entry[$field]) || $entry[$field] === '') {
            fwrite(STDERR, "Entry #{$i}: missing or empty field '{$field}'\n");
            exit(EXIT_CHECK);
        }
    }
    if ($entry['expires'] < $today) {
        fwrite(STDERR, "POLICY FAILURE: expired quarantine {$entry['suite']}::{$entry['test']} (expired {$entry['expires']}) — fix the test or renew explicitly\n");
        $expired++;
    } else {
        fwrite(STDERR, "quarantine active: {$entry['suite']}::{$entry['test']} until {$entry['expires']} — {$entry['reason']}\n");
    }
}

exit($expired > 0 ? EXIT_POLICY : EXIT_OK);
