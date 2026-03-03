# Contributing to PaySentinel

Thank you for your interest in contributing to PaySentinel! This document provides guidelines and instructions for contributing to the project.

## Table of Contents

- [Getting Started](#getting-started)
- [Development Environment Setup](#development-environment-setup)
- [Code Standards](#code-standards)
- [Testing Requirements](#testing-requirements)
- [Submitting Changes](#submitting-changes)
- [Code Review Process](#code-review-process)
- [License](#license)

## Getting Started

Before you start, please:

1. Fork the repository on GitHub: https://github.com/caplaz/paysentinel-woocommerce
2. Clone your fork: `git clone https://github.com/YOUR-USERNAME/paysentinel-woocommerce.git`
3. Create a new branch for your feature/fix: `git checkout -b feature/your-feature-name`
4. Set up your development environment (see below)

## Development Environment Setup

### Prerequisites

- PHP 7.4 or higher
- Docker & Docker Compose
- WordPress development plugins knowledge
- WooCommerce plugin familiarity

### Local Development with Docker

1. **Install wp-env** (WordPress development environment):

   ```bash
   npm install -g @wordpress/env
   ```

2. **Start the local environment**:

   ```bash
   wp-env start
   ```

   This starts:
   - WordPress 6.8 + WooCommerce 9.5
   - MySQL 8.0 database
   - Development environment at `http://localhost:8888`

3. **Enable HPOS** (High-Performance Order Storage):

   ```bash
   wp-env run cli wp option update woocommerce_custom_orders_table_enabled yes
   ```

4. **Install dependencies**:

   ```bash
   composer install
   npm install
   ```

5. **Build assets**:
   ```bash
   npm run build
   ```

### Accessing the Environment

- **WordPress Admin**: `http://localhost:8888/wp-admin`
- **Default Credentials**: username: `admin`, password: `password`
- **Test Endpoint**: `http://localhost:8888/wp-json/paysentinel/v1/health`

### Running Tests

```bash
# Run all tests
make test

# Run specific test file
make test-file file=tests/alerts/AlertSeverityLogicTest.php

# Run with coverage
make test-coverage
```

### Stopping the Environment

```bash
wp-env stop
```

### Cleaning Up

```bash
wp-env destroy
```

## Code Standards

PaySentinel follows WordPress Coding Standards and modern PHP best practices.

### PHP Standards

1. **Formatting**:
   - Use 4 spaces for indentation (no tabs)
   - Maximum line length: 100 characters where reasonable
   - Follow PSR-12 guidelines adapted for WordPress

2. **Naming Conventions**:
   - Classes: `class_name` format → `class-paysentinel-feature.php`
   - Functions: `paysentinel_feature_action()` (snake_case with plugin prefix)
   - Constants: `PAYSENTINEL_CONSTANT_NAME` (UPPERCASE_SNAKE_CASE)
   - Variables: `$my_variable_name` (snake_case)

3. **Class Structure**:

   ```php
   /**
    * Class Description
    *
    * @package PaySentinel
    */
   class PaySentinel_Feature {
       // Properties
       // Constructor
       // Public methods
       // Protected methods
       // Private methods
   }
   ```

4. **Documentation**:
   - All classes need PHPDoc blocks
   - All public methods need PHPDoc blocks
   - Use `@param`, `@return`, `@throws` annotations
   - Example:
     ```php
     /**
      * Checks payment gateway connectivity
      *
      * @param string $gateway Gateway ID to check.
      * @return bool True if connected, false otherwise.
      * @throws PaySentinel_Exception If connectivity check fails.
      */
     public function check_connectivity( $gateway ) {
         // Implementation
     }
     ```

5. **Security**:
   - Always sanitize user input: `sanitize_text_field()`, `sanitize_key()`
   - Always escape output: `esc_html()`, `esc_attr()`, `wp_kses_post()`
   - Use nonces for AJAX and form submissions: `wp_nonce_field()`, `wp_verify_nonce()`
   - Never use direct database queries; use `PaySentinel_Database` class

### Code Quality Tools

Run these tools before submitting:

```bash
# WordPress Coding Standards
composer run phpcs

# PHP Mess Detector
composer run phpmd

# PHPStan static analysis
composer run phpstan

# Fix auto-fixable issues with PHPCBF
composer run phpcbf
```

### Strings & Translation (i18n)

All user-facing strings must be translatable:

```php
// Correct
__( 'Payment failed', 'paysentinel' )
_e( 'Processing...', 'paysentinel' )
esc_html__( 'Status', 'paysentinel' )

// Incorrect - DO NOT USE
echo 'Payment failed';
_( 'Status' );
```

Text domain must always be `'paysentinel'`.

## Testing Requirements

### Test Coverage

All new features must include tests. Current coverage:

- **297 unit tests** (all passing)
- **Test-to-Code Ratio**: ~1:3 (good coverage)
- **Critical Paths**: 100% coverage required

### Writing Tests

Tests are in the `tests/` directory, organized by feature:

```
tests/
├── alerts/          # Alert system tests
├── api/             # API endpoint tests
├── core/            # Core functionality tests
├── gateways/        # Payment gateway tests
├── admin/           # Admin interface tests
└── bootstrap/       # Test setup files
```

### Test Structure

```php
<?php
/**
 * Test class description
 */
class MyFeatureTest extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        // Setup test fixtures
    }

    public function tearDown(): void {
        // Cleanup
        parent::tearDown();
    }

    public function test_feature_does_something() {
        // Arrange
        $gateway = 'stripe';

        // Act
        $result = paysentinel_process_payment( $gateway );

        // Assert
        $this->assertTrue( $result );
    }
}
```

### Running Tests

```bash
# Run all tests
make test

# Run specific test class
phpunit --filter MyFeatureTest

# Run with code coverage
phpunit --coverage-html coverage/

# Run only failing tests
phpunit --failed
```

### Test Naming Conventions

- Test files: `FeatureNameTest.php`
- Test methods: `test_what_it_does()`
- Test data files: `fixtures/` directory

Example:

```php
public function test_critical_alert_triggered_on_zero_success_rate() { }
public function test_soft_error_not_counted_as_failure() { }
public function test_gateway_excluded_from_monitoring() { }
```

## Submitting Changes

### Before Submitting

1. **Create a feature branch** from `develop`:

   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make your changes** following code standards above

3. **Write/update tests**:

   ```bash
   make test
   ```

4. **Run code quality checks**:

   ```bash
   composer run phpcs
   composer run phpstan
   ```

5. **Update CHANGELOG.md**:

   ```markdown
   ## [1.0.3] - 2026-03-XX

   ### Added

   - New feature description

   ### Fixed

   - Bug fix description

   ### Changed

   - API change description
   ```

### Commit Messages

Follow conventional commit format:

```
type(scope): subject

body

footer
```

**Types**: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`  
**Scope**: `core`, `admin`, `api`, `alerts`, `gateways`, etc.  
**Subject**: lowercase, imperative, no period

**Examples**:

```
feat(alerts): add critical alert severity level
fix(gateways): exclude offline payment methods from monitoring
docs(contributing): update test setup instructions
test(core): add 11 new gateway detection tests
```

### Pull Request Process

1. **Push your branch** to your fork:

   ```bash
   git push origin feature/your-feature-name
   ```

2. **Create a pull request** on GitHub with:
   - Clear title and description
   - Reference any related issues: `Fixes #123`
   - Checklist completion:
     ```markdown
     - [x] Code follows style guidelines
     - [x] All tests pass locally
     - [x] New tests added for new features
     - [x] Documentation updated
     - [x] No breaking changes (or documented)
     ```

3. **Respond to review feedback** within 48-72 hours

## Code Review Process

### Review Checklist

PRs are reviewed for:

- ✅ **Functionality**: Does it work as intended?
- ✅ **Testing**: Are tests adequate and passing?
- ✅ **Code Quality**: Does it follow standards?
- ✅ **Security**: No vulnerabilities introduced?
- ✅ **Performance**: No degradation?
- ✅ **Documentation**: Clear and complete?
- ✅ **Backward Compatibility**: No breaking changes?

### Review Timeline

- Initial review: Within 24-48 hours
- Feedback response: Within 48-72 hours
- Merge: After approval and all checks pass

### Common Feedback

Common reasons for requesting changes:

1. **Missing tests** - All features need tests
2. **Security issues** - Unescaped output, unsanitized input, missing nonces
3. **Code style** - Not following WordPress standards
4. **Documentation** - Missing PHPDoc or CHANGELOG entry
5. **Breaking changes** - Without deprecation period

## License

By contributing to PaySentinel, you agree that your contributions will be licensed under the **GPL v2 License** (or later).

## Questions?

- Check [DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md) for detailed architecture
- Open an issue for bugs or feature requests
- See [README.md](README.md) for project overview

Thank you for contributing! 🎉

---

**Last Updated**: March 3, 2026  
**Maintained By**: PaySentinel Contributors  
**License**: GPL v2 or later
