#!/usr/bin/env bash
#
# Create the current version's GitHub Release and attach the WordPress.org ZIP.
#
# PRE ships PRIMARILY via WordPress.org SVN; this covers RELEASE.md distribution
# item 2 — the GitHub Release with `build/promptless-cpt-pages.zip` attached.
# Version-agnostic (reads the plugin header) and reuses the GitHub login your
# `git push` already uses, so no `gh` CLI is required. The token stays in your
# shell and is never printed; override with GH_TOKEN=... if needed.
#
# Run AFTER: version bumped, committed + pushed, `./bin/build-release.sh` built
# the ZIP, and the tag is pushed (`git push origin vX.Y.Z`).
#
set -uo pipefail
REPO="breonwilliams/post-runtime-engine"
VERSION=$(grep -m1 "^ \* Version:" post-runtime-engine.php | sed 's/^ \* Version: *//' | tr -d ' \r')
[ -n "$VERSION" ] || { echo "ERROR: could not read version from post-runtime-engine.php"; exit 1; }
TAG="v$VERSION"; NAME="v$VERSION"
ZIP="build/promptless-cpt-pages.zip"
[ -f "$ZIP" ] || { echo "ERROR: $ZIP not found. Run ./bin/build-release.sh first."; exit 1; }
NOTES=$(awk -v v="= $VERSION =" '$0==v{f=1;next} /^= [0-9]/{if(f)exit} f{print}' readme.txt)
[ -n "$NOTES" ] || NOTES="See CHANGELOG.md for details."
TOKEN=$(printf "protocol=https\nhost=github.com\n\n" | GIT_TERMINAL_PROMPT=0 git credential fill 2>/dev/null | sed -n 's/^password=//p')
[ -z "$TOKEN" ] && TOKEN="${GH_TOKEN:-}"
[ -n "$TOKEN" ] || { echo "No GitHub token found. Re-run as: GH_TOKEN=ghp_xxx bash bin/publish-github-release.sh"; exit 1; }
if ! git ls-remote --tags origin "refs/tags/$TAG" 2>/dev/null | grep -q "$TAG"; then
  echo "ERROR: tag $TAG is not on origin yet. Push it first:  git push origin $TAG"; exit 1
fi
echo "Publishing $NAME ..."
BODY=$(NOTES="$NOTES" python3 -c 'import json,os,sys; print(json.dumps({"tag_name":sys.argv[1],"name":sys.argv[2],"body":os.environ["NOTES"],"draft":False,"prerelease":False,"make_latest":"true"}))' "$TAG" "$NAME")
RESP=$(curl -sS -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2022-11-28" "https://api.github.com/repos/$REPO/releases" -d "$BODY")
UPLOAD_URL=$(printf '%s' "$RESP" | python3 -c 'import json,sys
try: d=json.load(sys.stdin)
except Exception: print(""); sys.exit()
print(d.get("upload_url","") or "")')
HTML_URL=$(printf '%s' "$RESP" | python3 -c 'import json,sys
try: print(json.load(sys.stdin).get("html_url",""))
except Exception: print("")')
if [ -z "$UPLOAD_URL" ]; then
  echo "Release creation FAILED. GitHub said:"
  printf '%s' "$RESP" | python3 -c 'import json,sys
try:
  d=json.load(sys.stdin); print("  ", d.get("message",""))
  for e in d.get("errors",[]): print("   -", e.get("code"), e.get("field"))
except Exception: print(sys.stdin.read())'
  exit 1
fi
UP="${UPLOAD_URL%\{*}"
echo "Uploading $(basename "$ZIP") ..."
ASSET=$(curl -sS -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/zip" -H "X-GitHub-Api-Version: 2022-11-28" "${UP}?name=$(basename "$ZIP")" --data-binary @"$ZIP")
STATE=$(printf '%s' "$ASSET" | python3 -c 'import json,sys
try: print(json.load(sys.stdin).get("state",""))
except Exception: print("")')
if [ "$STATE" = "uploaded" ]; then
  echo ""; echo "SUCCESS: $NAME published with the ZIP attached."; echo "View it: $HTML_URL"
else
  echo "Release created ($HTML_URL) but the asset upload did not confirm:"; printf '%s\n' "$ASSET" | head -c 800; exit 1
fi
