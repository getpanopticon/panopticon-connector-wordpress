<?php
/*
 * Plugin Name: Panopticon E2E fake plugin updates
 * Description: Offers the plugin updates listed in wp-content/e2e-updates/updates.json, so that the E2E suite can update plugins without an update server. Throwaway test site only.
 *
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

defined('ABSPATH') || die;

/**
 * Adds the updates listed in updates.json to the update_plugins site transient, whenever WordPress reads or writes it.
 *
 * updates.json maps a plugin file (e.g. "foo/foo.php") to {"version": "1.1.0", "package": "/absolute/path.zip"}. A
 * package which is a local file is used as-is; WordPress does not download it, nor delete it after installing it. An
 * update is only offered while the installed version is older, like a real update server does.
 *
 * @param   mixed  $transient  The update_plugins transient; false when it is not set.
 *
 * @return  mixed
 */
function panopticon_e2e_fake_plugin_updates($transient)
{
	$file = WP_CONTENT_DIR . '/e2e-updates/updates.json';

	if (!is_file($file))
	{
		return $transient;
	}

	$updates = json_decode((string) file_get_contents($file), true);

	if (!is_array($updates))
	{
		return $transient;
	}

	if (!is_object($transient))
	{
		$transient = new stdClass();
	}

	if (!isset($transient->response) || !is_array($transient->response))
	{
		$transient->response = [];
	}

	foreach ($updates as $plugin => $update)
	{
		$path = WP_PLUGIN_DIR . '/' . $plugin;

		if (!is_file($path))
		{
			continue;
		}

		$installed = get_file_data($path, ['Version' => 'Version'])['Version'];

		if (version_compare($installed, $update['version'], 'ge'))
		{
			unset($transient->response[$plugin]);

			continue;
		}

		$transient->response[$plugin] = (object) [
			'id'          => 'e2e/' . $plugin,
			'slug'        => dirname($plugin),
			'plugin'      => $plugin,
			'new_version' => $update['version'],
			'url'         => '',
			'package'     => $update['package'],
			'icons'       => [],
			'banners'     => [],
			'tested'      => '',
			'requires_php'=> '',
		];
	}

	return $transient;
}

add_filter('site_transient_update_plugins', 'panopticon_e2e_fake_plugin_updates');
add_filter('pre_set_site_transient_update_plugins', 'panopticon_e2e_fake_plugin_updates');
