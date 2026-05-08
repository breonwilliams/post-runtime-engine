#!/bin/bash
#
# Build a production-ready release ZIP for Post Runtime Engine.
#
# Produces a zip with the canonical folder structure WordPress expects
# (root folder named "post-runtime-engine/") so uploads through
# Plugins → Add New → Upload Plugin install cleanly and updates in-place
# instead of creating a duplicate. GitHub's auto-generated zipballs use
# a different folder name (with the commit hash), which is why we build
# our own.
#
# Usage:
#   bash bin/build-release.sh
#
# Output:
#   build/post-runtime-engine.zip          (canonical filename)
#   build/post-runtime-engine-vX.X.X.zip   (versioned filename for archives)
#
# The zip contains a root folder "post-runtime-engine/" with only the
# files needed for production. Dev infrastructure (tests/, bin/,
# CLAUDE.md, .git/, .gitignore) is excluded.

set -e

PLUGIN_SLUG="post-runtime-engine"
BUILD_DIR="build"
TEMP_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

# Resolve plugin root from the script's location so this works no matter
# where it's invoked from.
cd "$(dirname "$0")/.." || exit 1

# Detect version from the main plugin file's PRE_VERSION constant.
# awk splits on `'`; field 4 is the value between the second pair of
# single quotes — i.e. the version string itself.
VERSION=$(awk -F"'" "/define\\( 'PRE_VERSION'/{print \$4}" post-runtime-engine.php)

if [ -z "$VERSION" ]; then
    echo "Error: Could not detect PRE_VERSION from post-runtime-engine.php"
    exit 1
fi

echo "Building ${PLUGIN_SLUG} v${VERSION}..."

# Clean previous build artifacts.
rm -rf "${BUILD_DIR}"
mkdir -p "${TEMP_DIR}"

# Copy production files. The exclude list captures everything that's
# either dev-only, version control, OS junk, or AI-tooling specific.
rsync -av --exclude-from=- . "${TEMP_DIR}/" >/dev/null <<'EXCLUDE'
.git
.gitignore
.DS_Store
.idea
.vscode
*.swp
*.swo
*~
*.log
Thumbs.db
node_modules
vendor
build
tests
bin
CLAUDE.md
phpunit.xml
phpunit.xml.dist
composer.lock
.phpcs.xml
.phpcs.xml.dist
.phpunit.result.cache
EXCLUDE

# Sanity-check that the essential files made it through. If the rsync
# exclude list ever accidentally drops one of these, the build fails
# loudly rather than silently shipping a broken zip.
REQUIRED=(
    "post-runtime-engine.php"
    "uninstall.php"
    "README.md"
    "includes/class-pre-autoloader.php"
    "includes/Connector/assets/post-runtime-connector.js"
    "templates/single-base.php"
    "assets/css/frontend.css"
    "assets/css/admin.css"
    "assets/js/meta-box.js"
)
for f in "${REQUIRED[@]}"; do
    if [ ! -f "${TEMP_DIR}/${f}" ]; then
        echo "Error: required file missing from build: ${f}"
        rm -rf "${BUILD_DIR}"
        exit 1
    fi
done

echo "Creating zip..."

# Build the zip from inside the build directory so the root folder is
# named correctly. -x cleans up any stragglers the rsync exclude missed.
(
    cd "${BUILD_DIR}"
    zip -r "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}/" -x "*.DS_Store" "*/.git/*" "*/node_modules/*" >/dev/null

    # Also produce a versioned copy for archival.
    cp "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}-v${VERSION}.zip"
)

ZIP_SIZE=$(du -h "${BUILD_DIR}/${PLUGIN_SLUG}.zip" | cut -f1)
FILE_COUNT=$(unzip -l "${BUILD_DIR}/${PLUGIN_SLUG}.zip" | tail -1 | awk '{print $2}')

echo ""
echo "================================================================"
echo "Build complete."
echo ""
echo "  Plugin:  ${PLUGIN_SLUG} v${VERSION}"
echo "  Files:   ${FILE_COUNT}"
echo "  Size:    ${ZIP_SIZE}"
echo "  Output:  ${BUILD_DIR}/${PLUGIN_SLUG}.zip"
echo "  Archive: ${BUILD_DIR}/${PLUGIN_SLUG}-v${VERSION}.zip"
echo ""
echo "Next steps:"
echo "  1. Upload via WP-Admin → Plugins → Add New → Upload Plugin"
echo "     and select ${BUILD_DIR}/${PLUGIN_SLUG}.zip"
echo ""
echo "  2. Or attach to a GitHub release:"
echo "       gh release create v${VERSION} \\"
echo "         ${BUILD_DIR}/${PLUGIN_SLUG}-v${VERSION}.zip \\"
echo "         --title \"v${VERSION}\" \\"
echo "         --notes \"See CHANGELOG.md\""
echo "================================================================"
