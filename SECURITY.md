# Security Policy

## Supported Versions

| Version | Supported |
| ------- | --------- |
| `0.1.x` | Yes       |

## Reporting a Vulnerability

File managers handle file uploads, filesystem paths, image decoding, SVG rendering, remote URL imports, and signed downloads — security is a first-class priority in **LiveUX FileManagerBundle**.

**Please do NOT report security vulnerabilities through public GitHub issues or pull requests.**

Instead, please report them privately using one of the following channels:

1. **GitHub Private Security Advisories** (preferred): Go to the repository's **Security -> Advisories -> Report a vulnerability** page.
2. **Direct Maintainer Contact:** Contact the maintainer directly via the profile links listed in `composer.json`.

### What to Include
- Description of the vulnerability and potential impact (e.g., Path Traversal, XSS/CSP bypass, SSRF, CSRF bypass, ZIP Slip, Pixel Bomb, SVG injection).
- Minimal proof-of-concept steps or payload.
- Affected bundle version, PHP version, Symfony version, and storage driver (`LocalFileSystem`, `AwsS3`, etc.).

### Response Timeline
- **Acknowledgement:** Within 48 hours.
- **Triage & Fix Plan:** Within 5 business days.
- **Coordinated Disclosure:** A patched release + regression test in `tests/Unit/Security/` and/or `tests/Js/dom_security_test.mjs` will be published before public disclosure.
