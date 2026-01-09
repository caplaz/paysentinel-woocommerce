.PHONY: test test-rebuild build down lint lint-fix quality help

# Show available targets
help:
	@echo "Available targets:"
	@echo "  test         - Run the test suite (downloads WP + test suite on first run)"
	@echo "  test-rebuild - Rebuild the test image and run tests"
	@echo "  build        - Build the test image"
	@echo "  down         - Tear down containers and volumes"
	@echo "  lint         - Run PHP CodeSniffer linting"
	@echo "  lint-fix     - Auto-fix PHP CodeSniffer issues where possible"
	@echo "  quality      - Run all code quality checks (lint + mess detector)"
	@echo "  help         - Show this help message"

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

# Run all code quality checks
quality:
	composer quality