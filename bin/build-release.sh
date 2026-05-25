#!/bin/bash
#
# Build a production-ready release ZIP for Post Runtime Engine.
#
# Produces one of two build flavors from the same source tree:
#
#   GitHub build (default):
#     - Includes the GitHub auto-updater (includes/Updates/).
#     - Output: build/post-runtime-engine.zip  +  versioned archive copy.
#     - Distributed via GitHub Releases.
#
#   WP.org build (--wporg):
#     - Excludes the GitHub auto-updater (WP.org guideline #8 prohibits
#       plugins from overriding the WordPress update mechanism).
#     - Excludes additional dev-only markdown files (README.md, RELEASE.md).
#     - Output: build/post-runtime-engine-wporg.zip
#     - Distributed via the WordPress.org plugin directory SVN.
#
# Both flavors contain a root folder named "post-runtime-engine/" so
# WordPress recognizes them as the same plugin when uploaded/updated.
# Only the file contents inside that folder differ between flavors.
#
# Usage:
#   bash bin/build-release.sh             # GitHub build (default)
#   bash bin/build-release.sh --github    # Explicit GitHub build (same as default)
#   bash bin/build-release.sh --wporg     # WordPress.org-compliant build
#   bash bin/build-release.sh --help      # Show this usage

set -e

# Parse command-line flags.
BUILD_TARGET="github"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --wporg)
            BUILD_TARGET="wporg"
            shift
            ;;
        --github)
            BUILD_TARGET="github"
            shift
            ;;
        --help|-h)
            cat <<'USAGE'
Usage:
  bash bin/build-release.sh             # GitHub build (default)
  bash bin/build-release.sh --github    # Explicit GitHub build (same as default)
  bash bin/build-release.sh --wporg     # WordPress.org-compliant build
  bash bin/build-release.sh --help      # Show this usage

GitHub build:
  - Includes the GitHub auto-updater (includes/Updates/).
  - Output: build/post-runtime-engine.zip
  - Distributed via GitHub Releases.

WP.org build:
  - Excludes the GitHub auto-updater (WP.org guideline #8).
  - Excludes dev-only markdown (README.md, RELEASE.md).
  - Output: build/post-runtime-engine-wporg.zip
  - Distributed via the WordPress.org plugin directory SVN.
USAGE
            exit 0
            ;;
        *)
            echo "Error: Unknown option: $1"
            echo "Run with --help for usage."
            exit 1
            ;;
    esac
done

PLUGIN_SLUG="post-runtime-engine"
BUILD_DIR="build"
TEMP_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

# ZIP name differs by target so both flavors can coexist in build/.
if [ "$BUILD_TARGET" = "wporg" ]; then
    ZIP_NAME="${PLUGIN_SLUG}-wporg.zip"
else
    ZIP_NAME="${PLUGIN_SLUG}.zip"
fi

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

echo "Building ${PLUGIN_SLUG} v${VERSION} for ${BUILD_TARGET}..."

# Clean only the staging subdirectory, not the whole build/ folder.
# This lets both GitHub and WP.org ZIPs coexist in build/ when both
# targets are built in sequence.
rm -rf "${TEMP_DIR}"
mkdir -p "${TEMP_DIR}"

# Assemble the rsync exclude list in a temp file so we can conditionally
# append WP.org-specific exclusions without duplicating the base list.
EXCLUDE_FILE=$(mktemp)
trap "rm -f ${EXCLUDE_FILE}" EXIT

# Base exclusions — applied to BOTH GitHub and WP.org builds.
cat > "${EXCLUDE_FILE}" <<'BASE_EXCLUDES'
.git
.gitignore
.DS_Store
.idea
.vscode
.claude
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
BASE_EXCLUDES

# WP.org-only exclusions. The GitHub updater is forbidden by guideline #8
# (plugins must use WordPress's update mechanism); README.md / RELEASE.md
# trigger "unexpected markdown file" warnings — WP.org reads metadata
# from readme.txt instead.
if [ "$BUILD_TARGET" = "wporg" ]; then
    cat >> "${EXCLUDE_FILE}" <<'WPORG_EXCLUDES'
includes/Updates
README.md
RELEASE.md
WPORG_EXCLUDES
fi

# Copy production files using the assembled exclude list.
rsync -av --exclude-from="${EXCLUDE_FILE}" . "${TEMP_DIR}/" >/dev/null

# Sanity-check that essential files made it through. The REQUIRED list
# branches by target — the GitHub updater is required for GitHub builds
# but intentionally absent from WP.org builds.
REQUIRED=(
    "post-runtime-engine.php"
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

if [ "$BUILD_TARGET" = "github" ]; then
    REQUIRED+=(
        "README.md"
        "includes/Updates/class-pre-github-updater.php"
    )
else
    # WP.org build requires readme.txt (the canonical WP.org metadata file).
    REQUIRED+=(
        "readme.txt"
    )
fi

for f in "${REQUIRED[@]}"; do
    if [ ! -f "${TEMP_DIR}/${f}" ]; then
        echo "Error: required file missing from build: ${f}"
        rm -rf "${TEMP_DIR}"
        exit 1
    fi
done

echo "Creating zip..."

# Build the zip from inside the build directory so the root folder is
# named correctly. -x cleans up any stragglers the rsync exclude missed.
(
    cd "${BUILD_DIR}"
    zip -r "${ZIP_NAME}" "${PLUGIN_SLUG}/" -x "*.DS_Store" "*/.git/*" "*/node_modules/*" >/dev/null

    # Versioned archive copy — only produced for GitHub builds (WP.org
    # distributes via SVN tags rather than versioned ZIPs in the repo).
    if [ "$BUILD_TARGET" = "github" ]; then
        cp "${ZIP_NAME}" "${PLUGIN_SLUG}-v${VERSION}.zip"
    fi
)

ZIP_SIZE=$(du -h "${BUILD_DIR}/${ZIP_NAME}" | cut -f1)
FILE_COUNT=$(unzip -l "${BUILD_DIR}/${ZIP_NAME}" | tail -1 | awk '{print $2}')

echo ""
echo "================================================================"
echo "Build complete."
echo ""
echo "  Plugin:  ${PLUGIN_SLUG} v${VERSION}"
echo "  Target:  ${BUILD_TARGET}"
echo "  Files:   ${FILE_COUNT}"
echo "  Size:    ${ZIP_SIZE}"
echo "  Output:  ${BUILD_DIR}/${ZIP_NAME}"

if [ "$BUILD_TARGET" = "wporg" ]; then
    echo ""
    echo "Next steps (WordPress.org):"
    echo "  1. Verify the staged build with Plugin Check:"
    echo "       wp plugin check ${BUILD_DIR}/${PLUGIN_SLUG} --format=table"
    echo "  2. Commit to WordPress.org SVN trunk and tag for release."
    echo "     (see RELEASE.md for the SVN workflow once it's documented)"
else
    echo "  Archive: ${BUILD_DIR}/${PLUGIN_SLUG}-v${VERSION}.zip"
    echo ""
    echo "Next steps (GitHub):"
    echo "  1. Upload via WP-Admin → Plugins → Add New → Upload Plugin"
    echo "     and select ${BUILD_DIR}/${ZIP_NAME}"
    echo ""
    echo "  2. Or attach to a GitHub release:"
    echo "       gh release create v${VERSION} \\"
    echo "         ${BUILD_DIR}/${PLUGIN_SLUG}-v${VERSION}.zip \\"
    echo "         --title \"v${VERSION}\" \\"
    echo "         --notes \"See CHANGELOG.md\""
fi
echo "================================================================"
