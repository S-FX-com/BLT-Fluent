#!/usr/bin/env bash
#
# Build a distributable zip: build/blt-fluent-<version>.zip
#
# Attach the zip as a GitHub release asset on a tag matching the Version header
# (for example v0.1.0); Plugin Update Checker is configured to prefer release
# assets and to ignore pre-releases.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "${ROOT}/blt-fluent.php" | sed -E 's/.*Version:[[:space:]]*//')"
BUILD="${ROOT}/build"
STAGE="${BUILD}/blt-fluent"

if [[ -z "${VERSION}" ]]; then
	echo "Could not read the Version header from blt-fluent.php" >&2
	exit 1
fi

if [[ ! -f "${ROOT}/vendor/plugin-update-checker/plugin-update-checker.php" \
	&& ! -f "${ROOT}/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php" ]]; then
	echo "plugin-update-checker is missing. Run bin/install-puc.sh first." >&2
	exit 1
fi

rm -rf "${STAGE}"
mkdir -p "${STAGE}"

rsync -a \
	--exclude '.git' \
	--exclude '.github' \
	--exclude '.gitignore' \
	--exclude 'bin' \
	--exclude 'build' \
	--exclude 'docs' \
	--exclude 'tests' \
	--exclude 'CLAUDE.md' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude 'README.md' \
	"${ROOT}/" "${STAGE}/"

( cd "${BUILD}" && rm -f "blt-fluent-${VERSION}.zip" && zip -qr "blt-fluent-${VERSION}.zip" "blt-fluent" )

echo "Built ${BUILD}/blt-fluent-${VERSION}.zip"
