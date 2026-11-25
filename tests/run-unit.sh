#!/usr/bin/env bash
#
# Runs the unit suite (composer test:unit).
#
# The unit tests load a few real WordPress files (WP_Filesystem_Direct, wp-admin/includes/misc.php, the upgrader
# skins), from the WordPress copy in the WP_ROOT environment variable. Without WP_ROOT, this uses the latest release of
# the WordPress branch the E2E suite tests by default (WP_VERSION in tests/integration/docker/matrix.env). It is looked
# up before every run; tests/.wordpress/ only saves downloading it again. Offline, point WP_ROOT at one of those copies.
#
# Usage:  tests/run-unit.sh [phpunit arguments]
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

command -v phpunit >/dev/null 2>&1 || { echo "phpunit is not on PATH. Install it globally (composer global require phpunit/phpunit) and put Composer's global bin directory on your PATH." >&2; exit 1; }

if [ -z "${WP_ROOT:-}" ]; then
	WP_VERSION="$(sed -nE 's/^WP_VERSION=([0-9.]+).*/\1/p' "${REPO_ROOT}/tests/integration/docker/matrix.env" | head -1)"
	[ -n "${WP_VERSION}" ] || { echo "Could not read WP_VERSION from tests/integration/docker/matrix.env." >&2; exit 1; }
	log() { echo "$*"; }
	ok() { echo "$*"; }
	die() { echo "$*" >&2; exit 1; }
	LIBRARY="${REPO_ROOT}/../buildfiles/docker/e2e/wordpress/acquire.sh"
	[ -f "${LIBRARY}" ] || die "${LIBRARY} not found. Update the akeeba/buildfiles checkout next to this repository, or set WP_ROOT."
	# shellcheck source=/dev/null
	. "${LIBRARY}"
	wordpress_resolve "${WP_VERSION}"
	WP_ROOT="${REPO_ROOT}/tests/.wordpress/${WP_RESOLVED}"

	# A branch directory (tests/.wordpress/7.1) from before releases were looked up has no marker, and so cannot pass for
	# the release of the same name.
	if [ ! -f "${WP_ROOT}/.release-${WP_RESOLVED}" ]; then
		rm -rf "${WP_ROOT}"
		php "${REPO_ROOT}/../buildfiles/getcms/getcms.php" download "${WP_RESOLVED}" "${WP_ROOT}" --cms=wordpress
		: > "${WP_ROOT}/.release-${WP_RESOLVED}"
	fi
fi

export WP_ROOT
cd "${REPO_ROOT}"
exec phpunit "$@"
