# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Plugin Does

PaySentinel is a WooCommerce payment monitoring plugin that tracks payment gateway health, fires alerts on failure spikes, and auto-retries failed payments. It supports multiple license tiers (Free / Starter / Pro / Agency) with feature gating throughout.

## Commands

### Testing
```bash
make test           # Run full test suite in Docker (recommended)
make test-rebuild   # Rebuild Docker image then test

# Or via Composer (requires local PHP environment):
composer test
composer test-coverage   # Generates HTML coverage report in /coverage
```

### Linting & Static Analysis
```bash
make lint            # PHP CodeSniffer (WordPress Coding Standards)
make lint-fix        # Auto-fix PHPCS issues
make quality         # lint + PHPMD + PHPStan (level 9)

# Or via Composer:
composer lint
composer lint-fix
composer mess-detector
composer static-analysis
```

### Packaging
```bash
make package    # Builds paysentinel.zip for distribution (excludes tests, docs, dev config)
```

## Architecture

### Bootstrap Flow
`paysentinel.php` is the entry point. It defines plugin constants, registers a singleton `PaySentinel` class, and on `init` (after WP/WooCommerce version checks) calls `init_components()`, which instantiates all services in dependency order: Config → Database → Logger → Health → Alerts → Retry → Telemetry → License → Gateway Connectivity → REST API → Admin.

### Domain Structure (`includes/`)
| Directory | Purpose |
|-----------|---------|
| `core/` | Foundational services: Config (singleton settings cache), Database (schema), Logger, Health calculator, Retry engine, License tier, Security utilities |
| `gateways/` | Strategy-pattern connectors per gateway (Stripe, PayPal, Square, WC Payments) managed by `PaySentinel_Gateway_Manager` |
| `alerts/` | Alert orchestration — `Alert_Checker` evaluates thresholds, `Alert_Notifier` sends via Email/Slack/Discord/Teams, `Alert_Recovery_Handler` handles retry outcomes |
| `api/` | REST API endpoints under `paysentinel/v1`; all inherit from `PaySentinel_API_Base` which handles auth (`manage_woocommerce` cap) and response formatting |
| `admin/` | MVC-style split: `Admin` (controller), `Menu_Handler`, `Settings_Handler`, `Page_Renderer`, `AJAX_Handler` |
| `utils/` | `Failure_Simulator` for testing alert/retry workflows |

### Database (4 Custom Tables)
- `wp_payment_monitor_transactions` — per-order payment events
- `wp_payment_monitor_gateway_health` — aggregated health metrics by gateway + period (1hr, 24hr, 7day; 30/90day for Pro+)
- `wp_payment_monitor_alerts` — alert log with severity and delivery metadata
- `wp_payment_monitor_gateway_connectivity` — periodic connectivity test results

### Settings
All option keys are constants in `PaySentinel_Settings_Constants`. The `PaySentinel_Config` singleton caches both option groups (`paysentinel_options` for core settings, `paysentinel_settings` for gateway config). Use these constants everywhere — no raw string keys.

### License / Feature Gating
`PaySentinel_License::get_license_tier()` returns the active tier. Feature gates are checked inline throughout the codebase (e.g., `is_retry_feature_available()`). Tier order: Free < Starter < Professional < Agency.

## Code Standards

This plugin passed a full security audit in v1.1.1. Maintain these patterns:
- All `$wpdb` queries must use `$wpdb->prepare()` with `%i` for table/column identifiers
- All output must be escaped: `esc_html()`, `esc_attr()`, `wp_kses()`
- All AJAX/admin form handlers must verify nonces
- All superglobal reads must use `wp_unslash()` + sanitization functions
- Use `wp_parse_url`, `wp_strip_all_tags`, `gmdate` (not the bare PHP equivalents)

PHPCS runs WordPress Coding Standards 3.0 + PHPCompatibilityWP targeting PHP 7.4+. PHPStan runs at level 9.
