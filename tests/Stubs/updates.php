<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

/**
 * WordPress stand-ins for the plugin/theme update tests (UpdatesShowMessageTest).
 *
 * Only loaded inside the separate processes those tests run in, because they declare global functions, and the tests
 * load WordPress' real wp-admin/includes/misc.php.
 *
 * The upgrader skins are WordPress' real classes. The upgraders are doubles that behave like WordPress' own from the
 * skin's point of view (header, feedback, footer), without touching the filesystem.
 */

// WordPress' real upgrader skins
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader-skin.php';
require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader-skin.php';
require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

const PANOPTICON_TEST_PLUGIN = 'foo/foo.php';
const PANOPTICON_TEST_THEME  = 'bar';

/**
 * Upgrader double. Calls the skin like WordPress' upgraders do, then runs the test's callback, if any, which stands in
 * for whatever third-party code runs during the update.
 *
 * $GLOBALS['panopticon_test_upgrade_outcome'] picks what WordPress' upgrader would do:
 * - "success" (default): the update is installed.
 * - "fs_failure": WordPress cannot connect to the filesystem. Nothing is installed, and no error is reported.
 * - "package_error": the package is invalid. The skin and the result report the error.
 * - "up_to_date": WordPress has no update for the extension.
 */
abstract class Panopticon_Test_Upgrader
{
	public $skin;

	public $strings = [];

	public function __construct($skin)
	{
		$this->skin = $skin;
		$this->skin->set_upgrader($this);
	}

	/**
	 * Like WordPress' upgrade(), which returns true on success, and false or a WP_Error otherwise.
	 */
	public function upgrade($extension)
	{
		$this->skin->header();

		switch (self::outcome())
		{
			case 'fs_failure':
				$this->skin->footer();

				return false;

			case 'up_to_date':
				$this->skin->error('up_to_date');
				$this->skin->footer();

				return false;

			case 'package_error':
				$result = self::packageError();
				$this->skin->error($result);
				$this->skin->footer();

				return $result;
		}

		$this->skin->feedback('Update installed successfully.');
		$this->skin->footer();
		$this->runCallback();

		return true;
	}

	/**
	 * Like WordPress' bulk_upgrade(): false when it could not connect to the filesystem, otherwise an array keyed by
	 * extension with WP_Upgrader::run()'s result, or true when there was no update for it.
	 */
	public function bulk_upgrade($extensions)
	{
		$this->skin->header();

		if (self::outcome() === 'fs_failure')
		{
			$this->skin->footer();

			return false;
		}

		$results = [];

		foreach ($extensions as $extension)
		{
			switch (self::outcome())
			{
				case 'up_to_date':
					$this->skin->feedback('up_to_date');
					$results[$extension] = true;
					break;

				case 'package_error':
					$results[$extension] = self::packageError();
					$this->skin->error($results[$extension]);
					break;

				default:
					$this->skin->feedback('Update installed successfully.');
					$results[$extension] = ['destination_name' => dirname($extension)];
					break;
			}
		}

		$this->runCallback();
		$this->skin->footer();

		return $results;
	}

	private static function outcome()
	{
		return $GLOBALS['panopticon_test_upgrade_outcome'] ?? 'success';
	}

	private static function packageError()
	{
		return new WP_Error('incompatible_archive_no_plugins', 'The package could not be installed.');
	}

	private function runCallback()
	{
		if (is_callable($GLOBALS['panopticon_test_during_upgrade'] ?? null))
		{
			call_user_func($GLOBALS['panopticon_test_during_upgrade']);
		}
	}
}

class Plugin_Upgrader extends Panopticon_Test_Upgrader
{
}

class Theme_Upgrader extends Panopticon_Test_Upgrader
{
}

function activate_plugin($plugin, $redirect = '', $network_wide = false, $silent = false)
{
	$GLOBALS['panopticon_test_activated'][] = $plugin;

	if (is_callable($GLOBALS['panopticon_test_on_activate'] ?? null))
	{
		return call_user_func($GLOBALS['panopticon_test_on_activate']);
	}

	return null;
}

function get_plugins()
{
	return [PANOPTICON_TEST_PLUGIN => ['Name' => 'Foo', 'Version' => '1.0.0']];
}

function get_plugin_updates()
{
	return [PANOPTICON_TEST_PLUGIN => (object) ['update' => (object) ['new_version' => '1.1.0']]];
}

function wp_get_themes()
{
	return [PANOPTICON_TEST_THEME => (object) ['Name' => 'Bar']];
}

function get_theme_updates()
{
	return [PANOPTICON_TEST_THEME => (object) ['update' => ['new_version' => '1.1.0']]];
}

function wp_update_plugins()
{
}

function wp_update_themes()
{
}

function is_plugin_active($plugin)
{
	return true;
}

function is_plugin_active_for_network($plugin)
{
	return false;
}

function is_network_only_plugin($plugin)
{
	return false;
}

function is_multisite()
{
	return false;
}

function request_filesystem_credentials()
{
	return true;
}

function wp_parse_args($args, $defaults = [])
{
	return array_merge($defaults, (array) $args);
}

function wp_kses($string, $allowed_html)
{
	return $string;
}

function esc_html($text)
{
	return htmlspecialchars($text, ENT_QUOTES);
}

function wp_ob_end_flush_all()
{
	// The real one flushes every output buffer, including PHPUnit's own. Not needed to see the output.
}
