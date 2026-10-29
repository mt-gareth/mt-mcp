#!/usr/bin/env bash
# Build a WordPress-installable zip (top-level folder: mt-mcp/) and optionally
# create a GitHub Release. Requires: git, zip; gh for --publish.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="$(grep -E "^\s*\* Version:" mt-mcp.php | head -1 | sed -E 's/.*Version:[[:space:]]*//')"
VERSION="$(echo "$VERSION" | tr -d '[:space:]')"
if [[ -z "$VERSION" ]]; then
  echo "Could not read Version from mt-mcp.php" >&2
  exit 1
fi

TAG="v${VERSION}"
DIST_DIR="$(mktemp -d)"
STAGE="${DIST_DIR}/mt-mcp"
ZIP_PATH="${ROOT}/dist/mt-mcp-${VERSION}.zip"

mkdir -p "${ROOT}/dist" "$STAGE"

# Prefer rsync + .distignore; fall back to git archive filtered copy.
if command -v rsync >/dev/null 2>&1; then
  rsync -a \
    --exclude-from="${ROOT}/.distignore" \
    --exclude='.git' \
    --exclude='dist' \
    --exclude='bin' \
    --exclude='.wordpress-org' \
    ./ "$STAGE/"
else
  echo "rsync required" >&2
  exit 1
fi

# Ensure vendor autoload exists (committed in this repo).
if [[ ! -f "${STAGE}/vendor/autoload.php" ]]; then
  echo "Missing vendor/autoload.php in package — run composer install --no-dev" >&2
  exit 1
fi

rm -f "$ZIP_PATH"
(
  cd "$DIST_DIR"
  zip -rq "$ZIP_PATH" mt-mcp
)

rm -rf "$DIST_DIR"

echo "Built ${ZIP_PATH}"
echo "Tag: ${TAG}"

if [[ "${1:-}" == "--publish" ]]; then
  if ! command -v gh >/dev/null 2>&1; then
    echo "gh CLI required for --publish" >&2
    exit 1
  fi
  # Create tag if missing (annotated from current HEAD).
  if ! git rev-parse "$TAG" >/dev/null 2>&1; then
    git tag -a "$TAG" -m "Release ${TAG}"
    git push origin "$TAG"
  else
    git push origin "$TAG" 2>/dev/null || true
  fi
  NOTES="MT MCP ${VERSION}

Install/update zip for WordPress (folder name \`mt-mcp\`).

See readme.txt changelog."
  if gh release view "$TAG" >/dev/null 2>&1; then
    gh release upload "$TAG" "$ZIP_PATH" --clobber
  else
    gh release create "$TAG" "$ZIP_PATH" \
      --title "MT MCP ${VERSION}" \
      --notes "$NOTES"
  fi
  echo "Published https://github.com/mt-gareth/mt-mcp/releases/tag/${TAG}"
else
  echo "Dry build only. Publish with: bin/build-release.sh --publish"
fi
