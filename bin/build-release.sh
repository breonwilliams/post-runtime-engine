#!/bin/bash
#
# Build a production-ready release ZIP for Promptless CPT Pages.
#
# Single WordPress.org-compliant build. The plugin is distributed exclusively
# through the WordPress.org plugin directory (SVN), so it ships NO alternative
# update mechanism (WP.org guideline #8 — plugins must use the core update
# system) and excludes dev-only docs the reviewers flag as non-shipping
# material. The ZIP produced here is for local Plugin Check verification and
# test installs; the actual release is published by committing the staged tree
# to the WordPress.org SVN repo and tagging it (see RELEASE.md).
#
# History: the GitHub auto-updater and its dual GitHub/WP.org build flavors were
# retired once the plugin was accepted into the WordPress.org directory. There
# is now one build target.
#
# Output: build/promptless-cpt-pages.zip  (root folder: promptless-cpt-pages/)
#
# Usage:
#   bash bin/build-release.sh
#   bash bin/build-release.sh --help

set -e

case "${1:-}" in
    --help|-h)
        cat <<'USAGE'
Usage:
  bash bin/build-release.sh        # Build the WordPress.org release ZIP
  bash bin/build-release.sh --help # Show this usage

Produces build/promptless-cpt-pages.zip — a WordPress.org-compliant build
(no alternative updater; dev-only docs excluded). Verify it with Plugin Check,
then publish via the WordPress.org SVN workflow described in RELEASE.md.
USAGE
        exit 0
        ;;
    "")
        ;;
    *)
        echo "Error: Unknown option: $1"
        echo "Run with --help for usage."
        exit 1
        ;;
esac

PLUGIN_SLUG="promptless-cpt-pages"
BUILD_DIR="build"
TEMP_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
ZIP_NAME="${PLUGIN_SLUG}.zip"

# Resolve plugin root from the script's location so this works no matter
# where it's invoked from.
cd "$(dirname "$0")/.." || exit 1

# Detect version from the main plugin file's PCPTPages_VERSION constant.
# awk splits on `'`; field 4 is the value between the second pair of quotes.
VERSION=$(awk -F"'" "/define\\( 'PCPTPages_VERSION'/{print \$4}" post-runtime-engine.php)
if [ -z "$VERSION" ]; then
    echo "Error: Could not detect PCPTPages_VERSION from post-runtime-engine.php"
    exit 1
fi

echo "Building ${PLUGIN_SLUG} v${VERSION} (WordPress.org)..."

rm -rf "${TEMP_DIR}"
# Guard: if the staging dir survives the clean (permission issue, file lock,
# leftover from an interrupted build), abort. The rsync step below does NOT use
# --delete, so a surviving staging dir would silently leak removed files — e.g.
# the retired GitHub updater — back into the ZIP.
if [ -e "${TEMP_DIR}" ]; then
    echo "Error: could not fully clean the staging dir '${TEMP_DIR}'." >&2
    echo "       Stale files would leak into the build. Remove it manually and retry:" >&2
    echo "         rm -rf '${TEMP_DIR}'" >&2
    exit 1
fi
mkdir -p "${TEMP_DIR}"

# rsync exclude list: dev tooling, build artifacts, and dev-only docs that the
# WP.org reviewers flag as non-shipping material. `includes/Updates` is listed
# defensively — the GitHub updater was deleted from source, and it must never
# ship even if a stray copy reappears.
EXCLUDE_FILE=$(mktemp)
trap "rm -f ${EXCLUDE_FILE}" EXIT

cat > "${EXCLUDE_FILE}" <<'EXCLUDES'
.git
.gitignore
.DS_Store
.idea
.vscode
.claude
*.swp
*.swo
*~
*.bak
*.log
Thumbs.db
node_modules
vendor
build
tests
bin
includes/Updates
CLAUDE.md
POST_RUNTIME_AUDIT.md
phpunit.xml
phpunit.xml.dist
composer.lock
.phpcs.xml
.phpcs.xml.dist
.phpunit.result.cache
dev-*.php
seed-*.php
scratch-*.php
README.md
RELEASE.md
docs/ARCHITECTURE.md
docs/HOSTED_VALIDATION.md
docs/INTEGRATION_PROMPTLESS.md
docs/POST_FIELDS_V1_1_DESIGN.md
docs/PRESSURE_TESTS.md
docs/ROADMAP.md
EXCLUDES

rsync -av --exclude-from="${EXCLUDE_FILE}" . "${TEMP_DIR}/" >/dev/null

# Safety net: the plugin must ship zero traces of an alternative update
# mechanism (WP.org guideline #8). Fail loud if a reference reappears.
if grep -rl --include='*.php' 'PCPTPages_GitHub_Updater' "${TEMP_DIR}" >/dev/null 2>&1; then
    echo "Error: PCPTPages_GitHub_Updater reference found in the build — the GitHub updater was retired and must not return (WP.org guideline #8)." >&2
    grep -rn --include='*.php' 'PCPTPages_GitHub_Updater' "${TEMP_DIR}" >&2
    exit 1
fi

# Sanity-check that essential files made it through.
REQUIRED=(
    "post-runtime-engine.php"
    "readme.txt"
    "uninstall.php"
    "includes/class-pre-autoloader.php"
    "includes/Connector/assets/post-runtime-connector.js"
    "templates/single-base.php"
    "assets/css/frontend.css"
    "assets/css/admin.css"
    "assets/css/cards.css"
    "assets/js/meta-box.js"
    "assets/js/iconify-icon.min.js"
)
for f in "${REQUIRED[@]}"; do
    if [ ! -f "${TEMP_DIR}/${f}" ]; then
        echo "Error: required file missing from build: ${f}"
        rm -rf "${TEMP_DIR}"
        exit 1
    fi
done

echo "Creating zip..."
(
    cd "${BUILD_DIR}"
    zip -r "${ZIP_NAME}" "${PLUGIN_SLUG}/" -x "*.DS_Store" "*/.git/*" "*/node_modules/*" >/dev/null
)

ZIP_SIZE=$(du -h "${BUILD_DIR}/${ZIP_NAME}" | cut -f1)
FILE_COUNT=$(unzip -l "${BUILD_DIR}/${ZIP_NAME}" | tail -1 | awk '{print $2}')

echo ""
echo "================================================================"
echo "Build complete."
echo ""
echo "  Plugin:  ${PLUGIN_SLUG} v${VERSION}"
echo "  Files:   ${FILE_COUNT}"
echo "  Size:    ${ZIP_SIZE}"
echo "  Output:  ${BUILD_DIR}/${ZIP_NAME}"
echo ""
echo "Next steps (WordPress.org):"
echo "  1. Verify the staged build with Plugin Check:"
echo "       wp plugin check ${BUILD_DIR}/${PLUGIN_SLUG} --format=table"
echo "  2. Publish via the WordPress.org SVN workflow (see RELEASE.md):"
echo "       copy ${BUILD_DIR}/${PLUGIN_SLUG}/ into SVN trunk, then tag v${VERSION}."
echo "================================================================"
