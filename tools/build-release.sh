#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PROJECT_NAME="${PROJECT_NAME:-$(basename "$ROOT_DIR")}" 
SAFE_NAME="$(printf '%s' "$PROJECT_NAME" | tr '[:space:]' '-' | tr -cd 'A-Za-z0-9._-')"

VERSION="${VERSION:-}"
if [[ -z "$VERSION" ]]; then
  if [[ -f VERSION ]]; then
    VERSION="$(tr -d '\r\n ' < VERSION)"
  else
    VERSION="0.1.0"
  fi
fi

CHANNEL="${CHANNEL:-}"
if [[ -z "$CHANNEL" ]]; then
  if [[ -f CHANNEL ]]; then
    CHANNEL="$(tr -d '\r\n ' < CHANNEL)"
  else
    BRANCH_HINT="${GITHUB_REF_NAME:-$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)}"
    if [[ "$BRANCH_HINT" == "main" ]]; then
      CHANNEL="stable"
    else
      CHANNEL="dev"
    fi
  fi
fi

case "$CHANNEL" in
  dev|stable|hotfix|rc) ;;
  *) echo "Invalid CHANNEL '${CHANNEL}'. Expected one of: dev, stable, hotfix, rc" >&2; exit 1 ;;
esac

BRANCH="${GITHUB_REF_NAME:-$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)}"
COMMIT="${GITHUB_SHA:-$(git rev-parse --short HEAD 2>/dev/null || echo unknown)}"
SHORT_COMMIT="${COMMIT:0:7}"
BUILD_DATE="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

rm -rf build dist
mkdir -p build dist

PACKAGE_ROOT="build/${SAFE_NAME}"
mkdir -p "$PACKAGE_ROOT"

EXCLUDES=(
  --exclude='.git/'
  --exclude='.github/'
  --exclude='build/'
  --exclude='dist/'
  --exclude='tools/'
  --exclude='tests/'
  --exclude='docs/'
  --exclude='node_modules/'
  --exclude='vendor/bin/'
  --exclude='*.zip'
  --exclude='*.tar'
  --exclude='*.tar.gz'
  --exclude='*.bak'
  --exclude='*.tmp'
  --exclude='*.log'
  --exclude='.DS_Store'
  --exclude='Thumbs.db'
  --exclude='.env'
  --exclude='.env.*'
)

PACKAGE_TYPE="generic"
if [[ -d modules/addons || -d modules/servers ]]; then
  PACKAGE_TYPE="whmcs"
  mkdir -p "$PACKAGE_ROOT/modules"
  [[ -d modules/addons ]] && rsync -a "${EXCLUDES[@]}" modules/addons "$PACKAGE_ROOT/modules/"
  [[ -d modules/servers ]] && rsync -a "${EXCLUDES[@]}" modules/servers "$PACKAGE_ROOT/modules/"
else
  PACKAGE_TYPE="wordpress-or-generic"
  rsync -a "${EXCLUDES[@]}" ./ "$PACKAGE_ROOT/"
fi

cat > "$PACKAGE_ROOT/BUILD-MANIFEST.txt" <<MANIFEST
Project: ${PROJECT_NAME}
Version: ${VERSION}
Channel: ${CHANNEL}
Branch: ${BRANCH}
Commit: ${COMMIT}
Build Date UTC: ${BUILD_DATE}
Package Type: ${PACKAGE_TYPE}
Source: GitHub Actions clean build

Packaging Rules:
- Built from repository checkout only.
- Excludes git metadata, workflows, tools, docs, tests, build folders, dist folders, logs, backups, old ZIPs, and local env files.
- Includes deployable WHMCS module paths when modules/addons or modules/servers exist.
MANIFEST

printf '%s\n' "$VERSION" > "$PACKAGE_ROOT/VERSION"
printf '%s\n' "$CHANNEL" > "$PACKAGE_ROOT/CHANNEL"
[[ -f CHANGELOG.md ]] && cp CHANGELOG.md "$PACKAGE_ROOT/CHANGELOG.md"
[[ -f README.md ]] && cp README.md "$PACKAGE_ROOT/README.md"

PHP_FILES="$(find "$PACKAGE_ROOT" -type f -name '*.php' | sort)"
if [[ -n "$PHP_FILES" ]]; then
  while IFS= read -r file; do
    php -l "$file" >/dev/null
  done <<< "$PHP_FILES"
fi

ZIP_NAME="${SAFE_NAME}_${VERSION}_${CHANNEL}_${SHORT_COMMIT}.zip"
(
  cd build
  zip -qr "../dist/${ZIP_NAME}" "${SAFE_NAME}"
)

unzip -l "dist/${ZIP_NAME}" > "dist/${SAFE_NAME}_${VERSION}_${CHANNEL}_${SHORT_COMMIT}_contents.txt"

echo "Built dist/${ZIP_NAME}"
