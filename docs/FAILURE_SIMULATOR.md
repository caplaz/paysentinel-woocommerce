# Failure Simulator — Architecture & Known Issues

Technical reference for `PaySentinel_Failure_Simulator` (`includes/utils/class-paysentinel-failure-simulator.php`), covering its design, the source-of-truth decision for simulated failure storage, the HPOS bug history, and the regression test strategy.

---

## Table of Contents

- [Purpose](#purpose)
- [How Simulated Failures Work](#how-simulated-failures-work)
- [Storage Architecture](#storage-architecture)
  - [Why the transactions table is the source of truth](#why-the-transactions-table-is-the-source-of-truth)
  - [Why order metadata is unreliable with HPOS](#why-order-metadata-is-unreliable-with-hpos)
- [Bug History: "Cleared 0 simulated orders"](#bug-history-cleared-0-simulated-orders)
  - [Root cause](#root-cause)
  - [Failed approaches](#failed-approaches)
  - [Correct fix](#correct-fix)
- [Activation Fatal Error & Missing Tables](#activation-fatal-error--missing-tables)
- [Key Methods Reference](#key-methods-reference)
- [Regression Tests](#regression-tests)

---

## Purpose

The failure simulator creates realistic-looking WooCommerce payment failures for use in test and staging environments. It covers twelve failure scenarios (card declined, insufficient funds, gateway timeout, fraud detected, etc.) and supports:

- Manually simulating a failure on an existing order (`simulate_failure_for_order`)
- Creating a complete test order with a failure already applied (`create_test_order_with_failure`)
- Generating bulk test failures across gateways (`generate_bulk_failures`)
- Clearing all simulated orders and their transaction records (`clear_simulated_failures`)

---

## How Simulated Failures Work

1. A `WC_Order` is resolved (created fresh or passed in).
2. `$order->update_status('failed', '[SIMULATED FAILURE] ...')` is called. This transitions the order to `failed` and writes an order note containing the `[SIMULATED FAILURE]` prefix string.
3. The `woocommerce_order_status_failed` hook fires, which triggers `PaySentinel_Logger::log_failure()`. The logger extracts the failure reason from the order notes and inserts a row into `wp_payment_monitor_transactions`.
4. **Additionally** (and critically), `upsert_simulated_failure_transaction()` is called directly. This writes/updates the transaction record unconditionally, independent of whether step 3 succeeded.
5. Order metadata (`_paysentinel_simulated_failure`, `_paysentinel_failure_scenario`, etc.) is saved via `$order->add_meta_data()` + `$order->save()`. This is retained for reference but is **not** used to locate simulated orders.

Step 4 exists specifically because step 3 cannot be relied upon in all environments — see [below](#why-order-metadata-is-unreliable-with-hpos).

---

## Storage Architecture

### Why the transactions table is the source of truth

`get_simulated_failure_order_ids()` (private, used by `clear_simulated_failures`) and `get_simulation_stats()` both query **only** `wp_payment_monitor_transactions`:

```sql
SELECT DISTINCT order_id
FROM wp_payment_monitor_transactions
WHERE failure_reason LIKE '[SIMULATED FAILURE]%'
  AND order_id > 0
```

This query works regardless of:

- Whether HPOS is enabled or disabled.
- Whether `wp_woocommerce_orders_meta` exists.
- Whether the `woocommerce_order_status_failed` hook fired correctly.
- Whether `wp_postmeta` was written to.

The `[SIMULATED FAILURE]` prefix in `failure_reason` is the canonical marker. It is set by `simulate_failure_for_order()` and `maybe_simulate_failure()` via both the hook path and the direct upsert path.

### Why order metadata is unreliable with HPOS

When WooCommerce's High Performance Order Storage (HPOS) is enabled, orders are stored in `wp_woocommerce_orders` instead of `wp_posts`. Order metadata goes into `wp_woocommerce_orders_meta` instead of `wp_postmeta`.

However, **the HPOS metadata table (`wp_woocommerce_orders_meta`) is not always created** — for example, on sites that enabled HPOS before ever running the WooCommerce HPOS migration, or on certain hosting stacks that skip the migration step.

When `wp_woocommerce_orders_meta` does not exist:

- `$order->add_meta_data()` + `$order->save()` silently fails — no PHP error is thrown, no `$wpdb->last_error` is set.
- Nothing is written to `wp_postmeta` either (WooCommerce does not fall back).
- All subsequent queries against either metadata table return empty results.

This means any code that locates simulated orders by querying `_paysentinel_simulated_failure` in either metadata table will always return `[]`, making `clear_simulated_failures()` report "Cleared 0" even when simulated orders are present.

---

## Bug History: "Cleared 0 simulated orders"

### Root cause

`get_simulated_failure_order_ids()` originally used `wc_get_orders()` with a metadata filter, then later a two-branch query (try `wp_woocommerce_orders_meta` if HPOS, fall back to `wp_postmeta`). Both approaches failed on any site where HPOS is enabled but `wp_woocommerce_orders_meta` doesn't exist, because metadata was never written in the first place.

The data **was** always present — in `wp_payment_monitor_transactions.failure_reason`. This table is populated by the `woocommerce_order_status_failed` hook+logger chain, which operates entirely independently of the order storage model.

### Failed approaches

| Approach                                                          | Why it failed                                                                                                            |
| ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `wc_get_orders(['meta_key' => '_paysentinel_simulated_failure'])` | metadata never written when HPOS meta table absent                                                                       |
| Direct query of `wp_woocommerce_orders_meta`                      | table doesn't exist → query errors silently                                                                              |
| `wp_postmeta` fallback                                            | HPOS does not write to postmeta; fallback always returns `[]`                                                            |
| `$wpdb->insert($wpdb->postmeta, ...)` direct write hack           | metadata written to postmeta but HPOS reads from `wp_woocommerce_orders_meta`, so the data was still invisible to WC ORM |

### Correct fix

Three code changes were made:

**1. `get_simulated_failure_order_ids()` now queries the transactions table:**

```php
$sql = 'SELECT DISTINCT order_id FROM ' . $table_name
     . ' WHERE failure_reason LIKE %s AND order_id > 0';
$order_ids = $wpdb->get_col( $wpdb->prepare( $sql, '[SIMULATED FAILURE]%' ) );
```

**2. `simulate_failure_for_order()` calls `upsert_simulated_failure_transaction()` directly:**

After `$order->update_status('failed', ...)`, a direct INSERT/UPDATE is performed on the transactions table to ensure the `[SIMULATED FAILURE]` row exists, regardless of whether the hook+logger chain ran successfully. The upsert checks for an existing row first to avoid duplicates.

**3. `maybe_simulate_failure()` was updated the same way.**

---

## Activation Fatal Error & Missing Tables

A related problem: if the plugin's activation hook (`register_activation_hook`) throws a fatal error (e.g. because WooCommerce hasn't loaded yet), `create_tables()` never runs, so `wp_payment_monitor_transactions` never gets created. Every subsequent `$wpdb` call against it silently returns `false`/`[]`, causing both "Cleared 0" and any other feature that reads from transactions to appear broken.

Two defensive measures are in place:

**1. Runtime table creation in `init_components()` (`paysentinel.php`):**

```php
$database = new PaySentinel_Database();
if ( $database->needs_update() ) {
    $database->create_tables();
}
```

`dbDelta` (used internally by `create_tables`) is idempotent — it only adds missing tables/columns and never destroys existing data. Running it on every `init` when the version is stale is safe.

**2. Same guard inside `upsert_simulated_failure_transaction()`:**

```php
if ( $this->database->needs_update() ) {
    $this->database->create_tables();
}
```

Belt-and-suspenders: even if the `init_components()` check was somehow skipped, the first simulate call will self-heal the missing tables.

**3. Try/catch in `activate()` with error logging:**

```php
try {
    // ... create tables, set options, fire action
} catch ( \Throwable $e ) {
    error_log( 'PaySentinel activation error: ' . $e->getMessage() ... );
    throw $e; // Let WP show the activation error notice
}
```

This ensures activation failures are visible in `wp-content/debug.log` instead of producing an opaque blank screen.

---

## Key Methods Reference

| Method                                                       | Visibility | Description                                                                                                                            |
| ------------------------------------------------------------ | ---------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| `simulate_failure_for_order($order, $scenario_key)`          | `public`   | Apply a simulated failure to an existing order. Returns `['success' => bool, 'message' => string]`.                                    |
| `create_test_order_with_failure($scenario_key, $gateway_id)` | `public`   | Create a complete test order and immediately apply a failure. Returns `['success', 'order_id', 'message']`.                            |
| `generate_bulk_failures($count, $gateway_id, $scenarios)`    | `public`   | Create multiple test orders with failures. Returns `['success', 'failed', 'order_ids', 'errors', 'gateways']`.                         |
| `clear_simulated_failures()`                                 | `public`   | Delete all simulated orders and their transaction records. Returns `['success', 'deleted_orders', 'deleted_transactions', 'message']`. |
| `get_simulation_stats()`                                     | `public`   | Aggregate counts from the transactions table. Returns `['total_simulated', 'by_scenario']`.                                            |
| `get_all_scenarios()`                                        | `public`   | Returns the `FAILURE_SCENARIOS` constant array.                                                                                        |
| `is_test_mode_enabled()`                                     | `public`   | Reads `paysentinel_settings[enable_test_mode]`.                                                                                        |
| `get_simulated_failure_order_ids()`                          | `private`  | Queries the transactions table and returns order IDs.                                                                                  |
| `upsert_simulated_failure_transaction($order, $note, $code)` | `private`  | Writes/updates the `[SIMULATED FAILURE]` transaction record directly. Includes `$wpdb->last_error` logging on failure.                 |

---

## Regression Tests

All regression tests live in `tests/integration/FailureSimulatorTest.php`. Key tests:

| Test                                                            | What it guards                                                                                                              |
| --------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `test_simulate_failure_writes_transaction_record`               | Confirms a `[SIMULATED FAILURE]` row is always written to the transactions table, regardless of metadata outcome.           |
| `test_simulate_failure_twice_does_not_duplicate_transaction`    | The second call for the same order must UPDATE, not INSERT (upsert idempotency).                                            |
| `test_non_simulated_failures_are_not_affected`                  | Real failed transactions must never appear in the simulated list and must survive `clear_simulated_failures()`.             |
| `test_clear_simulated_failures_removes_transaction_records`     | After clearing, no `[SIMULATED FAILURE]` rows may remain in the transactions table.                                         |
| `test_simulate_failure_with_invalid_order_id_returns_failure`   | Invalid order ID must return `success => false` without writing any record.                                                 |
| `test_simulate_failure_with_invalid_scenario_returns_failure`   | Invalid scenario key must return `success => false` without writing any record.                                             |
| `test_all_defined_scenarios_can_be_simulated`                   | Every one of the 12 `FAILURE_SCENARIOS` keys must produce a valid `[SIMULATED FAILURE]` transaction record.                 |
| `test_generate_bulk_failures_creates_transaction_records`       | All bulk-generated orders must each have a transaction record.                                                              |
| `test_create_test_order_with_failure_writes_transaction_record` | The full order-creation helper must produce a complete, correct transaction record.                                         |
| `test_clear_simulated_failures_removes_orders`                  | **Original regression test.** `clear_simulated_failures()` on 3 orders must return `deleted_orders = 3`, never `Cleared 0`. |
| `test_clear_simulated_failures_with_various_statuses`           | Simulated orders in non-`failed` statuses (e.g. `pending`) must still be found and cleared.                                 |

### Helper methods

The test class maintains two private helpers that also query the transactions table (not metadata):

- `get_simulated_orders()` — mirrors `get_simulated_failure_order_ids()` for test assertions.
- `cleanup_test_orders()` — deletes both the WC orders and all matching transaction records between tests to prevent data bleed.
