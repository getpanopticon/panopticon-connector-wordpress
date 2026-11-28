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

		/**
		 * Install the plugin update the way WordPress' own "Update Now" does (wp_ajax_update_plugin()), and WP-CLI.
		 *
		 * Plugin_Upgrader::bulk_upgrade() replaces the files under maintenance mode, and leaves the plugin's activation
		 * state alone. Plugin_Upgrader::upgrade() instead deactivates the plugin first, expecting a separate request to
		 * re-activate it. Re-activating it in this request would run the activation hook of the old version, which is
		 * already loaded, so any hook that requires a file or declares something unguarded dies with a fatal error,
		 * leaving the plugin deactivated. It would also lose the network activation of a plugin on Multisite. See GitHub
		 * issue #14.
		 */
		$skin  = new WP_Ajax_Upgrader_Skin();
		$error = $this->installUpdate(
			new Plugin_Upgrader($skin), $skin, $plugin, 'rest_plugin_update_failed',
			__('Could not install the plugin update.', 'panopticon')
		);

		if ($error !== null)
		{
			return $error;
		}

		$messages = $skin->get_upgrade_messages();

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

		// Install the theme update the way WordPress' own "Update Now" does (wp_ajax_update_theme()).
		$skin  = new WP_Ajax_Upgrader_Skin();
		$error = $this->installUpdate(
			new Theme_Upgrader($skin), $skin, $theme, 'rest_theme_update_failed',
			__('Could not install the theme update.', 'panopticon')
		);

		if ($error !== null)
		{
			return $error;
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
	 * Installs the update of one plugin or theme with the upgrader's bulk_upgrade(), like WordPress' own "Update Now"
	 * does (wp_ajax_update_plugin(), wp_ajax_update_theme()), and checks the result the same way.
	 *
	 * @param   Plugin_Upgrader|Theme_Upgrader  $upgrader      The upgrader, using $skin
	 * @param   WP_Ajax_Upgrader_Skin           $skin          The upgrader's skin
	 * @param   string                          $extension     The plugin (e.g. foo/foo.php) or theme (stylesheet)
	 * @param   string                          $errorCode     Error code when the update failed without saying why
	 * @param   string                          $errorMessage  Error message when the update failed without saying why
	 *
	 * @return  WP_Error|null  Null when the update was installed
	 * @since   1.2.2
	 */
	private function installUpdate($upgrader, WP_Ajax_Upgrader_Skin $skin, $extension, $errorCode, $errorMessage)
	{
		$obLevel = ob_get_level();

		ob_start();

		try
		{
			$results = $upgrader->bulk_upgrade([$extension]);
		}
		finally
		{
			/**
			 * Close every output buffer opened since. Each error the upgrader reports opens one more, which WordPress
			 * never closes: WP_Upgrader_Skin::error() calls header() when $done_header is false, and the header() of
			 * Automatic_Upgrader_Skin starts an output buffer without setting it.
			 */
			while (ob_get_level() > $obLevel)
			{
				ob_end_clean();
			}
		}

		// $results is false when WordPress could not connect to the filesystem.
		$result = is_array($results) && isset($results[$extension]) ? $results[$extension] : null;

		if (is_wp_error($result))
		{
			return $result;
		}

		if (is_wp_error($skin->result))
		{
			return $skin->result;
		}

		if ($skin->get_errors()->get_error_code())
		{
			return $skin->get_errors();
		}

		// True means WordPress found no update to install.
		if ($result === true)
		{
			return new WP_Error('no_such_update', __('There is no such update.', 'panopticon'), ['status' => 409]);
		}

		if (!is_array($result) || empty($result['destination_name']))
		{
			return new WP_Error($errorCode, $errorMessage, ['status' => 500]);
		}

		return null;
	}

	/**
	 * Loads the upgrader skins used for plugin and theme updates.
	 *
	 * Automatic_Upgrader_Skin, and WP_Ajax_Upgrader_Skin which extends it, collect the upgrader's messages instead of
	 * printing them with show_message(). WordPress' show_message() writes HTML, then flushes all output buffers, which
	 * breaks the JSON reply. Do NOT declare a show_message() replacement either: wp-admin/includes/misc.php declares it
	 * unconditionally, and any code which loads that file later in the request (e.g. a plugin's activation hook loading
	 * wp-admin/includes/upgrade.php to use dbDelta) would die with a "Cannot redeclare function show_message()" fatal
	 * error. See GitHub issue #12.
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

		if (!class_exists(WP_Ajax_Upgrader_Skin::class))
		{
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
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
