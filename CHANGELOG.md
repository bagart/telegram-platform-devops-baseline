# Changelog

## 0.1.0 (2026-08-25)
- Initial extraction from the telegram-bot-platform baseline (RFC `todo.baseline-package.md`).
- Engine: lib/ shell infrastructure, controls/, hooks/, bin/ entry points.
- BASELINE_DIR / REPO_ROOT path decoupling; composer-deps profile detection with `.baseline-profiles.json` overrides.
- Consumer policy resolution: override in `tools/baseline/<name>` wins over package `defaults/<name>`.
