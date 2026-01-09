#!/bin/bash
# Simple test runner that runs tests without PHPUnit config parsing issues

docker compose run --rm tests bash -lc "
vendor/bin/phpunit tests/TestLoggerTest.php \
  --no-configuration \
  --testdox \
  --verbose
"
