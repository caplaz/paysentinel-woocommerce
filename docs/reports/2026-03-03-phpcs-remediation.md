# PHPCS Code Quality Remediation Plan

**Date:** March 3, 2026  
**Plugin:** PaySentinel v1.0.2  
**Purpose:** Fix WordPress Coding Standards violations for WordPress.org submission

---

## Overview

The PaySentinel codebase has **~900 PHPCS violations**, but **0 are security-related**. The violations are:

- **850 Documentation Issues** (missing doc comments, improper punctuation)
- **40 Code Quality Issues** (rand() → wp_rand() conversions)
- **10 Formatting Issues** (spacing, alignment)

**Impact:** ⚠️ **Blocks WordPress.org submission** but ✅ **Does NOT affect functionality or security**

---

## Violation Categories

### 1. Missing File Doc Comments (~150 violations)

**Severity:** Low | **Fix:** Add 1-2 lines per file

Missing file-level documentation block at the beginning of PHP files.

**Example:**

```php
❌ WRONG
<?php
class My_Class { }

✅ CORRECT
<?php
/**
 * Brief file description.
 *
 * @package PaySentinel
 */

class My_Class { }
```

**Files affected:** ~30 files (includes/**, tests/**)

**Fix time:** ~30 minutes

**Command:** Manual edit - add template to top of each file

### 2. Missing Class Doc Comments (~150 violations)

**Severity:** Low | **Fix:** Add 2-3 lines per class

Missing class-level documentation with `@package` tag.

**Example:**

```php
❌ WRONG
class PaySentinel_Alert_Checker {
    public function check() { }
}

✅ CORRECT
/**
 * Checks payment alerts.
 *
 * @package PaySentinel
 * @since   1.0.0
 */
class PaySentinel_Alert_Checker {
    public function check() { }
}
```

**Files affected:** ~30 classes across codebase

**Fix time:** ~1 hour

---

### 3. Missing Function/Method Doc Comments (~200 violations)

**Severity:** Medium | **Fix:** Add `@param`, `@return` tags

Missing documentation for public/protected methods.

**Example:**

```php
❌ WRONG
public function get_gateway_health( $gateway_id ) {
    return $this->health[ $gateway_id ];
}

✅ CORRECT
/**
 * Get health status for a gateway.
 *
 * @param string $gateway_id The gateway identifier.
 * @return array|null Gateway health data or null.
 * @since 1.0.0
 */
public function get_gateway_health( $gateway_id ) {
    return $this->health[ $gateway_id ];
}
```

**Files affected:** ~50+ files with public methods

**Fix time:** ~2 hours

---

### 4. Inline Comment Punctuation (~450 violations) 📝

**Severity:** Low | **Fix:** Add periods to comments

Inline comments must end with punctuation: `.` `!` `?`

**Example:**

```php
❌ WRONG
// check if gateway is active
// verify nonce is valid

✅ CORRECT
// Check if gateway is active.
// Verify nonce is valid.
```

**Files affected:** 25+ files

**Fix time:** ~1.5 hours with find/replace

**Automated Fix:**

```bash
# Find: // ([A-Za-z][^.!?\n]*)$
# Replace: // $1.
# Regex enabled
```

---

### 5. rand() vs wp_rand() (~40 violations)

**Severity:** Medium | **Fix:** Replace function calls

WordPress security: `rand()` is predictable; use `wp_rand()` instead.

**Example:**

```php
❌ WRONG
$random_value = rand( 1, 100 );
$array_value = $items[ rand( 0, count( $items ) - 1 ) ];

✅ CORRECT
$random_value = wp_rand( 1, 100 );
$array_value = $items[ wp_rand( 0, count( $items ) - 1 ) ];
```

**Files affected:** tests/api/APIPaginationPropertyTest.php (18 instances)

**Fix time:** ~30 minutes

**Command:**

```bash
# Simple replace in affected file
sed -i 's/rand(/wp_rand(/g' tests/api/APIPaginationPropertyTest.php
```

---

### 6. Missing Property Doc Comments (~50 violations)

**Severity:** Low | **Fix:** Add `@var` tags

Class properties need documentation.

**Example:**

```php
❌ WRONG
class PaySentinel_Alert_Checker {
    protected $database;
    protected $logger;
}

✅ CORRECT
/**
 * Checks payment alerts.
 *
 * @package PaySentinel
 */
class PaySentinel_Alert_Checker {
    /**
     * Database instance.
     *
     * @var PaySentinel_Database
     */
    protected $database;

    /**
     * Logger instance.
     *
     * @var PaySentinel_Logger
     */
    protected $logger;
}
```

---

## Remediation Strategy

### Phase 1: Automated Fixes (15-30 minutes)

✅ **Inline comment punctuation** - Use PHPCBF (already done once)
✅ **rand() → wp_rand()** - Bulk replace in test file
✅ **Basic formatting** - Run PHPCBF again

**Command:**

```bash
cd /Users/ace/Projects/WP/sentinel
make lint-fix
```

**Expected result:** Fix 50-100 additional violations automatically

### Phase 2: File-Level Documentation (30 minutes)

Add template to ~30 files:

```php
<?php
/**
 * Brief description of what's in this file.
 *
 * Longer description if needed, explaining the purpose and main classes/functions.
 *
 * @package PaySentinel
 * @since   1.0.0
 */
```

**Files to update:**

- All files in `includes/` directory
- Test files in `tests/` directory

### Phase 3: Class Documentation (1-2 hours)

Add doc blocks to all classes with:

- Brief description
- `@package PaySentinel`
- `@since 1.0.0`
- Class purpose explanation

**Template:**

```php
/**
 * Describes what this class does.
 *
 * Longer explanation of functionality, key methods, and purpose.
 *
 * @package PaySentinel
 * @since   1.0.0
 */
class PaySentinel_Feature {
    // ...
}
```

### Phase 4: Method/Function Documentation (2-3 hours)

Add doc blocks to all public/protected methods with:

- Brief method description
- `@param` tags for each parameter
- `@return` tag with return type
- `@throws` for exceptions
- `@since` version tag

**Template:**

```php
/**
 * Brief description of method purpose.
 *
 * Longer explanation if method behavior is complex or has side effects.
 *
 * @param string $parameter_name Description of parameter.
 * @return array|bool Return value description and type.
 * @throws PaySentinel_Exception If something goes wrong.
 * @since 1.0.0
 */
public function method_name( $parameter_name ) {
    // ...
}
```

### Phase 5: Verification (30 minutes)

Run PHPCS to verify all violations are fixed:

```bash
make lint
```

**Expected:** 0 ERRORS, ~0-10 warnings max

---

## Priority Files (Start Here)

These files have the most violations and will have the biggest impact:

| File                                            | Violations | Est. Time |
| ----------------------------------------------- | ---------- | --------- |
| includes/core/class-paysentinel-retry.php       | 142        | 45 min    |
| includes/api/class-paysentinel-api-health.php   | 64         | 35 min    |
| includes/core/class-paysentinel-diagnostics.php | 65         | 40 min    |
| includes/core/class-paysentinel-logger.php      | 81         | 50 min    |
| includes/core/class-paysentinel-license.php     | 85         | 50 min    |
| paysentinel.php                                 | 41         | 25 min    |
| includes/core/class-paysentinel-database.php    | 48         | 30 min    |
| includes/core/class-paysentinel-health.php      | 68         | 40 min    |
| includes/core/class-paysentinel-security.php    | 67         | 40 min    |

**Total for top 9 files:** ~325 violations, ~415 minutes (~7 hours)

---

## Implementation Checklist

### Step 1: Setup

- [ ] Clone paysentinel repository
- [ ] Install PHPCS: `composer install`
- [ ] Run: `make lint` to see current state

### Step 2: Automated Fixes

- [ ] Run: `make lint-fix` to auto-fix what PHPCBF can
- [ ] Check results: `make lint | grep "FOUND"`
- [ ] Commit changes: `git commit -m "style: auto-fix phpcs violations with phpcbf"`

### Step 3: File Documentation

- [ ] Add file doc comments to `includes/` files (30 min)
- [ ] Add file doc comments to `tests/` files (15 min)
- [ ] Commit: `git commit -m "docs: add file-level PHP doc comments"`

### Step 4: Class Documentation

- [ ] Review `includes/core/` classes (15 classes × 3 min = 45 min)
- [ ] Review `includes/admin/` classes (5 classes × 3 min = 15 min)
- [ ] Review `includes/api/` classes (6 classes × 3 min = 18 min)
- [ ] Review `includes/alerts/` classes (4 classes × 3 min = 12 min)
- [ ] Review `includes/gateways/` classes (6 classes × 3 min = 18 min)
- [ ] Commit: `git commit -m "docs: add class-level doc comments with @package tags"`

### Step 5: Method Documentation

- [ ] Document public methods in core classes (1.5 hours)
- [ ] Document public methods in admin classes (45 min)
- [ ] Document public methods in API classes (1 hour)
- [ ] Document public methods in alerts/gateways (1 hour)
- [ ] Commit: `git commit -m "docs: add comprehensive method documentation"`

### Step 6: Final Verification

- [ ] Run: `make lint` verify no errors
- [ ] Run: `make test` verify tests still pass
- [ ] Run: `make static-analysis` verify no regressions
- [ ] Create PR for review

---

## Tools & Resources

### Automated Tools

```bash
# Fix auto-fixable issues
make lint-fix

# Run static analysis
make static-analysis

# Run full quality check
make quality

# View violations by file
make lint 2>&1 | grep "FOUND"
```

### Manual Reference

- **Doc Comment Standards:** https://developer.wordpress.org/plugins/coding-standards/
- **PHP DocBlox Format:** https://docs.phpdoc.org/3.0/guide/

### GitHub Workflow

```bash
git checkout -b fix/phpcs-violations
# ... make changes ...
git add .
git commit -m "docs: fix phpcs violations"
git push origin fix/phpcs-violations
# Create PR for review
```

---

## Estimated Timeline

| Phase | Tasks                  | Time | Cumulative |
| ----- | ---------------------- | ---- | ---------- |
| 1     | Automated fixes        | 0.5h | 0.5h       |
| 2     | File documentation     | 0.5h | 1h         |
| 3     | Class documentation    | 1.5h | 2.5h       |
| 4     | Method documentation   | 4.5h | 7h         |
| 5     | Verification & testing | 0.5h | 7.5h       |

**Total Estimated Time:** ~7-8 hours

**Can be parallelized:** Changes are independent, multiple contributors can work simultaneously

---

## Success Criteria

✅ All items complete when:

1. `make lint` produces 0 ERRORS
2. `make test` produces 0 FAILURES
3. All 297 tests pass
4. `make quality` produces clean output
5. All doc comments follow WordPress standards

---

## Notes

- **Security:** No security improvements needed - this is pure documentation
- **Functionality:** No functional changes - documentation only
- **Testing:** Existing tests remain unchanged; all should still pass
- **Performance:** No performance impact
- **Backward Compatibility:** 100% compatible

---

## Next Steps

1. **Immediate:** This documentation phase
2. **Week 1:** Implement automated fixes (Phase 1-2)
3. **Week 2:** Manual documentation (Phase 3-5)
4. **Submit:** Ready for WordPress.org submission after PHPCS cleanup

---

**Created:** March 3, 2026  
**Status:** Ready to implement  
**Owner:** PaySentinel Team
