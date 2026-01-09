.PHONY: test test-rebuild build down help

# Show available targets
help:
	@echo "Available targets:"
	@echo "  test         - Run the test suite (downloads WP + test suite on first run)"
	@echo "  test-rebuild - Rebuild the test image and run tests"
	@echo "  build        - Build the test image"
	@echo "  down         - Tear down containers and volumes"
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