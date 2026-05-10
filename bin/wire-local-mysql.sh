#!/usr/bin/env bash
#
# One-shot wire-up: connect /tmp/wordpress-tests-lib to Local by Flywheel's MySQL.
#
# Discovers the AI Section Builder site's MySQL socket (or falls back to the
# most recently modified socket), creates the wordpress_test database on it,
# and patches wp-tests-config.php to use the socket via DB_HOST.
#
# Idempotent — safe to re-run.
#

set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

print_status()  { echo -e "${GREEN}[*]${NC} $1"; }
print_warning() { echo -e "${YELLOW}[!]${NC} $1"; }
print_error()   { echo -e "${RED}[x]${NC} $1"; }

# 1. Find Local's MySQL binary.
MYSQL_BIN=$(find /Applications/Local.app -name 'mysql' -type f 2>/dev/null | grep bin | head -1)
if [ -z "$MYSQL_BIN" ]; then
    print_error "Could not find Local MySQL binary under /Applications/Local.app"
    exit 1
fi
print_status "Local MySQL binary: $MYSQL_BIN"

# 2. Auto-detect AI Section Builder's running socket by grepping nginx site.conf.
LOCAL_RUN_DIR="$HOME/Library/Application Support/Local/run"
SOCK=""

if [ -d "$LOCAL_RUN_DIR" ]; then
    for d in "$LOCAL_RUN_DIR"/*/; do
        for conf in "${d}conf/nginx/site.conf" "${d}conf/nginx/site.conf.hbs"; do
            if [ -f "$conf" ] && grep -q "ai-section-builder" "$conf" 2>/dev/null; then
                SOCK="${d}mysql/mysqld.sock"
                print_status "Detected AI Section Builder site at: ${d}"
                break 2
            fi
        done
    done
fi

# 3. Fallback: pick the most recently modified socket.
if [ -z "$SOCK" ]; then
    print_warning "Auto-detection didn't find a site referencing 'ai-section-builder'."
    print_warning "Falling back to the most recently modified MySQL socket."
    SOCK=$(ls -t "$LOCAL_RUN_DIR"/*/mysql/mysqld.sock 2>/dev/null | head -1)
fi

if [ -z "$SOCK" ] || [ ! -S "$SOCK" ]; then
    print_error "No active Local MySQL sockets found."
    print_error "Make sure Local is running and at least one site is started."
    exit 1
fi

print_status "Using socket: $SOCK"

# 4. Create the wordpress_test database (idempotent).
if "$MYSQL_BIN" --socket="$SOCK" -uroot -proot -e "CREATE DATABASE IF NOT EXISTS wordpress_test;" 2>/dev/null; then
    print_status "Database 'wordpress_test' is ready."
else
    print_error "Failed to create the wordpress_test database via MySQL on this socket."
    print_error "Check that root/root credentials are correct for the picked Local site."
    exit 1
fi

# 5. Patch wp-tests-config.php to use the socket.
WP_TESTS_CONFIG="/tmp/wordpress-tests-lib/wp-tests-config.php"
if [ ! -f "$WP_TESTS_CONFIG" ]; then
    print_error "Expected $WP_TESTS_CONFIG to exist (run 'composer install-wp-tests' first)."
    exit 1
fi

# Use a literal-character-safe sed approach: pipe-delimited s|||, escape only what sed requires.
# WordPress accepts socket via DB_HOST as 'localhost:/path/to/socket'.
DB_HOST_VALUE="localhost:${SOCK}"

# Escape sed-meta characters in the replacement.
ESCAPED_VALUE=$(printf '%s' "$DB_HOST_VALUE" | sed -e 's/[\/&|]/\\&/g')

sed -i.bak "s|define( 'DB_HOST', '.*' );|define( 'DB_HOST', '${ESCAPED_VALUE}' );|" "$WP_TESTS_CONFIG"

print_status "Patched $WP_TESTS_CONFIG"
echo ""
echo "    $(grep DB_HOST "$WP_TESTS_CONFIG")"
echo ""
print_status "Setup complete. Run integration tests with:"
echo ""
echo "    composer test:integration"
echo ""
