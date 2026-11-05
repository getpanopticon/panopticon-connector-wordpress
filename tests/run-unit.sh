#!/usr/bin/env bash
#
# Runs the unit suite (composer test:unit).
#
# The unit tests load a few real WordPress files (WP_Filesystem_Direct, wp-admin/includes/misc.php, the upgrader
# skins), from the WordPress copy in the WP_ROOT environment variable. Without WP_ROOT, this downloads the WordPress
# branch the E2E suite tests by default (WP_VERSION in tests/integration/docker/matrix.env) into tests/.wordpress/, once,
# with buildfiles' getcms.
#
# Usage:  tests/run-unit.sh [phpunit arguments]
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

command -v phpunit >/dev/null 2>&1 || { echo "phpunit is not on PATH. Install it globally (composer global require phpunit/phpunit) and put Composer's global bin directory on your PATH." >&2; exit 1; }

if [ -z "${WP_ROOT:-}" ]; then
	WP_VERSION="$(sed -nE 's/^WP_VERSION=([0-9.]+).*/\1/p' "${REPO_ROOT}/tests/integration/docker/matrix.env" | head -1)"
	[ -n "${WP_VERSION}" ] || { echo "Could not read WP_VERSION from tests/integration/docker/matrix.env." >&2; exit 1; }
	WP_ROOT="${REPO_ROOT}/tests/.wordpress/${WP_VERSION}"

	if [ ! -f "${WP_ROOT}/wp-includes/version.php" ]; then
		GETCMS="${REPO_ROOT}/../buildfiles/getcms/getcms.php"
		[ -f "${GETCMS}" ] || { echo "${GETCMS} not found. Check out akeeba/buildfiles next to this repository and run composer install there, or set WP_ROOT." >&2; exit 1; }
		rm -rf "${WP_ROOT}"
		php "${GETCMS}" download "${WP_VERSION}" "${WP_ROOT}" --cms=wordpress
	fi
fi

export WP_ROOT
cd "${REPO_ROOT}"
exec phpunit "$@"
