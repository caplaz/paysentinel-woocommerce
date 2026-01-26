#!/bin/bash
# Simple test runner that runs tests without PHPUnit config parsing issues

docker compose run --rm tests bash -lc "
bash ./tests/entrypoint.sh && \
vendor/bin/phpunit \
  --configuration phpunit.xml \
  tests/SmartRetryLogicTest.php \
  --verbose
"
