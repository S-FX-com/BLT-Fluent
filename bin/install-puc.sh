#!/usr/bin/env bash
#
# Install Plugin Update Checker into vendor/plugin-update-checker.
#
# The library is not committed to this repository. Run this once after cloning,
# and again before building a release zip.
#
# Usage: bin/install-puc.sh [version]

set -euo pipefail

VERSION="${1:-v5.6}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${ROOT}/vendor/plugin-update-checker"

if [[ -f "${TARGET}/plugin-update-checker.php" ]]; then
	echo "Already installed: ${TARGET}"
	exit 0
fi

if command -v composer >/dev/null 2>&1; then
	echo "Installing via composer..."
	( cd "${ROOT}" && composer install --no-dev --no-interaction )

	if [[ -f "${ROOT}/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php" ]]; then
		echo "Installed at vendor/yahnis-elsts/plugin-update-checker (the plugin looks in both locations)."
		exit 0
	fi
fi

echo "Falling back to a tarball download of ${VERSION}..."
mkdir -p "${TARGET}"
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

curl -fsSL "https://github.com/YahnisElsts/plugin-update-checker/archive/refs/tags/${VERSION}.tar.gz" -o "${TMP}/puc.tar.gz"
tar -xzf "${TMP}/puc.tar.gz" -C "${TMP}"
cp -R "${TMP}"/plugin-update-checker-*/. "${TARGET}/"

echo "Installed at ${TARGET}"
