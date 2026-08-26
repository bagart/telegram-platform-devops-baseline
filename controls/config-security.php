<?php

declare(strict_types=1);

require_once __DIR__.'/baseline-policy.php';

/**
 * Security configuration checks (03-security-and-supply-chain.md §28).
 *
 * Evaluates framework configuration against security-sensitive settings
 * (debug mode, app key, cookie flags, CORS credentials). Violations block
 * only when the application environment is production; elsewhere they are
 * reported as warnings so developer environments stay green while the
 * drift remains visible.
 *
 * Usage:
 *   php controls/config-security.php [--format=text|json]
 *       [--config-json=file] [--env=name]
 *
 * Without --config-json the live Laravel configuration is used.
 * --config-json feeds a plain {"env": "...", "config": {"app.debug": ...}}
 * payload instead (used by tests and offline runs).
 *
 * Exit codes: 0 clean (or warnings only), 1 violation in production, 2 usage error.
 */

const EXIT_OK = 0;
const EXIT_CHECK = 1;
const EXIT_USAGE = 2;

/**
 * @param array<string, mixed> $config flat dot-path values, missing keys are null
 *
 * @return array<int, array{check: string, message: string}>
 */
function config_security_findings(array $config): array
{
    $findings = [];

    $add = static function (string $check, string $message) use (&$findings): void {
        $findings[] = ['check' => $check, 'message' => $message];
    };

    if (($config['app.debug'] ?? null) !== false) {
        $add('app.debug', 'debug mode must be disabled in production');
    }

    if (array_path_is_blank($config['app.key'] ?? null)) {
        $add('app.key', 'application key must be set');
    }

    if (($config['session.http_only'] ?? null) !== true) {
        $add('session.http_only', 'session cookies must be http-only');
    }

    if (($config['session.secure'] ?? null) !== true) {
        $add('session.secure', 'session cookies must be secure-only');
    }

    if (! in_array($config['session.same_site'] ?? null, ['lax', 'strict'], true)) {
        $add('session.same_site', 'session cookies must set same_site to lax or strict');
    }

    $credentials = ($config['cors.supports_credentials'] ?? null) === true;
    $origins = $config['cors.allowed_origins'] ?? [];
    if ($credentials && in_array('*', (array) $origins, true)) {
        $add('cors.allowed_origins', 'CORS must not combine wildcard origin with credentials');
    }

    return $findings;
}

/**
 * Laravel-profile checks (03 §28): opinionated framework settings, active
 * only with --profile=laravel so the default gate stays portable.
 *
 * @param array<string, mixed> $config flat dot-path values
 *
 * @return array<int, array{check: string, message: string}>
 */
function config_security_profile_laravel(array $config): array
{
    $findings = [];
    $add = static function (string $check, string $message) use (&$findings): void {
        $findings[] = ['check' => $check, 'message' => $message];
    };

    if (($config['cache.default'] ?? null) === 'array') {
        $add('cache.default', 'array cache driver must not serve production traffic');
    }
    if (($config['queue.default'] ?? null) === 'sync') {
        $add('queue.default', 'sync queue driver blocks workers — use a real connection in production');
    }
    if (in_array($config['session.driver'] ?? null, ['array'], true)) {
        $add('session.driver', 'array session driver loses state between requests');
    }
    $cipher = (string) ($config['app.cipher'] ?? 'aes-256-cbc');
    if (! in_array($cipher, ['aes-128-cbc', 'aes-256-cbc', 'aes-128-gcm', 'aes-256-gcm'], true)) {
        $add('app.cipher', sprintf('unsupported app cipher "%s"', $cipher));
    }

    return $findings;
}

function array_path_is_blank(mixed $value): bool
{
    return $value === null || $value === '' || $value === false;
}

/**
 * Violations block in production; anywhere else they degrade to warnings.
 *
 * @param array<string, mixed> $config
 *
 * @return array<int, array{severity: string, check: string, message: string}>
 */
function config_security_evaluate(array $config, string $env, array $extraFindings = []): array
{
    $enforcing = $env === 'production';

    $findings = array_merge(config_security_findings($config), $extraFindings);

    return array_map(static function (array $finding) use ($enforcing): array {
        return [
            'severity' => $enforcing ? 'violation' : 'warning',
            'check' => $finding['check'],
            'message' => $finding['message'],
        ];
    }, $findings);
}

/**
 * @return array<string, mixed>
 */
function config_security_load_json(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false || ! is_array(json_decode($raw, true))) {
        fwrite(STDERR, sprintf('unable to read valid JSON from %s%s', $path, PHP_EOL));

        exit(EXIT_USAGE);
    }

    return json_decode($raw, true);
}

$options = ['format' => 'text', 'config-json' => null, 'env' => null, 'profile' => null];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(format|config-json|env|profile)=(.+)$/', $arg, $m) === 1 && array_key_exists($m[1], $options)) {
        $options[$m[1]] = $m[2];

        continue;
    }
    if (in_array($arg, ['--help', '-h'], true)) {
        fwrite(STDERR, 'usage: config-security.php [--format=text|json] [--config-json=file] [--env=name] [--profile=laravel]'.PHP_EOL);

        exit(EXIT_USAGE);
    }
    fwrite(STDERR, sprintf('unknown argument: %s%s', $arg, PHP_EOL));

    exit(EXIT_USAGE);
}

if ($options['config-json'] !== null) {
    $payload = config_security_load_json($options['config-json']);
    $env = (string) ($payload['env'] ?? '');
    $values = is_array($payload['config'] ?? null) ? $payload['config'] : [];
} else {
    $root = baseline_consumer_root() ?? dirname(__DIR__, 2);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $env = $options['env'] ?? (string) config('app.env', '');
    $values = [
        'app.debug' => config('app.debug'),
        'app.key' => config('app.key'),
        'session.http_only' => config('session.http_only'),
        'session.secure' => config('session.secure'),
        'session.same_site' => config('session.same_site'),
        'cors.supports_credentials' => config('cors.supports_credentials'),
        'cors.allowed_origins' => config('cors.allowed_origins'),
        ...(($options['profile'] ?? null) === 'laravel' ? [
            'cache.default' => config('cache.default'),
            'queue.default' => config('queue.default'),
            'session.driver' => config('session.driver'),
            'app.cipher' => config('app.cipher'),
        ] : []),
    ];
}

$results = config_security_evaluate(
    $values,
    $env,
    ($options['profile'] ?? null) === 'laravel' ? config_security_profile_laravel($values) : [],
);
$violations = array_values(array_filter($results, static fn (array $r): bool => $r['severity'] === 'violation'));
$warnings = array_values(array_filter($results, static fn (array $r): bool => $r['severity'] === 'warning'));

if ($options['format'] === 'json') {
    echo json_encode([
        'env' => $env,
        'violations' => count($violations),
        'warnings' => count($warnings),
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    foreach ($results as $result) {
        printf('%s [%s] %s%s', strtoupper($result['severity']), $result['check'], $result['message'], PHP_EOL);
    }
    printf('env=%s: %d violation(s), %d warning(s)%s', $env, count($violations), count($warnings), PHP_EOL);
}

exit($violations === [] ? EXIT_OK : EXIT_CHECK);
