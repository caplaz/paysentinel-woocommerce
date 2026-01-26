#!/usr/bin/env bash

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress/}

download() {
    if command -v curl >/dev/null 2>&1; then
        if [ -z "$2" ]; then
            curl -s "$1"
        else
            curl -s "$1" > "$2"
        fi
    elif command -v wget >/dev/null 2>&1; then
        if [ -z "$2" ]; then
            wget -q -O - "$1"
        else
            wget -nv -O "$2" "$1"
        fi
    fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${WP_VERSION%\-*}
else
	WP_BRANCH=$WP_VERSION
fi

echo "Installing WordPress version $WP_VERSION in $WP_CORE_DIR..."

if [ -d $WP_CORE_DIR ]; then
	# If it's a mount point OR just a dir, clear contents instead of removing the dir itself
    rm -rf $WP_CORE_DIR/*
fi

if [ ! -d $WP_CORE_DIR ]; then
    mkdir -p $WP_CORE_DIR
fi

if [[ $WP_VERSION == 'nightly' ]] || [[ $WP_VERSION == 'trunk' ]]; then
	mkdir -p $TMPDIR/wordpress-nightly
	download https://wordpress.org/nightly-builds/wordpress-latest.zip  $TMPDIR/wordpress-nightly/wordpress-nightly.zip
	unzip -q $TMPDIR/wordpress-nightly/wordpress-nightly.zip -d $TMPDIR/wordpress-nightly/
	mv $TMPDIR/wordpress-nightly/wordpress/* $WP_CORE_DIR
elif [ $WP_VERSION == 'latest' ]; then
	LATEST_VERSION=$(download https://api.wordpress.org/core/version-check/1.7/ | head -1)
	download https://wordpress.org/wordpress-$LATEST_VERSION.zip  $TMPDIR/wordpress-$LATEST_VERSION.zip
	unzip -q $TMPDIR/wordpress-$LATEST_VERSION.zip -d $TMPDIR/
	mv $TMPDIR/wordpress/* $WP_CORE_DIR
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
	# https serves multiple offers, whereas http serves single.
	download https://wordpress.org/wordpress-$WP_VERSION.zip $TMPDIR/wordpress-$WP_VERSION.zip
	unzip -q $TMPDIR/wordpress-$WP_VERSION.zip -d $TMPDIR/
	mv $TMPDIR/wordpress/* $WP_CORE_DIR
else
	archive_path=$(find . -name "*wordpress-$WP_BRANCH*.zip" | head -1)
	if [ ! -f $archive_path ]; then
		echo "Could not find archive for WordPress $WP_BRANCH"
		exit 1
	fi
	unzip -q $archive_path -d $TMPDIR/
	mv $TMPDIR/wordpress/* $WP_CORE_DIR
fi

if [ -d $WP_TESTS_DIR ]; then
	rm -rf $WP_TESTS_DIR
fi

echo "Installing WordPress test suite in $WP_TESTS_DIR..."
mkdir -p $WP_TESTS_DIR

# Use Git for WordPress test suite (SVN is deprecated)
if command -v git >/dev/null 2>&1; then
    echo "Using Git to download WordPress test suite..."
    TMP_TESTS_DIR="$TMPDIR/wordpress-develop"
    if [ -d "$TMP_TESTS_DIR" ]; then
        rm -rf "$TMP_TESTS_DIR"
    fi
    
    if [[ $WP_BRANCH == 'trunk' ]]; then
        git clone --depth=1 --branch=trunk https://github.com/WordPress/wordpress-develop.git "$TMP_TESTS_DIR"
    else
        # For specific versions, try to find the tag
        git clone --depth=1 --branch="$WP_BRANCH" https://github.com/WordPress/wordpress-develop.git "$TMP_TESTS_DIR" 2>/dev/null || \
        git clone --depth=1 --branch="trunk" https://github.com/WordPress/wordpress-develop.git "$TMP_TESTS_DIR"
    fi
    
    cp -r "$TMP_TESTS_DIR/tests/phpunit/includes" "$WP_TESTS_DIR/"
    cp -r "$TMP_TESTS_DIR/tests/phpunit/data" "$WP_TESTS_DIR/"
    if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
        cp "$TMP_TESTS_DIR/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
    fi
    # Create src directory with WordPress core for tests
    mkdir -p "$WP_TESTS_DIR/src"
    cp -r "$WP_CORE_DIR"/* "$WP_TESTS_DIR/src/"
    rm -rf "$TMP_TESTS_DIR"
else
    echo "Git not found, falling back to SVN..."
    svn co --quiet https://develop.svn.wordpress.org/tags/$WP_BRANCH/tests/phpunit/includes/ $WP_TESTS_DIR/includes
    svn co --quiet https://develop.svn.wordpress.org/tags/$WP_BRANCH/tests/phpunit/data/ $WP_TESTS_DIR/data
    if [ ! -f $WP_TESTS_DIR/wp-tests-config.php ]; then
        download https://develop.svn.wordpress.org/tags/$WP_BRANCH/wp-tests-config-sample.php $WP_TESTS_DIR/wp-tests-config.php
    fi
fi

if [ ! -f $WP_CORE_DIR/wp-tests-config.php ]; then
	cp $WP_TESTS_DIR/wp-tests-config.php $WP_CORE_DIR/wp-tests-config.php
fi

if [ "$SKIP_DB_CREATE" = "false" ]; then
	echo "Setting up test database..."
	mysqladmin create $DB_NAME --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --silent
else
	echo "Skipping database creation..."
fi

echo "Configuring WordPress for tests..."
sed -i "s/youremptytestdbnamehere/$DB_NAME/" $WP_CORE_DIR/wp-tests-config.php
sed -i "s/yourusernamehere/$DB_USER/" $WP_CORE_DIR/wp-tests-config.php
sed -i "s/yourpasswordhere/$DB_PASS/" $WP_CORE_DIR/wp-tests-config.php
sed -i "s|localhost|${DB_HOST}|" $WP_CORE_DIR/wp-tests-config.php

# Also configure the tests config file
sed -i "s/youremptytestdbnamehere/$DB_NAME/" $WP_TESTS_DIR/wp-tests-config.php
sed -i "s/yourusernamehere/$DB_USER/" $WP_TESTS_DIR/wp-tests-config.php
sed -i "s/yourpasswordhere/$DB_PASS/" $WP_TESTS_DIR/wp-tests-config.php
sed -i "s|localhost|${DB_HOST}|" $WP_TESTS_DIR/wp-tests-config.php

echo "Done!"