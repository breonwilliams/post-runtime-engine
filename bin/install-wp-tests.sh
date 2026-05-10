#!/usr/bin/env bash
#
# Install WordPress test framework for Post Runtime Engine plugin.
#
# Modeled on Form Runtime Engine's install-wp-tests.sh so PRE/FRE share
# the same setup convention. Defaults are tuned for Local by Flywheel.
#
# Usage:
#   ./bin/install-wp-tests.sh [db_name] [db_user] [db_pass] [db_host] [wp_version] [--force]
#
# Use --force to skip interactive prompts and reinstall everything.
#

set -e

# Check for --force flag.
FORCE=false
for arg in "$@"; do
    if [ "$arg" == "--force" ]; then
        FORCE=true
    fi
done

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

# Colors for output.
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

print_status()  { echo -e "${GREEN}[*]${NC} $1"; }
print_warning() { echo -e "${YELLOW}[!]${NC} $1"; }
print_error()   { echo -e "${RED}[x]${NC} $1"; }

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PLUGIN_DIR="$( dirname "$SCRIPT_DIR" )"

download() {
    if [ "$(which curl)" ]; then
        curl -s "$1" > "$2"
    elif [ "$(which wget)" ]; then
        wget -nv -O "$2" "$1"
    fi
}

get_latest_wp_version() {
    download https://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
    grep -o '"version":"[^"]*"' /tmp/wp-latest.json | head -1 | sed 's/"version":"//;s/"//'
}

if [ "$WP_VERSION" == "latest" ]; then
    print_status "Fetching latest WordPress version..."
    WP_VERSION=$(get_latest_wp_version)
fi

print_status "WordPress Test Framework Installer (Post Runtime Engine)"
echo "========================================"
echo "WP Version:     $WP_VERSION"
echo "WP Tests Dir:   $WP_TESTS_DIR"
echo "WP Core Dir:    $WP_CORE_DIR"
echo "Database:       $DB_NAME"
echo "DB User:        $DB_USER"
echo "DB Host:        $DB_HOST"
echo ""

install_wp_tests() {
    print_status "Installing WordPress test library..."

    if [ -d "$WP_TESTS_DIR" ]; then
        if [ "$FORCE" = true ]; then
            print_status "Force flag set, removing existing installation..."
            rm -rf "$WP_TESTS_DIR"
        else
            print_warning "Test library already exists at $WP_TESTS_DIR"
            read -p "Remove and reinstall? [y/N] " -n 1 -r
            echo
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                rm -rf "$WP_TESTS_DIR"
            else
                print_status "Skipping download, using existing installation."
                return
            fi
        fi
    fi

    mkdir -p "$WP_TESTS_DIR"

    if [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
        WP_TESTS_TAG="branches/$WP_VERSION"
    elif [[ "$WP_VERSION" =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
        if [[ "$WP_VERSION" =~ [0-9]+\.[0-9]+\.[0] ]]; then
            WP_TESTS_TAG="branches/${WP_VERSION%??}"
        else
            WP_TESTS_TAG="tags/$WP_VERSION"
        fi
    elif [ "$WP_VERSION" == "trunk" ]; then
        WP_TESTS_TAG="trunk"
    else
        WP_TESTS_TAG="branches/$WP_VERSION"
    fi

    print_status "Downloading test library from SVN (tag: $WP_TESTS_TAG)..."

    if ! command -v svn &> /dev/null; then
        print_error "SVN is not installed."
        if command -v brew &> /dev/null; then
            print_status "Installing svn via Homebrew..."
            brew install svn
        else
            print_error "Please install SVN: brew install svn"
            exit 1
        fi
    fi

    svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
    svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"

    print_status "Test library installed successfully!"
}

install_wp_core() {
    print_status "Installing WordPress core..."

    if [ -d "$WP_CORE_DIR" ]; then
        if [ "$FORCE" = true ]; then
            print_status "Force flag set, removing existing WordPress core..."
            rm -rf "$WP_CORE_DIR"
        else
            print_warning "WordPress core already exists at $WP_CORE_DIR"
            read -p "Remove and reinstall? [y/N] " -n 1 -r
            echo
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                rm -rf "$WP_CORE_DIR"
            else
                print_status "Skipping download, using existing installation."
                return
            fi
        fi
    fi

    mkdir -p "$WP_CORE_DIR"

    if [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
        download "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" /tmp/wordpress.tar.gz
    else
        download "https://wordpress.org/wordpress-latest.tar.gz" /tmp/wordpress.tar.gz
    fi

    tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"

    print_status "WordPress core installed successfully!"
}

create_config() {
    print_status "Creating wp-tests-config.php..."

    if [ ! -f "$WP_TESTS_DIR/wp-tests-config-sample.php" ]; then
        download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
    fi

    cat > "$WP_TESTS_DIR/wp-tests-config.php" <<EOF
<?php
/**
 * WordPress Test Configuration
 *
 * Generated by Post Runtime Engine install script.
 * Created: $(date)
 */

define( 'ABSPATH', '$WP_CORE_DIR/' );

define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

\$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Post Runtime Engine Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
EOF

    print_status "Config file created at $WP_TESTS_DIR/wp-tests-config.php"
}

create_database() {
    print_status "Creating test database '$DB_NAME'..."

    # If Local by Flywheel is installed, delegate to wire-local-mysql.sh.
    # That script handles the things the standard mysql client can't reach:
    # discovering Local's per-site MySQL binary, finding the right socket
    # (auto-detecting the AI Section Builder site by name), and patching
    # wp-tests-config.php to use socket-format DB_HOST. Single source of
    # truth — install-wp-tests.sh and re-wire scenarios share the logic.
    #
    # Why a separate script: re-wiring after a Local restart or a site
    # rename needs the same logic without re-installing WP + the test lib.
    if [ -d "/Applications/Local.app" ]; then
        local wire_script="$SCRIPT_DIR/wire-local-mysql.sh"
        if [ -x "$wire_script" ]; then
            print_status "Local by Flywheel detected — delegating to wire-local-mysql.sh"
            echo ""
            "$wire_script"
            return $?
        fi
        print_warning "Local by Flywheel detected but bin/wire-local-mysql.sh missing or not executable."
    fi

    # Standard MySQL client path (non-Local environments — CI, Docker,
    # standalone MySQL, etc.).
    if ! command -v mysql &> /dev/null; then
        print_error "MySQL client not found and Local by Flywheel not detected."
        print_warning "Install MySQL client or Local by Flywheel, then re-run."
        echo ""
        echo "If you're on Local by Flywheel and still seeing this, the wire-up"
        echo "script can be run directly:"
        echo ""
        echo "  ./bin/wire-local-mysql.sh"
        echo ""
        return 1
    fi

    MYSQL_CMD="mysql -h$DB_HOST -u$DB_USER"
    if [ -n "$DB_PASS" ] && [ "$DB_PASS" != "" ]; then
        MYSQL_CMD="$MYSQL_CMD -p$DB_PASS"
    fi

    eval "$MYSQL_CMD -e 'CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;'" 2>/dev/null || {
        print_error "Failed to create database via standard mysql client."
        return 1
    }

    print_status "Database '$DB_NAME' created successfully!"
}

main() {
    echo ""
    print_status "Starting WordPress test framework installation..."
    echo ""

    install_wp_tests
    install_wp_core
    create_config
    create_database

    echo ""
    echo "========================================"
    print_status "Installation complete!"
    echo ""
    echo "To run integration tests:"
    echo ""
    echo "  cd $PLUGIN_DIR"
    echo "  composer test:integration"
    echo ""
    echo "Or directly:"
    echo ""
    echo "  WP_TESTS_DIR=$WP_TESTS_DIR ./vendor/bin/phpunit --testsuite Integration"
    echo ""
    echo "========================================"
}

main
