# End-to-end tests

The E2E suite drives the Panopticon Connector the way Panopticon does: over HTTP, against its REST API, on a real,
throwaway WordPress site running in Docker. Nothing boots WordPress in-process. The unit tests (`tests/*Test.php`,
`phpunit.xml.dist`) are a separate suite.

## Requirements

* Docker, with Compose v2.
* PHP and PHPUnit on the host, PHPUnit installed globally (`composer global require phpunit/phpunit`), with Composer's
  global `bin` directory on your `PATH`.
* [akeeba/buildfiles](https://github.com/akeeba/buildfiles) checked out next to this repository (`../buildfiles`), with
  `composer install` run there. It provides the Docker images (`docker/e2e/images.sh`), the WordPress downloader
  (`getcms/`) and the Phing build.
* Phing and GNU gettext (`msgfmt`) to build the Connector, unless you pass `--skip-build` or `--package`.

## Quick start

```bash
composer test                       # unit suite, then this one
composer test:integration           # just this one; same as tests/integration/docker/run.sh
tests/integration/docker/run.sh --keep-containers    # leave the site up afterwards…
phpunit -c phpunit-integration.xml                   # …and re-run the tests against it in seconds
tests/integration/docker/run.sh --down               # tear it down
```

Every run starts from a clean slate and tears the stack down on every exit path, Ctrl-C included. After a failed run
the web root (`docker/www`) is left in place for inspection; the next run scrubs it.

The site is at http://localhost:8230 while it is up (WordPress admin: `admin` / `test`, see `docker/env.dist`).

## What `run.sh` does

1. Scrubs any previous stack, volumes, web root and `config.php`.
2. Downloads WordPress with buildfiles' `getcms` into `docker/www`, and checks it supports the PHP version under test.
3. Brings the stack up on the shared `akeeba-e2e/php` and `akeeba-e2e/web` images, plus MySQL. The php image carries
   WP-CLI, which runs as `www-data` so that the files it writes are owned like the rest of the web root.
4. Installs WordPress with WP-CLI: pretty permalinks (the API lives under `/wp-json/`), no automatic updates, no
   WP-Cron, `FS_METHOD` direct.
5. Builds the Connector with `phing git` and installs and activates the package from `release/`.
6. Installs the fixtures (`fixtures/mu-plugins`) and reads the Connector's API token with
   `wp panopticon token get --porcelain`.
7. Writes `config.php` (git-ignored) and runs PHPUnit with `phpunit-integration.xml`.

`phing git` rewrites the version in `panopticon.php` and `readme.txt`, and recompiles the `.mo` files, in the working
tree; don't commit those changes. `--skip-build` reuses the package the last build left in `release/`.

## Options

| Option              | What it does                                                                                         |
|---------------------|------------------------------------------------------------------------------------------------------|
| `--wp=VERSION`      | WordPress to test, for one run: a branch (`7.0`) or an exact version (`7.0.8`).                       |
| `--php=VERSION`     | PHP to test, for one run. It must be in `../buildfiles/docker/e2e/versions`.                          |
| `--matrix`          | Run every pair in `docker/matrix.env`, one full provision-test-teardown cycle each, then a summary.  |
| `--filter=PATTERN`  | Passed to PHPUnit.                                                                                   |
| `--skip-build`      | Don't run `phing git`; install the package the last build left in `release/`.                        |
| `--package=ZIP`     | Install this package instead (implies `--skip-build`). See "Seeing a test fail before a fix".        |
| `--keep-containers` | Leave the stack up afterwards.                                                                       |
| `--no-tests`        | Provision only; the stack stays up.                                                                  |
| `--down`            | Tear everything down and exit.                                                                       |
| `--reset-env`       | Back up `docker/.env` to `.env.bak` and recreate it from `env.dist`.                                 |
| `-- ARGS`           | Everything after `--` is passed to PHPUnit.                                                          |

`docker/.env` (git-ignored, created from `env.dist`) holds the ports, database and admin credentials. The versions under
test are not there: they are in the committed `docker/matrix.env`, which `run.sh` reads after `.env`.

## Why these versions

`docker/matrix.env` tests two environments:

| WordPress           | PHP | Why                                                                                   |
|---------------------|-----|---------------------------------------------------------------------------------------|
| 7.0 (latest 7.0.x)  | 7.4 | The previous WordPress branch, on the oldest PHP it supports.                          |
| 7.1 (latest 7.1.x)  | 8.5 | The latest WordPress branch, on the newest stable PHP. What a plain `run.sh` tests. |

**Only the latest WordPress branch and the one before it.** That is the standing policy for Akeeba WordPress
software, and it is narrower than the Joomla E2E suites, which test from the oldest Joomla version the extension
installs on.

The Connector itself claims more: WordPress 5.0 and PHP 7.2 (`Requires at least` and `Requires PHP` in
`panopticon.php`). That range is **not** tested here:

* WordPress older than 7.0: add a pair to `WP_MATRIX`, e.g. `WP_MATRIX="6.9:7.4 7.0:7.4 7.1:8.5"`. Nothing else in
  `run.sh` assumes "the latest two". For a one-off run: `run.sh --wp=6.9 --php=7.4`.
* PHP 7.2 and 7.3: the shared images start at PHP 7.4. Testing them means adding them to
  `../buildfiles/docker/e2e/versions` first, which affects every project.

The Connector has no code paths gated on the WordPress version, so the two pairs mostly guard PHP compatibility (the
plugin's code must stay PHP 7.2-compatible) and changes in the WordPress APIs it calls. A green run on one pair proves
less than a green `--matrix`.

## Writing tests

* Test classes go in `src/Tests/` and extend `AbstractE2ETestCase`. Call the Connector with `$this->api()`, which sends
  the token in `X-Panopticon-Token` like Panopticon does (or `Authorization: Bearer`, `X-Joomla-Token`).
* Use WP-CLI (`self::wp()`) only to set up fixtures and to read the site's state, never for the behaviour under test.
* The site's PHP errors go to `docker/www/wp-content/e2e-php-errors.log`. `phpErrorLogPosition()` and `phpErrorsSince()`
  give you what a request logged; put it in assertion messages, since a fatal error's reply is an empty HTTP 500.
* Tests which update plugins or themes extend `AbstractUpdateTestCase`. It installs fixture extensions from
  `fixtures/plugins` and `fixtures/themes` (`##VERSION##` in their files becomes the version installed), and
  `offerUpdate()` builds the new version's package and offers it through `fixtures/mu-plugins/e2e-fake-updates.php`,
  so no update server is needed. Whatever a test installs is removed after it, and the original theme reactivated.

## Seeing a test fail before a fix

Build a package from the code before the fix and pass it with `--package`. For example, from the commit before
`3665be8` (GitHub issue #12):

```bash
mkdir -p /tmp/prefix/panopticon
git archive 3665be8^ includes languages panopticon.php readme.txt | tar -x -C /tmp/prefix/panopticon
cp -R vendor /tmp/prefix/panopticon/
(cd /tmp/prefix && zip -qr panopticon-prefix.zip panopticon)
tests/integration/docker/run.sh --package=/tmp/prefix/panopticon-prefix.zip --filter='PluginUpdate|ThemeUpdate'
```

`PluginUpdateTest` then fails for the plugin whose activation hook loads `upgrade.php`, and `ThemeUpdateTest` for the
site with a plugin which loads `upgrade.php` after every update. Both get HTTP 500 and the
`Cannot redeclare function show_message()` fatal error from the issue.
