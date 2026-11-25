Cache of WordPress installation packages (wordpress-X.Y.Z.zip), so they are not
downloaded again. The test site is installed from them.

They do NOT decide which version gets tested. docker/run.sh looks up the release
asked for (--wp, --matrix, or WP_VERSION), then uses the package here if present
and downloads it if not.
