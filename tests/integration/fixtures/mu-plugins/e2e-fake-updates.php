<?php
/*
 * Plugin Name: Panopticon E2E fake updates
 * Description: Offers the plugin and theme updates listed in wp-content/e2e-updates/updates.json, so that the E2E suite can update extensions without an update server. Throwaway test site only.
 *
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

defined('ABSPATH') || die;

/**
 * Returns one section of updates.json.
 *
 * updates.json looks like this:
 *
 *   {
 *     "plugins": {"foo/foo.php": {"version": "1.1.0", "package": "/absolute/path/foo.zip"}},
 *     "themes":  {"bar":         {"version": "1.1.0", "package": "/absolute/path/bar.zip"}}
 *   }
 *
 * A package which is a local file is used as-is: WordPress neither downloads it nor deletes it after installing it.
 *
 * @param   string  $section  "plugins" or "themes"
 *
 * @return  array
 */
function panopticon_e2e_fake_updates_list(string $section): array
{
	$file = WP_CONTENT_DIR . '/e2e-updates/updates.json';

	if (!is_file($file))
	{
		return [];
	}

	$updates = json_decode((string) file_get_contents($file), true);

	return is_array($updates[$section] ?? null) ? $updates[$section] : [];
}

/**
 * Prepares an update_plugins / update_themes transient for adding updates to it.
 *
 * @param   mixed  $transient  The transient; false when it is not set.
 *
 * @return  object
 */
function panopticon_e2e_fake_updates_transient($transient)
{
	if (!is_object($transient))
	{
		$transient = new stdClass();
	}

	if (!isset($transient->response) || !is_array($transient->response))
	{
		$transient->response = [];
	}

	return $transient;
}

/**
 * Adds the plugin updates to the update_plugins site transient. An update is only offered while the installed version is
 * older, like a real update server does.
 *
 * @param   mixed  $transient  The transient; false when it is not set.
 *
 * @return  mixed
 */
function panopticon_e2e_fake_plugin_updates($transient)
{
	$updates = panopticon_e2e_fake_updates_list('plugins');

	if (empty($updates))
	{
		return $transient;
	}

	$transient = panopticon_e2e_fake_updates_transient($transient);

	foreach ($updates as $plugin => $update)
	{
		$path = WP_PLUGIN_DIR . '/' . $plugin;

		if (!is_file($path) || version_compare(get_file_data($path, ['Version' => 'Version'])['Version'], $update['version'], 'ge'))
		{
			unset($transient->response[$plugin]);

			continue;
		}

		// Plugin updates are objects.
		$transient->response[$plugin] = (object) [
			'id'           => 'e2e/' . $plugin,
			'slug'         => dirname($plugin),
			'plugin'       => $plugin,
			'new_version'  => $update['version'],
			'url'          => '',
			'package'      => $update['package'],
			'icons'        => [],
			'banners'      => [],
			'tested'       => '',
			'requires_php' => '',
		];
	}

	return $transient;
}

/**
 * Adds the theme updates to the update_themes site transient. An update is only offered while the installed version is
 * older, like a real update server does.
 *
 * @param   mixed  $transient  The transient; false when it is not set.
 *
 * @return  mixed
 */
function panopticon_e2e_fake_theme_updates($transient)
{
	$updates = panopticon_e2e_fake_updates_list('themes');

	if (empty($updates))
	{
		return $transient;
	}

	$transient = panopticon_e2e_fake_updates_transient($transient);

	foreach ($updates as $stylesheet => $update)
	{
		// Read style.css directly: wp_get_theme() caches the headers, and would report the old version after an update.
		$path = get_theme_root($stylesheet) . '/' . $stylesheet . '/style.css';

		if (!is_file($path) || version_compare(get_file_data($path, ['Version' => 'Version'])['Version'], $update['version'], 'ge'))
		{
			unset($transient->response[$stylesheet]);

			continue;
		}

		// Theme updates are arrays.
		$transient->response[$stylesheet] = [
			'theme'        => $stylesheet,
			'new_version'  => $update['version'],
			'url'          => '',
			'package'      => $update['package'],
			'requires'     => '',
			'requires_php' => '',
		];
	}

	return $transient;
}

add_filter('site_transient_update_plugins', 'panopticon_e2e_fake_plugin_updates');
add_filter('pre_set_site_transient_update_plugins', 'panopticon_e2e_fake_plugin_updates');
add_filter('site_transient_update_themes', 'panopticon_e2e_fake_theme_updates');
add_filter('pre_set_site_transient_update_themes', 'panopticon_e2e_fake_theme_updates');
