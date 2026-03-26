# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.2] - 2026-03-26

### Fixed
- Added nonce verification (`wp_verify_nonce`) to all admin flash-message displays that read `$_GET['message']` and `$_GET['type']`, and included `_wpnonce` in all corresponding `wp_safe_redirect()` calls to fully address `NonceVerification.Recommended` warnings.
- Suppressed `PluginCheck.Security.DirectDB.UnescapedDBParameter` and `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` on dynamically-built but properly parameterised queries in `class-paysentinel-api-alerts.php` and `class-paysentinel-api-transactions.php`.
- Added `WordPress.DB.DirectDatabaseQuery.NoCaching` to existing phpcs:ignore in `class-paysentinel-failure-simulator.php`.
- Consolidated standalone `phpcs:ignore` lines into inline comments on `return`/assignment statements across `class-paysentinel-health.php`, `class-paysentinel-alerts.php`, `class-paysentinel-logger.php`, `class-paysentinel-security.php`, `class-paysentinel-database.php`, and `class-paysentinel-diagnostics.php` to ensure PHPCS correctly suppresses `DirectQuery` and `NoCaching` warnings.

### Changed
- Replaced `current_time('timestamp')` with `time()` throughout the codebase (`class-paysentinel-health.php`, `class-paysentinel-license.php`, `class-paysentinel-analytics-pro.php`, `class-paysentinel-alerts.php`, `class-paysentinel-alert-checker.php`).
- Renamed reserved-keyword function parameters (`$default` → `$default_value`, `$array` → `$input_array`, `$string` → `$input_string`) in `class-paysentinel-config.php`, `class-paysentinel-admin.php`, `class-paysentinel-api-base.php`, and `class-paysentinel-security.php`.
- Added strict third argument (`true`) to all `in_array()` calls.
- Changed loose `==` comparisons to strict `===` in `class-paysentinel-health.php` and `class-paysentinel-retry.php`.
- Registered `manage_woocommerce` as a known capability in `phpcs.xml` to suppress false-positive `WordPress.WP.Capabilities.Unknown` warnings.
- Removed all commented-out `error_log()` debug blocks from production source files.
- Added `@var`, `@param`, `@throws`, and class/file docblocks missing across source files to meet WordPress Coding Standards.
- Merged unnecessary string concatenations and removed unused variables across source files.

## [1.1.1] - 2026-03-24

### Security (Audit Compliance)
- Resolved all findings from the WordPress.org plugin review team audit.
- Refactored all direct database queries to use strict `$wpdb->prepare()` parameterization to prevent SQL injection.
- Eliminated raw `$table_name` string interpolations via secure `%i` identifiers.
- Standardized all `$_GET` and `$_POST` array interactions with `wp_unslash()` and strict sanitization.
- Fixed XSS vulnerabilities with strict output escaping (`esc_html()`, `esc_attr()`, and `wp_kses()`) in all templates and components.
- Added robust CSRF nonce verification in all admin and AJAX endpoint handlers.
- Addressed `OffloadedContent` security warnings safely for required external payment gateway scripts.

### Changed
- Replaced discouraged functions (`parse_url`, `strip_tags`, `date`) with strict WordPress-recommended alternatives (`wp_parse_url`, `wp_strip_all_tags`, `gmdate`).
- Cleaned up and consolidated `phpcs:ignore` suppression blocks for custom database table operations to remove redundancies and inline queries effectively.
- Updated "Tested up to" compatibility header accurately to WordPress 6.9.

### Fixed
- Added missing translator `/* translators: %s */` comments for all i18n string placeholders.
- Improved database table existence verification in failure simulator execution blocks.

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
