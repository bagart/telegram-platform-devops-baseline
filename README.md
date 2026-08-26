# bagart/telegram-devops-baseline

Reusable DevOps/security baseline **engine** for BAGArt repositories: secret
scanning, LF enforcement, YAML/shell lint, semgrep, commit-message policy,
git hooks, budgeted tests, manifest drift checks.

The package ships executors and defaults. Every repository keeps its own
policy and state (allowlists, budgets, quarantine lists) under
`tools/baseline/`.

## Onboarding a new repo

```bash
composer require --dev bagart/telegram-devops-baseline:@dev
vendor/bin/baseline-setup            # core.hooksPath -> vendor hooks
vendor/bin/baseline-setup --stubs    # + scaffold policy stubs into tools/baseline/
vendor/bin/baseline-check --full     # first golden run
```

Zero copied engine files; per-repo footprint is a few small JSON policy files.

## Entry points

| Command | Purpose |
|---|---|
| `baseline-check [--quick|--full|--ci]` | main gate (exit codes 0–5 per contract.sh) |
| `baseline-security` | SAST + supply-chain controls |
| `baseline-fix` | auto-fixers |
| `baseline-drift` | manifest drift report |
| `baseline-selftest` | engine self-verification |

## Policy resolution

First match wins: consumer `tools/baseline/<name>` → package `defaults/<name>`.

## Profiles

Evidence-based via composer deps (`laravel/framework`, `bagart/php-async-kernel-lib`,
`bagart/telegram-bot-lib`, …). Repositories whose layout hides the evidence add
`.baseline-profiles.json`: `{"profiles": ["async-runtime", "telegram"]}`.
