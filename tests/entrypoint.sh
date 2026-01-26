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

if [ -d "/tmp/wordpress-tests-lib/src/wp-content/plugins" ]; then
    TARGET_PLUGIN_DIR="/tmp/wordpress-tests-lib/src/wp-content/plugins"
else
    # Fallback to standard WP location if test lib src structure is different
    TARGET_PLUGIN_DIR="/tmp/wordpress/wp-content/plugins"
fi

# Install WooCommerce if not present
WC_DIR="${TARGET_PLUGIN_DIR}/woocommerce"
if [ ! -d "$WC_DIR" ]; then
    echo -e "${YELLOW}Installing WooCommerce to ${TARGET_PLUGIN_DIR}...${NC}"
    mkdir -p "$TARGET_PLUGIN_DIR"
    curl -L https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip -o /tmp/woocommerce.zip
    unzip -q /tmp/woocommerce.zip -d "$TARGET_PLUGIN_DIR/"
    rm /tmp/woocommerce.zip
    echo -e "${GREEN}✓ WooCommerce installed${NC}"
else
    echo -e "${GREEN}✓ WooCommerce already installed at ${WC_DIR}${NC}"
fi

echo "Ready to run tests"
