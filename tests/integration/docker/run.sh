#!/usr/bin/env bash
#
# One-shot E2E test harness for the Panopticon Connector for WordPress.
#
# Provisions a throwaway WordPress site in Docker (Apache + PHP-FPM + MySQL, on the shared akeeba-e2e/* images),
# installs the Connector package into it, and runs the PHPUnit-over-HTTP suite against the Connector's REST API, the
# way Panopticon talks to it. Every run starts from a clean slate and tears the stack down on every exit path.
#
# What it does, in order:
#   1. Scrub any previous stack, volumes and web root.
#   2. Download WordPress with buildfiles' getcms, check it supports the PHP version under test.
#   3. Bring up the stack; install WordPress with WP-CLI.
#   4. Build the Connector with `phing git` (or take an existing package) and install and activate it.
#   5. Install the test fixtures (mu-plugins) and read the Connector's API token.
#   6. Run the PHPUnit E2E suite.
#
# Usage:  ./run.sh [options] [-- <extra phpunit args>]
#   -w, --wp=VERSION       Override WP_VERSION for one run (e.g. 7.1, 7.0, 7.0.8)
#   -p, --php=VERSION      Override PHP_VERSION for one run (e.g. 7.4, 8.5)
#       --matrix           Run every WordPress:PHP pair of matrix.env, one full scrub-provision-test-teardown cycle
#                          each. Not compatible with --keep-containers.
#       --filter=PATTERN   Only run PHPUnit tests matching PATTERN
#       --skip-build       Do not run `phing git`; install the newest package already in release/
#       --package=ZIP      Install this Connector package instead of building one (implies --skip-build). Use it to
#                          run the suite against an older release, e.g. to see a test fail before a fix.
#       --keep-containers  Leave the stack running afterwards (re-run the tests against it in seconds)
#       --down             Tear everything down and exit
#       --no-tests         Provision the site but run no tests (the stack stays up)
#       --reset-env        Back up .env to .env.bak and recreate it from env.dist
#   -h, --help             Show this help
#
# Re-running the tests against a stack left up by --keep-containers or --no-tests:
#   phpunit -c phpunit-integration.xml
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELF_PATH="${SCRIPT_DIR}/$(basename "${BASH_SOURCE[0]}")"
INTEGRATION_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${INTEGRATION_DIR}/../.." && pwd)"
RELEASE_DIR="${REPO_ROOT}/release"
FIXTURES_DIR="${INTEGRATION_DIR}/fixtures"
WWW_DIR="${SCRIPT_DIR}/www"
BUILDFILES_DIR="${REPO_ROOT}/../buildfiles"
E2E_IMAGES="${BUILDFILES_DIR}/docker/e2e/images.sh"
GETCMS="${BUILDFILES_DIR}/getcms/getcms.php"

cd "${SCRIPT_DIR}"

# ---------------------------------------------------------------------------
# Pretty logging
# ---------------------------------------------------------------------------
if [ -t 1 ]; then C_B='\033[0;34m'; C_G='\033[0;32m'; C_R='\033[0;31m'; C_Y='\033[0;33m'; C_0='\033[0m'; else C_B=; C_G=; C_R=; C_Y=; C_0=; fi
log()  { printf "${C_B}==>${C_0} %s\n" "$*"; }
ok()   { printf "${C_G}  ok${C_0} %s\n" "$*"; }
warn() { printf "${C_Y}  !!${C_0} %s\n" "$*"; }
die()  { printf "${C_R}ERROR:${C_0} %s\n" "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Tooling
# ---------------------------------------------------------------------------
if docker compose version >/dev/null 2>&1; then
	DC() { docker compose "$@"; }
elif command -v docker-compose >/dev/null 2>&1; then
	DC() { docker-compose "$@"; }
else
	die "Docker Compose is not available. Install Docker Desktop or the compose plugin."
fi
command -v docker >/dev/null 2>&1 || die "Docker is not installed or not on PATH."
command -v phpunit >/dev/null 2>&1 || die "phpunit is not on PATH. Install it globally (composer global require phpunit/phpunit) and put Composer's global bin directory on your PATH."
command -v php >/dev/null 2>&1 || die "php is not on PATH."

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
WP_OVERRIDE=""
PHP_OVERRIDE=""
SKIP_BUILD=0
PACKAGE=""
ONLY_DOWN=0
RUN_TESTS=1
KEEP_CONTAINERS=0
MATRIX_MODE=0
RESET_ENV=0
PHPUNIT_ARGS=()

while [ $# -gt 0 ]; do
	case "$1" in
		-w|--wp)          WP_OVERRIDE="$2"; shift 2;;
		--wp=*)           WP_OVERRIDE="${1#*=}"; shift;;
		-p|--php)         PHP_OVERRIDE="$2"; shift 2;;
		--php=*)          PHP_OVERRIDE="${1#*=}"; shift;;
		--filter)         PHPUNIT_ARGS+=("--filter=$2"); shift 2;;
		--filter=*)       PHPUNIT_ARGS+=("--filter=${1#*=}"); shift;;
		--skip-build)     SKIP_BUILD=1; shift;;
		--package)        PACKAGE="$2"; SKIP_BUILD=1; shift 2;;
		--package=*)      PACKAGE="${1#*=}"; SKIP_BUILD=1; shift;;
		--down)           ONLY_DOWN=1; shift;;
		--no-tests)       RUN_TESTS=0; shift;;
		--keep-containers) KEEP_CONTAINERS=1; shift;;
		--matrix)         MATRIX_MODE=1; shift;;
		--reset-env)      RESET_ENV=1; shift;;
		-h|--help)        sed -n '2,34p' "${SELF_PATH}" | sed 's/^# \{0,1\}//'; exit 0;;
		--)               shift; while [ $# -gt 0 ]; do PHPUNIT_ARGS+=("$1"); shift; done;;
		*)                die "Unknown option: $1 (use --help)";;
	esac
done

[ "${MATRIX_MODE}" -eq 1 ] && [ "${KEEP_CONTAINERS}" -eq 1 ] \
	&& die "--matrix and --keep-containers are contradictory: the matrix tears down between every pair by design."

if [ -n "${PACKAGE}" ]; then
	# Relative to where the operator ran the script from, not to this directory.
	case "${PACKAGE}" in /*) ;; *) PACKAGE="${OLDPWD:-${PWD}}/${PACKAGE}";; esac
	[ -f "${PACKAGE}" ] || die "--package: ${PACKAGE} does not exist."
fi

# ---------------------------------------------------------------------------
# Environment: .env from env.dist, then the committed matrix (which .env cannot shadow)
# ---------------------------------------------------------------------------
if [ "${RESET_ENV}" -eq 1 ]; then
	[ -f .env ] && cp .env .env.bak && log "Backed up .env to .env.bak"
	cp env.dist .env
	ok ".env reset from env.dist"
fi

[ -f .env ] || { cp env.dist .env; log "Created .env from env.dist"; }

# Add any key env.dist gained since this .env was created, without touching the ones already there.
while IFS='=' read -r distkey _; do
	case "${distkey}" in ''|\#*) continue;; esac
	grep -qE "^${distkey}=" .env 2>/dev/null && continue
	distline="$(grep -E "^${distkey}=" env.dist | head -1)"
	[ -n "${distline}" ] || continue
	printf '\n%s\n' "${distline}" >> .env
	log "Added missing .env key from env.dist: ${distkey}"
done < env.dist

set -a
. ./.env
. ./matrix.env
set +a
[ -n "${WP_OVERRIDE}" ]  && export WP_VERSION="${WP_OVERRIDE}"
[ -n "${PHP_OVERRIDE}" ] && export PHP_VERSION="${PHP_OVERRIDE}"

: "${WP_VERSION:?WP_VERSION is not set}"
: "${PHP_VERSION:?PHP_VERSION is not set}"
: "${WP_MATRIX:?WP_MATRIX is not set}"
: "${APACHE_VERSION:=2.4}"
: "${MYSQL_VERSION:=8.4}"
: "${HTTP_PORT:=8230}"
: "${DB_PORT:=33323}"
: "${DB_NAME:=integration}"
: "${DB_USER:=integration}"
: "${DB_PASSWORD:=integration}"
: "${DB_PREFIX:=wp_}"
export APACHE_VERSION MYSQL_VERSION HTTP_PORT DB_PORT

SITE_URL="http://localhost:${HTTP_PORT}"

# WP-CLI runs as www-data, so that what it writes to the bind-mounted web root is owned like everything else there.
wp_cli() { DC exec -T -u www-data php wp --path=/var/www/html "$@"; }

# ---------------------------------------------------------------------------
# Teardown
# ---------------------------------------------------------------------------
remove_www() {
	if [ -d "${WWW_DIR}" ]; then
		rm -rf "${WWW_DIR}" 2>/dev/null || \
			docker run --rm -v "${SCRIPT_DIR}:/work" alpine:3 rm -rf /work/www || true
	fi
}

scrub() {
	log "Scrubbing existing containers, volumes and web root"
	DC down --volumes --remove-orphans >/dev/null 2>&1 || true
	remove_www
	rm -f "${INTEGRATION_DIR}/config.php"
	mkdir -p "${WWW_DIR}"
	ok "Clean slate"
}

STACK_DOWN=0
teardown() {
	log "Bringing the Docker stack down"
	DC down --volumes --remove-orphans >/dev/null 2>&1 || true
	STACK_DOWN=1
	ok "Stack down"
}

# Matrix mode: one line per finished pair, and whether the summary was printed already.
RESULTS=""
SUMMARY_PRINTED=0
print_matrix_summary() {
	SUMMARY_PRINTED=1
	echo
	echo "Matrix results"
	if [ -n "${RESULTS}" ]; then
		printf '%s' "${RESULTS}"
	else
		echo "  (no pair finished)"
	fi
}

FINISHED=0
finish() {
	FINISHED=1
	exit "$1"
}

on_exit() {
	local status=$?

	trap - EXIT INT TERM

	# A fatal error (or Ctrl-C) between pairs must not lose the results of the pairs which did run.
	if [ "${MATRIX_MODE}" -eq 1 ] && [ "${SUMMARY_PRINTED}" -eq 0 ]; then
		print_matrix_summary || true
	fi

	# Bash 3.2 (macOS) hands an EXIT trap $? = 0 after a fatal shell error such as a failed ${VAR:?} check, then
	# exits with the trap's own status. A run which did not end through finish() has failed.
	[ "${status}" -ne 0 ] || [ "${FINISHED}" -eq 1 ] || status=1

	if [ "${KEEP_CONTAINERS}" -eq 1 ] || [ "${RUN_TESTS}" -eq 0 ]; then
		exit "${status}"
	fi

	[ "${STACK_DOWN}" -eq 1 ] || teardown

	if [ "${status}" -eq 0 ]; then
		remove_www
		rm -f "${INTEGRATION_DIR}/config.php"
	else
		warn "Left the web root in place for inspection: ${WWW_DIR}"
	fi

	exit "${status}"
}

if [ "${ONLY_DOWN}" -eq 1 ]; then
	scrub
	remove_www
	ok "Everything torn down."
	exit 0
fi

# ---------------------------------------------------------------------------
# Version helpers
# ---------------------------------------------------------------------------
# The oldest WordPress version this suite can run against: the one the Connector's plugin header declares.
MIN_WP_VERSION="$(sed -nE 's/^[[:space:]]*\*?[[:space:]]*Requires at least:[[:space:]]*([0-9.]+).*/\1/p' "${REPO_ROOT}/panopticon.php" | head -1)"
[ -n "${MIN_WP_VERSION}" ] || die "Could not read 'Requires at least' from panopticon.php."

version_lt() {
	[ "$1" = "$2" ] && return 1
	[ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" = "$1" ]
}

assert_supported_version() {
	version_lt "$1" "${MIN_WP_VERSION}" \
		&& die "WordPress $1 is not supported. The Connector requires WordPress ${MIN_WP_VERSION} or later."
	return 0
}

WP_RESOLVED=""
WP_PHP_MINIMUM=""
read_wordpress_version() {
	local f="${WWW_DIR}/wp-includes/version.php"

	[ -f "${f}" ] || die "wp-includes/version.php is missing from the extracted WordPress package."
	WP_RESOLVED="$(sed -nE "s/^\\\$wp_version[[:space:]]*=[[:space:]]*'([^']+)'.*/\1/p" "${f}" | head -1)"
	WP_PHP_MINIMUM="$(sed -nE "s/^\\\$required_php_version[[:space:]]*=[[:space:]]*'([^']+)'.*/\1/p" "${f}" | head -1)"
	[ -n "${WP_RESOLVED}" ] || die "Could not read \$wp_version from ${f}."
	[ -n "${WP_PHP_MINIMUM}" ] || die "Could not read \$required_php_version from ${f}."
}

check_php_compatibility() {
	if version_lt "${PHP_VERSION}" "${WP_PHP_MINIMUM}"; then
		[ "${MATRIX_MODE}" -eq 1 ] && return 1
		die "WordPress ${WP_RESOLVED} requires PHP >= ${WP_PHP_MINIMUM}; PHP_VERSION is ${PHP_VERSION}."
	fi

	return 0
}

# ---------------------------------------------------------------------------
# Provisioning
# ---------------------------------------------------------------------------
acquire_wordpress() {
	log "Acquiring WordPress ${WP_VERSION}"
	[ -f "${GETCMS}" ] || die "${GETCMS} not found. Check out akeeba/buildfiles next to this repository and run composer install there."
	php "${GETCMS}" download "${WP_VERSION}" "${WWW_DIR}" --cms=wordpress >/dev/null \
		|| die "Could not download and extract WordPress ${WP_VERSION} with getcms."
	read_wordpress_version
	ok "WordPress ${WP_RESOLVED} extracted (requires PHP ${WP_PHP_MINIMUM})"
}

bring_up_stack() {
	log "Starting the stack (PHP ${PHP_VERSION}, Apache ${APACHE_VERSION}, MySQL ${MYSQL_VERSION})"
	[ -x "${E2E_IMAGES}" ] || die "${E2E_IMAGES} not found. Check out akeeba/buildfiles next to this repository."
	"${E2E_IMAGES}" ensure php "${PHP_VERSION}"
	"${E2E_IMAGES}" ensure web "${APACHE_VERSION}"

	STACK_DOWN=0
	# The php service waits for the database's health check, so the database accepts connections once this returns.
	# Compose reports its progress on stderr; only show it when something went wrong.
	local out
	out="$(mktemp)"
	DC up -d --wait >"${out}" 2>&1 || { cat "${out}" >&2; rm -f "${out}"; die "The Docker stack did not come up."; }
	rm -f "${out}"
	ok "Stack is up"
}

install_wordpress() {
	log "Installing WordPress with WP-CLI"
	wp_cli config create --dbhost=db --dbname="${DB_NAME}" --dbuser="${DB_USER}" --dbpass="${DB_PASSWORD}" \
		--dbprefix="${DB_PREFIX}" --skip-check >/dev/null

	# A throwaway site must not change under the tests: no automatic updates, and no WP-Cron (which also runs them).
	# FS_METHOD: never fall back to asking for FTP credentials, which a REST request cannot answer.
	wp_cli config set WP_AUTO_UPDATE_CORE false --raw >/dev/null
	wp_cli config set AUTOMATIC_UPDATER_DISABLED true --raw >/dev/null
	wp_cli config set DISABLE_WP_CRON true --raw >/dev/null
	wp_cli config set FS_METHOD direct >/dev/null

	wp_cli core install --url="${SITE_URL}" --title="${SITE_TITLE}" --admin_user="${ADMIN_USERNAME}" \
		--admin_password="${ADMIN_PASSWORD}" --admin_email="${ADMIN_EMAIL}" --skip-email >/dev/null

	# Pretty permalinks, so the REST API lives under /wp-json/ like on a real site. WP-CLI only writes .htaccess when
	# it is told Apache has mod_rewrite, so write WordPress' standard rules here.
	wp_cli rewrite structure '/%postname%/' >/dev/null
	cat > "${WWW_DIR}/.htaccess" <<'HTACCESS'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTACCESS

	ok "WordPress ${WP_RESOLVED} installed at ${SITE_URL}"
}

build_connector() {
	if [ "${SKIP_BUILD}" -eq 1 ]; then
		warn "Skipping the build; using ${PACKAGE:-the newest package in release/}"
		return 0
	fi
	command -v phing >/dev/null 2>&1 || die "phing is not on PATH. Use --skip-build to reuse an existing package."
	log "Building the Connector with 'phing git'"
	( cd "${REPO_ROOT}" && phing git ) || die "phing git failed."
	ok "Connector built"
}

install_connector() {
	local pkg="${PACKAGE}"

	if [ -z "${pkg}" ]; then
		pkg="$(ls -t "${RELEASE_DIR}"/panopticon-*.zip 2>/dev/null | head -1 || true)"
		[ -n "${pkg}" ] || die "No panopticon-*.zip in ${RELEASE_DIR}. Build first (drop --skip-build)."
	fi

	log "Installing $(basename "${pkg}")"
	cp "${pkg}" "${WWW_DIR}/e2e-panopticon.zip"
	wp_cli plugin install /var/www/html/e2e-panopticon.zip --activate >/dev/null \
		|| { rm -f "${WWW_DIR}/e2e-panopticon.zip"; die "Installing the Connector failed."; }
	rm -f "${WWW_DIR}/e2e-panopticon.zip"

	wp_cli plugin is-active panopticon || die "The Connector is not active after installing it."
	ok "Connector $(wp_cli plugin get panopticon --field=version) installed and activated"
}

install_fixtures() {
	log "Installing the test fixtures"
	mkdir -p "${WWW_DIR}/wp-content/mu-plugins"
	cp "${FIXTURES_DIR}"/mu-plugins/*.php "${WWW_DIR}/wp-content/mu-plugins/"
	ok "Fixtures installed"
}

API_TOKEN=""
read_api_token() {
	API_TOKEN="$(wp_cli panopticon token get --porcelain)"
	[ -n "${API_TOKEN}" ] || die "Could not read the Connector's API token."
}

# config.php: everything the tests need to know about the site. Git-ignored, regenerated on every provisioning run.
write_e2e_config() {
	php -r '
		$config = [
			"site"      => ["url" => $argv[1], "root" => $argv[2]],
			"docker"    => $argv[3],
			"token"     => $argv[4],
			"wordpress" => $argv[5],
			"fixtures"  => $argv[6],
		];
		file_put_contents($argv[7], "<?php\n// Generated by tests/integration/docker/run.sh. Do not edit.\n\n\$testConfiguration = " . var_export($config, true) . ";\n");
	' "${SITE_URL}" "${WWW_DIR}" "${SCRIPT_DIR}" "${API_TOKEN}" "${WP_RESOLVED}" "${FIXTURES_DIR}" "${INTEGRATION_DIR}/config.php"
}

# ---------------------------------------------------------------------------
# Test runner
# ---------------------------------------------------------------------------
PHPUNIT_SUMMARY=""
run_phpunit() {
	local out status
	out="$(mktemp)"

	set +e
	if [ "${#PHPUNIT_ARGS[@]}" -gt 0 ]; then
		phpunit -c "${REPO_ROOT}/phpunit-integration.xml" "${PHPUNIT_ARGS[@]}" 2>&1 | tee "${out}"
	else
		phpunit -c "${REPO_ROOT}/phpunit-integration.xml" 2>&1 | tee "${out}"
	fi
	status=${PIPESTATUS[0]}
	set -e

	PHPUNIT_SUMMARY="$(grep -E '^(OK \(|Tests:)' "${out}" | tail -1 || true)"
	rm -f "${out}"

	return "${status}"
}

run_tests() {
	[ "${RUN_TESTS}" -eq 1 ] || { warn "Skipping tests (--no-tests). Site is up at ${SITE_URL}"; return 0; }

	log "Running the PHPUnit E2E suite"
	run_phpunit
}

# ---------------------------------------------------------------------------
# One scrub -> provision -> test -> teardown cycle. Returns 0 PASS, 1 FAIL, 2 SKIP (incompatible pair).
# ---------------------------------------------------------------------------
run_one_pair() {
	export WP_VERSION="$1"
	export PHP_VERSION="$2"

	log "Panopticon Connector for WordPress E2E harness -- WordPress ${WP_VERSION}, PHP ${PHP_VERSION}"
	scrub
	acquire_wordpress
	assert_supported_version "${WP_RESOLVED}"

	if ! check_php_compatibility; then
		warn "SKIP: WordPress ${WP_RESOLVED} requires PHP >= ${WP_PHP_MINIMUM}; PHP_VERSION is ${PHP_VERSION}."
		teardown
		return 2
	fi

	build_connector
	bring_up_stack
	install_wordpress
	install_connector
	install_fixtures
	read_api_token
	write_e2e_config

	local status=0
	run_tests || status=$?

	if [ "${MATRIX_MODE}" -eq 1 ]; then
		teardown
	elif [ "${KEEP_CONTAINERS}" -eq 1 ] || [ "${RUN_TESTS}" -eq 0 ]; then
		ok "Done. The site remains up at ${SITE_URL}. Tear it down with: ${SELF_PATH} --down"
	else
		teardown
	fi

	return "${status}"
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [ "${MATRIX_MODE}" -eq 1 ]; then
	FAILED=0

	for pair in ${WP_MATRIX}; do
		case "${pair}" in *:*) ;; *) die "Malformed WP_MATRIX entry '${pair}'. Expected WORDPRESS:PHP.";; esac
	done

	# Build once for the whole matrix, not once per pair.
	build_connector
	SKIP_BUILD=1

	pair_state="$(mktemp)"

	for pair in ${WP_MATRIX}; do
		# Each pair runs in its own subshell, started with errexit on and NOT as the operand of ||, && or if: in those
		# contexts bash ignores `set -e` for everything inside, so a failed provisioning step would be swallowed. A
		# step failing, or a die, ends this pair only; the EXIT trap tears its stack down and records what the summary
		# needs (the subshell cannot set the parent's variables).
		: > "${pair_state}"
		pair_status=0
		set +e
		(
			set -e
			PAIR_OK=0
			trap 'rc=$?
				[ "${rc}" -ne 0 ] || [ "${PAIR_OK}" -eq 1 ] || rc=1
				printf "%s\n%s\n" "${WP_RESOLVED:-${pair%%:*}}" "${PHPUNIT_SUMMARY}" > "${pair_state}"
				[ "${STACK_DOWN}" -eq 1 ] || teardown
				exit "${rc}"' EXIT
			trap 'exit 130' INT
			trap 'exit 143' TERM
			PHPUNIT_SUMMARY=""
			run_one_pair "${pair%%:*}" "${pair##*:}"
			PAIR_OK=1
		)
		pair_status=$?
		set -e

		case "${pair_status}" in
			0) verdict=PASS;;
			2) verdict=SKIP;;
			*) verdict=FAIL; FAILED=1;;
		esac

		pair_wp=""
		pair_summary=""
		{ IFS= read -r pair_wp; IFS= read -r pair_summary; } < "${pair_state}" || true

		RESULTS="${RESULTS}$(printf '  WordPress %-10s PHP %-6s %s  %s' "${pair_wp:-${pair%%:*}}" "${pair##*:}" "${verdict}" "${pair_summary}")
"
		# Ctrl-C ended the pair: stop the matrix too (the summary is printed on the way out).
		[ "${pair_status}" -ne 130 ] && [ "${pair_status}" -ne 143 ] || { FAILED=1; break; }
	done

	rm -f "${pair_state}"
	print_matrix_summary

	finish "${FAILED}"
fi

run_one_pair "${WP_VERSION}" "${PHP_VERSION}"
finish $?
