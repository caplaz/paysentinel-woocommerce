#!/bin/bash
# Simple test runner that runs tests without PHPUnit config parsing issues

docker compose run --rm tests bash -lc "
bash ./tests/bootstrap/entrypoint.sh && \
vendor/bin/phpunit \
  --configuration phpunit.xml \
  tests/core/SmartRetryLogicTest.php \
  tests/core/LicenseGatingTest.php \
  tests/core/HealthCalculationTest.php \
  --verbose
"
