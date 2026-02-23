# Developer Guide

Welcome to the technical documentation for **PaySentinel (PaySentinel - Payment Monitor for WooCommerce)**! This guide is designed for developers who want to extend or maintain the plugin.

## Table of Contents

- [Plugin Architecture](#plugin-architecture)
- [Directory Structure](#directory-structure)
- [Extending the Plugin](#extending-the-plugin)
- [Available Hooks & Filters](#available-hooks--filters)
- [Code Style](#code-style)
- [Running Tests](#running-tests)

---

## Plugin Architecture

The plugin is structured around a classic object-oriented module architecture, following modern WordPress plugin development standards.

- The standard logic resides in the `includes/core` folder.
- The Admin/Dashboard logic resides in `includes/admin`.
- API handlers are available in `includes/api`.
- The database storage schemas and logic are handled via their respective handler classes.

---

## Directory Structure

Here's an overview of the key folders within the `includes` directory:

- **/admin/**: Contains classes responsible for rendering the admin dashboard, settings pages, and registering options.
- **/api/**: Defines and registers custom REST API endpoints logic (e.g. analytics, health, and transactions syncing).
- **/core/**: Contains the core operational classes (like `PaySentinel_Health`, `PaySentinel_Transactions`), responsible for calculations, scheduling, and database operations.
- **/gateways/**: Adapters for specific WooCommerce payment gateways.

---

## Extending the Plugin

The plugin integrates tightly with the WooCommerce ecosystem and adds its own custom tables for precise activity tracking (`wp_payment_monitor_transactions`).

If you're integrating an unlisted payment gateway or a custom notification service, search for action and filter hooks that the system emits during successful or failed transaction captures.

## Available Hooks & Filters

For an up-to-date and complete list of hooks, please search the source code for `do_action` and `apply_filters`. Standard hooks include:

### Actions
- `paysentinel_after_health_check` — Triggered when a health check calculates and commits gateway status to database.
- `paysentinel_transaction_logged` — Triggered whenever a transaction is logged successfully into the custom table.

### Filters
- `paysentinel_alert_threshold` — Allows modifying the dynamic success rate threshold necessary to trigger an alert.
- `paysentinel_is_retriable_error` — Modify the boolean response matching failed transactions that are eligible for retry.

---

## Code Style

This plugin strictly adheres to the **WordPress Coding Standards**, enforcing static analysis and strict PHP formatting.

Before making commits, you should run the Composer lint scripts provided:

```bash
composer lint
composer lint-fix
composer static-analysis
```

You are highly encouraged to write DocBlocks describing the functionality of methods, return types, and expected parameters.

---

## Running Tests

Automated testing is integrated inside the plugin via **PHPUnit** and WP Mock. Check the `tests/` directory for existing test cases.

```bash
composer test
composer test-coverage
```
