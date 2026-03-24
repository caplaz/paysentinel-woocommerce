# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.1] - 2026-03-24

### Added
- **Compliance Audit**: Systematic resolution of findings from the WooCommerce.org plugin review team.
- **Improved Database Handling**: Enhanced table existence verification and migration consistency for smoother upgrades.

### Changed
- Replaced discouraged functions (`parse_url`, `strip_tags`, `date`) with WordPress-recommended alternatives (`wp_parse_url`, `wp_strip_all_tags`, `gmdate`).
- Standardized all `$_GET` and `$_POST` input handling using `wp_unslash()` and appropriate sanitization functions.
- Updated all translatable strings with placeholders to include mandatory `/* translators: ... */` comments.

### Fixed
- **XSS Prevention**: Implemented strict output escaping using `esc_html()`, `esc_attr()`, and `wp_kses()` in all admin templates and emails.
- **CSRF Protection**: Strengthened critical administrative actions with robust nonce verification.
- **Interpolated SQL**: Resolved warnings for safe dynamic table name interpolation in database query helpers.

## [1.1.0] - 2026-03-17

### Added

- **Auto-Retry Engine**: Comprehensive automatic payment retry system for soft declines with configurable backoff schedule (default: 1 hour, 6 hours). Starter+ feature.
- **Smart Decline Detection**: Automatic classification of hard declines (fraud, invalid card, expired, etc.) vs soft declines (timeout, insufficient funds) with no retry on permanent failures.
- **Recovery Email**: Automated recovery notifications sent to customers on hard declines when no retry is possible.
- **Analytics Dashboard (PRO)**: New analytics page with ROI tracking, recovery flow visualization, revenue breakdown by gateway, and CSV export for recovery metrics.
- Recovery metrics: transaction counts, success rates per gateway, and recovery email delivery tracking.
- License tier validation: Auto-retry feature enforces Starter+ license requirement; free tier falls back to recovery email only.
- Database schema: Added 'retry_outcome' to alert types for tracking retry recovery notifications.
- Comprehensive test suite: 10 new tests covering license tier enforcement, payment method validation, hard/soft decline detection variations, backoff schedule timing, and recovery flow.

### Changed

- Payment failure handling now delegates to smart retry logic based on license tier and stored payment methods.
- Alert recovery notifications now use database-persisted alert system for better tracking and audit trails.

### Fixed

- Fixed order note retrieval in unit tests to use WordPress standard `get_comments()` with comment_type filter instead of non-existent WooCommerce method.
- Fixed license tier mocking in retry tests to properly enable/disable retry feature based on license status.
- Improved test isolation by properly cleaning up license options in test teardown.

### Docs & Tests

- Added 10 new comprehensive tests for retry logic:
  - License tier validation (free vs pro)
  - Payment method edge cases (no stored token)
  - Failure reason variations (7 hard decline + 5 soft decline keywords + unknown)
  - Backoff schedule timing validation
  - Recovery email flag tracking
  - Retry action scheduling and transaction ID tracking
- Total test coverage now: 325 tests (up from 315), all passing.

## [1.0.2] - 2026-03-07

### Added

- Centralized SaaS alert delivery and per-gateway alert configuration support.
- Database helper to obtain the first transaction date per gateway (used to avoid premature alerts).
- Transaction cleanup for deleted WooCommerce orders.
- HPOS (High-Performance Order Storage) compatibility improvements.

### Changed

- Gateway alert configuration UI: header padding, label styling and improved alignment.
- Packaging moved to `Makefile` and CI updated to use `make package`.
- Notification channels normalized to: Email, Slack, Discord, Teams (SMS removed).

### Fixed

- Various admin link fixes (help & documentation, license links) and UI icon fallbacks.
- CI/test stability fixes (test isolation and setup ordering).

- **Critical**: Fixed "Cleared 0 simulated orders" issue in HPOS environments by replacing insufficient version-only database update check (`needs_update()`) with explicit table existence verification (`tables_exist()`).
- Ensured database tables are created at runtime in `init_components()` and `upsert_simulated_failure_transaction()` to handle cases where activation failed or DB version option was set during partial activation.
- Added diagnostics to surface missing transactions table as an error with self-healing on the next page load.
- Added "Transactions Table Exists" row to admin diagnostics UI for visibility into database schema health.

### Refactor

- Settings handling refactored to use constants for consistency.
- Removed client-side loading of gateways on page load; simplified gateway detection.

### Docs & Tests

- Added compatibility, security audit, contributing and README updates.
- Expanded integration and unit tests (gateway manager detection, failure simulator, alert severity fixes).

## [1.0.1] - 2026-02-24

### Added

- Sidebar menu item renamed to "Remote Dashboard" linking to external site.
- `SIDEBAR_HELP_URL` constant introduced to differentiate sidebar link from in-page help.
- Added spacing between gateway health cards; improved grid margins.
- Time period label removed from gateway health UI for cleaner layout.
- Styling consistency fixes for subtitle text on health page.
- Changelog file created.

### Fixed

- Build script simplified to avoid path issues in CI; `npm run build` now creates ZIP in repo root.
