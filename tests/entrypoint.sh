#!/bin/bash

# Entrypoint script for test container
# Only installs WordPress test suite if not already installed (cached in volume)

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if WordPress is already installed
if [ -f "/tmp/wordpress/wp-settings.php" ] && [ -f "/tmp/wordpress-tests-lib/includes/functions.php" ]; then
    echo -e "${GREEN}✓ WordPress test environment already cached${NC}"
    echo "  Using cached WordPress core and test suite"
else
    echo -e "${YELLOW}Installing WordPress test environment...${NC}"
    
    # Run the installation script
    bash install-wp-tests.sh "${DB_NAME}" "${DB_USER}" "${DB_PASS}" "${DB_HOST}" "${WP_VERSION}" true
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ WordPress test environment installed successfully${NC}"
    else
        echo -e "${RED}✗ Failed to install WordPress test environment${NC}"
        exit 1
    fi
fi

echo "Ready to run tests"
