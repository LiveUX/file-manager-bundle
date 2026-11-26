# LiveUX FileManagerBundle — AI Agent Rules

These rules apply whenever you read, modify, test, or extend files inside `liveux/file-manager-bundle`.

## Architecture & Zero-Dependency Principles
- **Zero CSS Framework Dependency:** Never rely on Bootstrap, Tailwind, DaisyUI, or Flowbite utility classes inside bundle Twig templates or JS. All styling lives in `assets/styles/core.css` under scoped `.liveux-fm-*` classes and `--liveux-fm-*` CSS custom properties.
- **Zero Lock-In for Optional Bridges:** Optional integrations (`EasyAdmin`, `Symfony AI`, `AWS S3`, `Imagick`, `GD`, `ext-zip`) must remain strictly opt-in. Guard optional classes with `class_exists()` / nullable DI injection so minimal Symfony apps boot cleanly.
- **Backward Compatibility & Multi-Version Symfony Support:** Keep compatibility with PHP `>= 8.3` and Symfony `^6.4 | ^7.2 | ^8.0`. In tests, use PHPUnit `createStub()` / `createMock()` for `AuthorizationCheckerInterface` (Symfony 8 added a 3rd optional `?AccessDecision` parameter to `isGranted()`).

## PHP & Code Quality Standards
- Every PHP file under `src/`, `tests/`, and `config/` MUST start with `declare(strict_types=1);`.
- Use strict comparisons (`===`, `!==`) and wrap every control structure in braces (`if ($x) { return; }`).
- Break complex methods into small, focused `private` helper methods to keep Cyclomatic Complexity low.
- Prefer `readonly` classes/properties and constructor property promotion.
- Never import `DateMalformedStringException`; document generic `@throws \Exception` when needed.

## Non-Negotiable Security Invariants
- **Twig:** Never use `|raw` in `templates/`. Custom icons must flow through `liveux_fm_icon()` (`IconSetRegistry` + `SvgSanitizer`).
- **JavaScript / DOM:** Never use `eval`, `new Function`, `document.write`, `insertAdjacentHTML`, or inline `on*=` attributes. Escape every dynamic string via `escapeHtml()` / `escapeAttr()`, validate every rendered URL via `isSafeUrl()` / `isSafeHttpOrPathUrl()`, and pair every `target="_blank"` with `rel="noopener noreferrer"`.
- **Controllers:** Validate `CsrfRequestValidator::assertValid()` BEFORE processing uploaded files or payloads on every state-mutating POST route. Always normalize paths with `PathNormalizer::normalize()` and guard with `FileSecurityGuard::assertAccessiblePath()`.
- **Private & Voter Cache Isolation:** Never emit `Cache-Control: public` or `s-maxage` for private volumes or Voter-protected profiles. Emit `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, and `Cache-Control: private` / `no-store`.
- **Per-Path Authorization:** Bulk operations (ZIP download, move, delete) must verify Voter permissions and accessible-path checks for every individual target path.

## Localization & Assets
- Keep all 12 built-in translation catalogs (`translations/LiveUXFileManagerBundle.{en,tr,fr,de,es,ar,it,pt,nl,pl,ja,zh_CN}.yaml`) in 100% key, non-empty value, and `%placeholder%` parity (enforced by `TranslationParityTest`).
- Never hardcode user-visible UI strings inside Stimulus JS controllers; expose keys via `BrowserComponent::getI18nJson()` and read with `this.t('key')`.
- After editing any JS or CSS file under `assets/`, rebuild `assets/dist/` with `npm --prefix assets run build` (`composer assets:build`) and verify with `composer assets:check`. In host Symfony apps with compiled `public/assets/`, also run `bin/console asset-map:compile`.

## Verification Gate (Required Before Finishing Changes)
Run all relevant checks before reporting work as complete:
1. `composer test` (or `php vendor/bin/phpunit`)
2. `composer assets:check`, `composer test:js:syntax`, and `composer test:js` (`node --test tests/Js/dom_security_test.mjs tests/Js/frontend_audit_test.mjs`)
3. `composer cs:check` and `composer stan` (when dev tools are installed)
4. `composer validate --strict`
