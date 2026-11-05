<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

class Panopticon_Updates extends WP_REST_Controller
{
	/**
	 * Registers the API route handlers provided by this controller.
	 *
	 * @since  1.0.0
	 */
	public function register_routes()
	{
		$namespace = \PanopticonPlugin::getApiPrefix();

		register_rest_route(
			$namespace, '/updates', [
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'refreshUpdates'],
					'permission_callback' => [$this, 'canUpdateExtensions'],
				],
			]
		);

		register_rest_route(
			$namespace, '/update/plugin/(?P<plugin>[^./]+(?:/[^/]+)?)', [
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'updatePlugin'],
					'permission_callback' => [$this, 'canUpdatePlugins'],
				],
			]
		);

		register_rest_route(
			$namespace, '/update/theme/(?P<theme>[^./]+(?:/[^./]+)?)', [
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'updateTheme'],
					'permission_callback' => [$this, 'canUpdateThemes'],
				],
			]
		);
	}

	public function refreshUpdates(WP_REST_Request $request)
	{
		if (!function_exists('get_plugin_updates'))
		{
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if (!function_exists('get_plugins'))
		{
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// This should do nothing
		delete_transient('update_plugins');
		delete_transient('update_themes');
		// This should actually delete the transient with the updates
		delete_site_transient('update_plugins');
		delete_site_transient('update_themes');

		// Ask WordPress to refresh the plugin and theme update information, if necessary
		wp_update_plugins();
		wp_update_themes();

		return new WP_REST_Response(
			[
				'status' => true,
			]
		);
	}

	public function updatePlugin(WP_REST_Request $request)
	{
		if (!function_exists('get_plugin_updates'))
		{
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if (!function_exists('get_plugins'))
		{
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if (!function_exists('request_filesystem_credentials'))
		{
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if (!class_exists(Plugin_Upgrader::class))
		{
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$this->loadUpgraderSkin();

		$plugin  = $request['plugin'];
		$plugins = get_plugins();

		if (!isset($plugins[$plugin]))
		{
			return new WP_Error('rest_plugin_not_found', __('Plugin not found.', 'panopticon'), ['status' => 404]);
		}

		/**
		 * Make sure the update information is up-to-date.
		 *
		 * Installing a plugin update deletes the `update_plugins` site transient. WordPress only repopulates it by
		 * hooking wp_update_plugins() to `upgrader_process_complete` in wp-admin/includes/admin-filters.php, a file
		 * which is never loaded during a REST request. Therefore, updating a plugin over the REST API leaves the
		 * transient empty, and the next update request in the same batch would be rejected with a bogus 409.
		 *
		 * This call is cheap when the transient is intact; WordPress only queries its update server when the
		 * transient is missing or stale.
		 */
		wp_update_plugins();

		// Do I have an update?
		$updates = get_plugin_updates();

		if (!is_array($updates))
		{
			$updates = json_decode(json_encode($updates), true);
		}

		if (!isset($updates[$plugin]))
		{
			return new WP_Error('no_such_update', __('There is no such update.', 'panopticon'), ['status' => 409]);
		}

		// Is the plugin activated?
		$isPluginActivated = is_plugin_active($plugin);
		$isNetworkActivated = is_multisite() && is_network_only_plugin($plugin)
		                      && is_plugin_active_for_network($plugin);

		// Install the plugin update
		$skin = new Automatic_Upgrader_Skin();

		@ob_start();
		$upgrader = new Plugin_Upgrader($skin);
		$result   = $upgrader->upgrade($plugin);
		@ob_end_clean();

		if (is_wp_error($result))
		{
			return $result;
		}

		$messages = $skin->get_upgrade_messages();

		/**
		 * Re-activate the plugin.
		 *
		 * WordPress deactivates the plugin before updating it, unless running under WP-Cron. In wp-admin it is then
		 * re-activated in a separate request. A failure here leaves the updated plugin deactivated, so report it.
		 */
		if ($isPluginActivated || $isNetworkActivated)
		{
			$activateResult = activate_plugin($plugin, '', $isNetworkActivated, false);

			if (is_wp_error($activateResult))
			{
				$messages[] = sprintf(
					__('The plugin was installed but activation failed: %s', 'panopticon'),
					$activateResult->get_error_message()
				);
			}
		}

		/**
		 * Rebuild the update information WordPress just threw away.
		 *
		 * This is what wp-admin/includes/admin-filters.php does after an update — but that file is not loaded during
		 * a REST request. Without this, the site would report no available updates at all until something else
		 * refreshes the transient.
		 */
		wp_update_plugins();

		return new WP_REST_Response(
			[
				'status'   => true,
				'messages' => implode("\n", $messages),
			]
		);
	}

	public function updateTheme(WP_REST_Request $request)
	{
		if (!function_exists('get_theme_updates'))
		{
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if (!function_exists('wp_get_themes'))
		{
			require_once ABSPATH . 'wp-includes/theme.php';
		}

		if (!function_exists('request_filesystem_credentials'))
		{
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if (!class_exists(Theme_Upgrader::class))
		{
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
		}

		$this->loadUpgraderSkin();

		$theme  = $request['theme'];
		$themes = wp_get_themes();

		if (!isset($themes[$theme]))
		{
			return new WP_Error('rest_theme_not_found', __('Theme not found.', 'panopticon'), ['status' => 404]);
		}

		/**
		 * Make sure the update information is up-to-date.
		 *
		 * Installing a theme update deletes the `update_themes` site transient. WordPress only repopulates it by
		 * hooking wp_update_themes() to `upgrader_process_complete` in wp-admin/includes/admin-filters.php, a file
		 * which is never loaded during a REST request. Therefore, updating a theme over the REST API leaves the
		 * transient empty, and the next update request in the same batch would be rejected with a bogus 409.
		 *
		 * This call is cheap when the transient is intact; WordPress only queries its update server when the
		 * transient is missing or stale.
		 */
		wp_update_themes();

		// Do I have an update?
		$updates = get_theme_updates();

		if (!isset($updates[$theme]))
		{
			return new WP_Error('no_such_update', __('There is no such update.', 'panopticon'), ['status' => 409]);
		}

		// Install the theme update
		$skin = new Automatic_Upgrader_Skin();

		@ob_start();
		$upgrader = new Theme_Upgrader($skin);
		$result   = $upgrader->upgrade($theme);
		@ob_end_clean();

		if (is_wp_error($result))
		{
			return $result;
		}

		$messages = $skin->get_upgrade_messages();

		/**
		 * Rebuild the update information WordPress just threw away.
		 *
		 * This is what wp-admin/includes/admin-filters.php does after an update — but that file is not loaded during
		 * a REST request. Without this, the site would report no available updates at all until something else
		 * refreshes the transient.
		 */
		wp_update_themes();

		return new WP_REST_Response(
			[
				'status'   => true,
				'messages' => implode("\n", $messages),
			]
		);
	}

	public function canUpdateExtensions(): bool
	{
		return $this->canUpdatePlugins() && $this->canUpdateThemes();
	}

	public function canUpdatePlugins(): bool
	{
		if (is_multisite())
		{
			return current_user_can('manage_network_plugins');
		}

		return current_user_can('update_plugins');
	}

	public function canUpdateThemes(): bool
	{
		if (is_multisite())
		{
			return current_user_can('manage_network_themes');
		}

		return current_user_can('update_themes');
	}

	/**
	 * Loads the upgrader skin used for plugin and theme updates.
	 *
	 * Automatic_Upgrader_Skin collects the upgrader's messages instead of printing them with show_message(). WordPress'
	 * show_message() writes HTML, then flushes all output buffers, which breaks the JSON reply. Do NOT declare a
	 * show_message() replacement either: wp-admin/includes/misc.php declares it unconditionally, and any code which
	 * loads that file later in the request (e.g. a plugin's activation hook loading wp-admin/includes/upgrade.php to
	 * use dbDelta) would die with a "Cannot redeclare function show_message()" fatal error. See GitHub issue #12.
	 *
	 * @return  void
	 * @since   1.2.2
	 */
	private function loadUpgraderSkin()
	{
		if (!class_exists(WP_Upgrader_Skin::class))
		{
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
		}

		if (!class_exists(Automatic_Upgrader_Skin::class))
		{
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}
	}

	protected function getPluginData($plugin)
	{
		$plugins = get_plugins();

		if (!isset($plugins[$plugin]))
		{
			return new WP_Error('rest_plugin_not_found', __('Plugin not found.', 'panopticon'), ['status' => 404]);
		}

		$data          = $plugins[$plugin];
		$data['_file'] = $plugin;

		return $data;
	}
}
