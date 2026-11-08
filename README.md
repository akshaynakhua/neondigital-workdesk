# NeonDigital WorkDesk — Community Source Snapshot

This repository contains the WordPress plugin and theme source code for an early community release. It is **not independently security-audited or production-certified**. Do not deploy with real customer data without your own review.

## Contents

- `plugin/` — WordPress plugin source
- `theme/` — WordPress theme source
- `CONTRIBUTING.md` — contribution guide
- `LICENSE` — GPL version 2 or later

## Local installation

1. Set up a new, isolated WordPress development instance with a fresh database.
2. Copy the plugin directory into `wp-content/plugins/` and the theme directory into `wp-content/themes/`.
3. Activate the plugin, then the theme, in the WordPress admin dashboard.
4. Configure integrations only with sandbox/test credentials. Never use production secrets or live client data in development.
5. Review code, dependencies, and WordPress/PHP compatibility before deploying.

## Limitations

This snapshot is supplied as source for community review. Integration configuration, private operational documents, test fixtures, historical release notes and brand artwork have been excluded from this public package. The original project may depend on external services or environment-specific settings. No functional or security guarantee is made.

## Reporting security issues

Do not publish exploit details or credentials in GitHub issues. Contact the project maintainer privately using their GitHub profile.

## Licence

GPL-2.0-or-later for original project code, subject to verification of bundled third-party components and their respective licences.
