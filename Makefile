.PHONY: test test-rebuild build down lint lint-fix static-analysis quality help

# Show available targets
help:
	@echo "Available targets:"
	@echo "  test            - Run the test suite (downloads WP + test suite on first run)"
	@echo "  test-rebuild    - Rebuild the test image and run tests"
	@echo "  build           - Build the test image"
	@echo "  down            - Tear down containers and volumes"
	@echo "  lint            - Run PHP CodeSniffer linting"
	@echo "  lint-fix        - Auto-fix PHP CodeSniffer issues where possible"
	@echo "  static-analysis - Run PHPStan static analysis"
	@echo "  quality         - Run all code quality checks (lint + mess detector + static analysis)"
	@echo "  help            - Show this help message"

# Run the test suite (downloads WP + test suite on first run)
test:
	docker compose run --rm tests

# Rebuild the test image and run tests
test-rebuild: build test

# Build the test image
build:
	docker compose build tests

# Tear down containers and volumes
down:
	docker compose down -v

# Run PHP CodeSniffer linting
lint:
	composer lint

# Auto-fix PHP CodeSniffer issues where possible
lint-fix:
	composer lint-fix

# Run PHPStan static analysis
static-analysis:
	composer static-analysis

# Run all code quality checks
quality:
	composer quality

# Create a distributable ZIP of the plugin (excludes tests, vendor and dev files)
.PHONY: package dist zip
package dist zip:
	@echo "Building paysentinel.zip..."
	@rm -f paysentinel.zip
	@zip -r paysentinel.zip . \
		-x "tests/*" "vendor/*" ".*" "Makefile" "docker-compose.yml" "Dockerfile.tests" "*.md" "phpcs.xml" "phpmd.xml" "phpstan.neon" "phpunit.xml" "codeception.yml" "run-tests.sh" "install-wp-tests.sh" "composer.*" "docs/*" "examples/*" "package.json" "*.cache" "*.zip"
	@echo "Created paysentinel.zip"