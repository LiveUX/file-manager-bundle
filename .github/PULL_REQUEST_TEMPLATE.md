<!--
Thank you for contributing to LiveUX FileManagerBundle!
Please fill out the Symfony-style summary table and checklist below before submitting your Pull Request.
-->

| Q | A |
| --- | --- |
| Branch? | `main` |
| Bug fix? | yes / no |
| New feature? | yes / no |
| BC breaks? | no / yes (explain below) |
| Deprecations? | no / yes |
| Tests pass? | yes / no |
| Fixed tickets | `#...` (if applicable) |
| License | MIT |
| Doc PR | (link or "included in this PR") |

## Summary

<!-- Explain WHAT changed and WHY. Include screenshots or a short GIF if the UI changed. -->

## Contributor Checklist

Please verify every applicable item before marking your PR ready for review:

- [ ] **Code Quality:** All new/modified PHP files include `declare(strict_types=1);`, use strict comparisons (`===` / `!==`), and follow PSR-12 / Symfony coding standards (`composer cs:check`).
- [ ] **Static Analysis:** `composer stan` (PHPStan) passes with zero new errors.
- [ ] **PHP Tests:** `composer test` (PHPUnit) passes, including new unit/regression tests covering this change.
- [ ] **JavaScript & Security Tests:** `composer test:js:syntax` and `composer test:js` (`dom_security_test.mjs`) pass.
- [ ] **Strict CSP & XSS Safety:** Zero inline `on*=` attributes, zero `|raw` filters in Twig, all dynamic URLs validated with `isSafeUrl()`, and all external links include `rel="noopener noreferrer"`.
- [ ] **Zero Framework Lock-In:** All new UI styles live in `assets/styles/core.css` under `.liveux-fm-*` variables and work in both light and dark modes without requiring Bootstrap or Tailwind.
- [ ] **Translations (i18n):** Any new UI string is added to **all** locales in `translations/` (`en`, `tr`, `fr`) and exposed in `BrowserComponent::getI18nJson()` when used by Stimulus.
- [ ] **Documentation & Changelog:** Relevant guides under `docs/` and `CHANGELOG.md` are updated (for features or config changes).
