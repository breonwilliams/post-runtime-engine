#!/bin/bash
#
# Build a production-ready release ZIP for Promptless CPT Pages.
#
# Produces one of two build flavors from the same source tree:
#
#   GitHub build (default):
#     - Includes the GitHub auto-updater (includes/Updates/).
#     - Output: build/promptless-cpt-pages.zip  +  versioned archive copy.
#     - Distributed via GitHub Releases.
#
#   WP.org build (--wporg):
#     - Excludes the GitHub auto-updater (WP.org guideline #8 prohibits
#       plugins from overriding the WordPress update mechanism).
#     - Excludes additional dev-only markdown files (README.md, RELEASE.md).
#     - Output: build/promptless-cpt-pages-wporg.zip
#     - Distributed via the WordPress.org plugin directory SVN.
#
# Both flavors contain a root folder named "promptless-cpt-pages/" so
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
  - Output: build/promptless-cpt-pages.zip
  - Distributed via GitHub Releases.

WP.org build:
  - Excludes the GitHub auto-updater (WP.org guideline #8).
  - Excludes dev-only markdown (README.md, RELEASE.md).
  - Output: build/promptless-cpt-pages-wporg.zip
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

PLUGIN_SLUG="promptless-cpt-pages"
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

# Detect version from the main plugin file's PCPTPages_VERSION constant.
# (Constant was renamed from PRE_VERSION in 0.5.0 as part of the
# WordPress.org 4-char prefix compliance rename.)
# awk splits on `'`; field 4 is the value between the second pair of
# single quotes — i.e. the version string itself.
VERSION=$(awk -F"'" "/define\\( 'PCPTPages_VERSION'/{print \$4}" post-runtime-engine.php)

if [ -z "$VERSION" ]; then
    echo "Error: Could not detect PCPTPages_VERSION from post-runtime-engine.php"
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
*.bak
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
# from readme.txt instead. Engineering planning / roadmap / pressure-test
# documents are flagged by WP.org reviewers as "AI-generated output" and
# don't belong in the shipped plugin — keep them in the GitHub build for
# contributors and exclude them from the WP.org distribution.
if [ "$BUILD_TARGET" = "wporg" ]; then
    cat >> "${EXCLUDE_FILE}" <<'WPORG_EXCLUDES'
includes/Updates
README.md
RELEASE.md
docs/ARCHITECTURE.md
docs/HOSTED_VALIDATION.md
docs/INTEGRATION_PROMPTLESS.md
docs/POST_FIELDS_V1_1_DESIGN.md
docs/PRESSURE_TESTS.md
docs/ROADMAP.md
WPORG_EXCLUDES
fi

# Copy production files using the assembled exclude list.
rsync -av --exclude-from="${EXCLUDE_FILE}" . "${TEMP_DIR}/" >/dev/null

# WP.org-only source-level strip. Removes ANY code block fenced with
# `BUILD:STRIP-FOR-WPORG-START` / `BUILD:STRIP-FOR-WPORG-END` markers
# from the staged copy, including the markers themselves. Used to fully
# excise references to the GitHub auto-updater (autoloader class_map
# entry + bootstrap call) so the WP.org distribution carries zero traces
# of an alternative update mechanism. The GitHub build leaves the
# markers in source and ships the wrapped code untouched.
if [ "$BUILD_TARGET" = "wporg" ]; then
    # In-place strip via awk + temp file. Chose awk over `sed -i` because
    # `-i` has BSD-vs-GNU flag-flavor differences (BSD needs `-i ''`, GNU
    # rejects the empty string) and threading that through bash arrays +
    # find -exec is fragile. Awk's text-processing semantics are
    # POSIX-standard and behave identically on macOS and Linux, so the
    # build is reproducible from either Local's Site Shell or a native
    # macOS Terminal.
    #
    # The awk program lives in a temp file (not an inline string) to
    # avoid any nested-quoting issues with bash 3.2 on macOS, where the
    # interaction between heredoc/process-substitution, `set -e`, and
    # `while read` loops has historically been brittle. Dispatching one
    # awk invocation per file via find -exec gives each file a fresh
    # awk process — `skip` always starts at 0, no state leaks across
    # files even if a marker pair is malformed.
    AWK_STRIP_SCRIPT="$(mktemp -t pre-strip.XXXXXX)"
    cat > "${AWK_STRIP_SCRIPT}" <<'AWK_PROGRAM'
/BUILD:STRIP-FOR-WPORG-START/ { skip = 1; next }
/BUILD:STRIP-FOR-WPORG-END/   { skip = 0; next }
!skip
AWK_PROGRAM

    # Tear the awk script down on any script exit (success or failure).
    # We already have a trap on EXCLUDE_FILE earlier; rather than
    # overwriting it, build a combined cleanup.
    trap "rm -f ${EXCLUDE_FILE} ${AWK_STRIP_SCRIPT}" EXIT

    # Count staged PHP files first — if find returns zero, something
    # went wrong with the rsync step and we want to fail loud rather
    # than silently "succeed" by not stripping anything.
    STAGED_PHP_COUNT=$(find "${TEMP_DIR}" -type f -name '*.php' | wc -l | tr -d ' ')
    echo "Stripping BUILD:STRIP-FOR-WPORG-* blocks from ${STAGED_PHP_COUNT} staged PHP files..."

    if [ "${STAGED_PHP_COUNT}" = "0" ]; then
        echo "Error: No PHP files found in ${TEMP_DIR} — rsync stage likely failed." >&2
        exit 1
    fi

    # Run awk per file via find -exec. This is the most portable
    # dispatch — no process substitution, no herestring, no while-read
    # subshell semantics. Each file is rewritten via temp-file-then-mv
    # so a partial awk run can't truncate the source.
    find "${TEMP_DIR}" -type f -name '*.php' -exec sh -c '
        awk_script="$1"
        shift
        for f in "$@"; do
            awk -f "${awk_script}" "${f}" > "${f}.tmp" && mv "${f}.tmp" "${f}"
        done
    ' _ "${AWK_STRIP_SCRIPT}" {} +

    echo "Strip pass complete."

    # Sanity: confirm no marker leaked through (would indicate a malformed
    # pair somewhere) AND no PCPTPages_GitHub_Updater reference survived.
    # Restricted to PHP files because the markers and class name appear
    # *as documentation* in CHANGELOG.md and the developer-facing comments
    # in build-release.sh itself. The strip step only operates on PHP,
    # so the sanity check only validates PHP — checking docs would
    # produce a false positive every time the changelog describes what
    # the markers do.
    if grep -rl --include='*.php' 'BUILD:STRIP-FOR-WPORG-' "${TEMP_DIR}" >/dev/null; then
        echo "Error: BUILD:STRIP-FOR-WPORG-* marker survived strip in a PHP file — check for unbalanced markers in source." >&2
        echo "Surviving PHP markers (file:line):" >&2
        grep -rn --include='*.php' 'BUILD:STRIP-FOR-WPORG-' "${TEMP_DIR}" >&2
        exit 1
    fi
    if grep -rl --include='*.php' 'PCPTPages_GitHub_Updater' "${TEMP_DIR}" >/dev/null; then
        echo "Error: PCPTPages_GitHub_Updater reference survived WP.org strip in a PHP file — check that all references are wrapped in BUILD:STRIP-FOR-WPORG-* markers." >&2
        grep -rn --include='*.php' 'PCPTPages_GitHub_Updater' "${TEMP_DIR}" >&2
        exit 1
    fi
fi

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
