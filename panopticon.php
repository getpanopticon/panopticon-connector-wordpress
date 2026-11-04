<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

/*
Plugin Name: Akeeba Panopticon Connector for WordPress
Plugin URI: https://github.com/getpanopticon/panopticon-connector-wordpress
Description: Use your WordPress site with Akeeba Panopticon
Text Domain: panopticon
Domain Path: /languages
Version: 1.2.1
Panopticon API: 100
Requires PHP: 7.2
Requires at least: 5.0
Tested up to: 6.6
Author: akeeba
Author URI: http://www.akeeba.com
License: AGPL-3.0+
License URI: https://www.gnu.org/licenses/agpl-3.0.html
GitHub Plugin URI: https://github.com/getpanopticon/panopticon-connector-wordpress
Update URI: false
*/

defined('WPINC') || die;

if (!trait_exists('Panopticon_Options_Trait', false))
{
	require_once __DIR__ . '/includes/panopticon-options-trait.php';
}

class PanopticonPlugin
{
	use Panopticon_Options_Trait;

	/**
	 * Transient with the update information cache for this plugin.
	 *
	 * @var   string
	 * @since 1.1.0
	 */
	private const UPDATE_CACHE_KEY = 'panopticon_custom_update_cache';

	/**
	 * URL with the plugin update information.
	 *
	 * @var   string
	 * @since 1.1.0
	 */
	private const UPDATE_URL = 'https://raw.githubusercontent.com/getpanopticon/panopticon-connector-wordpress/refs/heads/main/update.json';

	/**
	 * Update cache expiration time, in seconds. Default: 86400 (one day).
	 *
	 * @var   int
	 * @since 1.1.0
	 */
	private const UPDATE_EXPIRES_IN = 86400;

	/**
	 * Object instance for Singleton implementation
	 *
	 * @var   null|self
	 * @since 1.0.0
	 */
	private static $instance = null;

	/**
	 * The API route controller classes to load
	 *
	 * @var   string[]
	 * @since 1.0.0
	 */
	private static $classes = [
		Panopticon_Server_Info::class,
		Panopticon_Core::class,
		Panopticon_Extensions::class,
		Panopticon_Updates::class,
		Panopticon_AkeebaBackup::class,
		Panopticon_AdminTools::class,
	];

	private static $apiPrefix = 'v1/panopticon';

	/**
	 * Plugin version
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $version = '0.0.0-dev';

	/**
	 * Minimum PHP version
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $php = '7.2.0';

	/**
	 * Release date of this plugin
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $releaseDate = '';

	/**
	 * Panopticon API level
	 *
	 * @var   string
	 * @since 1.0.0
	 */
	private $apiLevel = '0';

	/**
	 * Common constructor
	 *
	 * @since   1.0.0
	 */
	public function __construct()
	{
		$this->releaseDate = gmdate('Y-m-d');

		$this->loadComposer();
		$this->loadVersionInfo();

		add_action('plugins_loaded', [$this, 'loadTextDomain']);
		add_action('rest_api_init', [$this, 'loadRoutes']);
	}

	/**
	 * Loads the plugin language files.
	 *
	 * @return  void
	 * @since   1.3.0
	 */
	public function loadTextDomain(): void
	{
		load_plugin_textdomain('panopticon', false, dirname(plugin_basename(__FILE__)) . '/languages');
	}

	/**
	 * Get a Singleton instance
	 *
	 * @return  self
	 * @since   1.0.0
	 */
	public static function getInstance(): self
	{
		return self::$instance = self::$instance ?? new self();
	}

	/**
	 * Runs on plugin activation
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public static function activation()
	{
		register_uninstall_hook(__FILE__, [PanopticonPlugin::class, 'uninstallation']);
	}

	/**
	 * Runs on plugin deactivation
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public static function deactivation()
	{
		// This method is intentionally left blank
	}

	/**
	 * Runs on plugin uninstallation
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public static function uninstallation()
	{
		// This method is intentionally left blank
	}

	/**
	 * Returns the API prefix for the routes
	 *
	 * @return  string
	 * @since   1.0.0
	 */
	public static function getApiPrefix(): string
	{
		return self::$apiPrefix;
	}

	/**
	 * Returns the version of the plugin
	 *
	 * @return  string
	 * @since   1.0.0
	 */
	public function getVersion(): string
	{
		return $this->version;
	}

	/**
	 * Returns the minimum supported PHP version
	 *
	 * @return  string
	 * @since   1.0.0
	 */
	public function getMinimumPhp(): string
	{
		return $this->php;
	}

	/**
	 * Returns the Panopticon API level supported by this plugin
	 *
	 * @return  string
	 * @since   1.0.0
	 */
	public function getApiLevel(): string
	{
		return $this->apiLevel;
	}

	/**
	 * Get the plugin's release date
	 *
	 * @return  string
	 * @since   1.0.0
	 */
	public function getReleaseDate()
	{
		return $this->releaseDate;
	}

	/**
	 * Loads the API route controllers and registers their routes
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function loadRoutes()
	{
		foreach (self::$classes as $class)
		{
			if (!class_exists($class))
			{
				$filePath = __DIR__ . '/includes/' . str_replace('_', '-', strtolower($class)) . '.php';

				if (!@file_exists($filePath) || !@is_readable($filePath))
				{
					continue;
				}

				try
				{
					require_once $filePath;
				}
				catch (Throwable $e)
				{
					continue;
				}
			}

			if (!class_exists($class))
			{
				continue;
			}

			$o = new $class;

			if (!method_exists($class, 'register_routes') || !$o instanceof WP_REST_Controller)
			{
				continue;
			}

			$o->register_routes();
		}
	}

	/**
	 * Authorize an API user.
	 *
	 * If the provided token matches the configured one we log in the first Super Admin (multisite), or Administrator
	 * user we can find.
	 *
	 * @param   mixed  $user
	 *
	 * @return  mixed
	 */
	public function authorizeAPIUser($user)
	{
		// We must have no logged-in user, and the token in the request must match the configured one.
		if (!empty($user) || !$this->isAuthenticated())
		{
			return $user;
		}

		$users = get_users(
			[
				'role'    => is_multisite() ? 'Super Admin' : 'Administrator',
				'orderby' => 'ID',
				'number'  => 1,
			]
		);

		return $users[0];
	}

	/**
	 * Register a custom page under the Tools menu item.
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function registerAdminMenu(): void
	{
		add_management_page(
			__('Panopticon', 'panopticon'), __('Panopticon', 'panopticon'), 'update_core', __FILE__,
			[$this, 'adminPage']
		);
	}

	/**
	 * Render the custom page under the Tools menu item.
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function adminPage(): void
	{
		// Add error/update messages

		// Check if the user have submitted the settings
		// WordPress will add the "settings-updated" $_GET parameter to the url
		if (isset($_GET['settings-updated']))
		{
			// add settings saved message with the class of "updated"
			add_settings_error(
				'panopticon_messages',
				'panopticon_message',
				__('Settings saved.', 'panopticon'),
				'updated'
			);
		}

		// Show error/update messages
		settings_errors('panopticon_messages');

		?>
		<h2 class="title">
			<?= __('Connection information', 'panopticon') ?>
		</h2>
		<table>
			<tr>
				<th scope="row">
					<?= __('Endpoint URL', 'panopticon') ?>
				</th>
				<td>
					<code style="background: none">
						<?= get_rest_url() ?>
					</code>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<?= __('Token', 'panopticon') ?>
				</th>
				<td>
					<code style="background: none">
						<?= $this->getToken() ?>
					</code>
				</td>
			</tr>
		</table>

		<hr style="margin: 1.5em 0" />

		<form action="options.php" method="post">
			<?php
			// Output security fields for the registered setting "wporg"
			settings_fields('panopticon');
			// Output setting sections, and their fields
			// (sections are registered for "panopticon", each field is registered to a specific section)
			do_settings_sections('panopticon');
			// output save settings button
			submit_button(__('Save Settings', 'panopticon'));
			?>
		</form>
		<?php
	}

	/**
	 * Get the (hashed, salted) token which authenticates our API
	 *
	 * @return  string
	 * @since   1.0.0
	 */
	public function getToken(): string
	{
		$salt  = wp_salt('auth');
		$token = get_option('panopticon_token', null);

		if (empty($token))
		{
			$token = wp_generate_password('64', false);
			update_option('panopticon_token', $token, true);
		}

		return hash('sha256', $token . ':' . $salt);
	}

	public function registerWpCliCommands()
	{
		require_once __DIR__ . '/includes/panopticon-cli-namespace.php';
		require_once __DIR__ . '/includes/panopticon-cli-token.php';

		WP_CLI::add_command('panopticon', Panopticon_Cli_Namespace::class);
		WP_CLI::add_command('panopticon token', Panopticon_Cli_Token::class);
	}

	/**
	 * Returns information about the installed plugin back to WordPress.
	 *
	 * This allows WordPress to display information about our plugin in the wp-admin Plugins page.
	 *
	 * Hooks into the `plugins_api` filter.
	 *
	 * @param   false|object|array  $result  The result object or array. Default false.
	 * @param   string              $action  The type of information being requested from the Plugin Installation API.
	 * @param   array|object        $args    Plugin API arguments.
	 *
	 * @return  false|object|array
	 * @since   1.1.0
	 * @link    https://developer.wordpress.org/reference/hooks/plugins_api/
	 */
	public function onGetPluginInformation($result, $action, $args)
	{
		// Only respond to a request for information about our plugin.
		if ($action !== 'plugin_information' || $args->slug !== plugin_basename(__DIR__))
		{
			return $result;
		}

		// Get the cached (or freshly fetched) update information.
		$remote = $this->getUpdateInformation();

		// Could not retrieve update information; bail out.
		if (!$remote)
		{
			return $result;
		}

		return (object) [
			'name'           => $remote->name,
			'slug'           => $remote->slug,
			'version'        => $remote->version,
			'tested'         => $remote->tested,
			'requires'       => $remote->requires,
			'author'         => $remote->author,
			'author_profile' => $remote->author_profile,
			'download_link'  => $remote->download_url,
			'trunk'          => $remote->download_url,
			'requires_php'   => $remote->requires_php,
			'last_updated'   => $remote->last_updated,
			// NB! The "sections" property is an ARRAY (**NOT** an object).
			'sections'       => [
				'description'  => $remote->sections->description,
				'installation' => $remote->sections->installation,
				'changelog'    => $remote->sections->changelog,

			],
			// NB! The "banners" property is an ARRAY (**NOT** an object).
			'banners'        => [
				'low'  => $remote->banners->low,
				'high' => $remote->banners->high,
			],
		];
	}

	/**
	 * Handles the update checks for the plugin by modifying WordPress' update_plugins transient.
	 *
	 * This allows WordPress to find and install updates for our plugin, even though we don't host it on the WordPress
	 * Plugins Directory.
	 *
	 * Hooks into the `site_transient_update_plugins` filter.
	 *
	 * @param   object  $transient  The transient object containing information about available plugin updates.
	 *
	 * @return  object  The modified transient object with added plugin update information if applicable.
	 * @since   1.1.0
	 * @link    https://developer.wordpress.org/reference/hooks/site_transient_transient/
	 * @link    https://make.wordpress.org/core/2020/07/30/recommended-usage-of-the-updates-api-to-support-the-auto-updates-ui-for-plugins-and-themes-in-wordpress-5-5/
	 */
	public function onUpdatePlugins($transient)
	{
		if (!is_object($transient) || empty($transient->checked))
		{
			return $transient;
		}

		$remote = $this->getUpdateInformation();

		if (!$remote)
		{
			return $transient;
		}

		if (version_compare($this->version, $remote->version, 'ge')
		    || version_compare($remote->requires, get_bloginfo('version'), 'gt')
		    || version_compare($remote->requires_php, PHP_VERSION, 'gt'))
		{
			return $transient;
		}

		$pluginBasename                       = plugin_basename(__FILE__);
		$transient->response[$pluginBasename] = ((object) [
			'slug'        => plugin_basename(__DIR__),
			'plugin'      => $pluginBasename,
			'new_version' => $remote->version,
			'tested'      => $remote->tested,
			'package'     => $remote->download_url,
		]);

		return $transient;
	}

	/**
	 * Reset the update cache when WordPress finishes updating any plugin.
	 *
	 * Hooks into the `upgrader_process_complete` hook.
	 *
	 * @param   WP_Upgrader  $upgrader  The Plugin_Upgrader instance. Note we static type on the superclass.
	 * @param   array        $options   Array of bulk item update data.
	 *
	 * @return  void
	 * @since   1.1.0
	 * @link    https://developer.wordpress.org/reference/hooks/upgrader_process_complete/
	 */
	public function onUpdateComplete($upgrader, $options)
	{
		// Sanity check
		if (!is_array($options))
		{
			return;
		}

		// Safe accessors to array elements
		$action = $options['action'] ?? '';
		$type   = $options['type'] ?? '';

		// We expect to see a plugin update here.
		if ($action !== 'update' || $type !== 'plugin')
		{
			return;
		}

		delete_transient(self::UPDATE_CACHE_KEY);
	}

	/**
	 * Does the token in the request match the one set up in the plugin?
	 *
	 * @return  bool
	 * @since   1.0.0
	 */
	private function isAuthenticated(): bool
	{
		$authHeader  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
		$tokenString = '';

		// Apache specific fixes. See https://github.com/symfony/symfony/issues/19693
		if (
			empty($authHeader) && \PHP_SAPI === 'apache2handler'
			&& function_exists('apache_request_headers')
			&& apache_request_headers() !== false
		)
		{
			$apacheHeaders = array_change_key_case(apache_request_headers(), CASE_LOWER);

			if (array_key_exists('authorization', $apacheHeaders))
			{
				$authHeader = $apacheHeaders['authorization'];
			}
		}

		// Preferred form: `Authorization: Bearer TOKEN_STRING`.
		if (substr($authHeader, 0, 7) == 'Bearer ')
		{
			$parts       = explode(' ', $authHeader, 2);
			$tokenString = trim($parts[1]);
		}

		// Fallback: `X-Panopticon-Token: TOKEN_STRING`.
		if (empty($tokenString))
		{
			$tokenString = $_SERVER['HTTP_X_PANOPTICON_TOKEN'] ?? '';
		}

		// Fallback: `X-Joomla-Token: TOKEN_STRING` (this is used )for simplicity in Panopticon 1.x).
		if (empty($tokenString))
		{
			$tokenString = $_SERVER['HTTP_X_JOOMLA_TOKEN'] ?? '';
		}

		// DO NOT INLINE. We want to run both checks.
		return hash_equals($this->getToken(), $tokenString);
	}

	/**
	 * Retrieves the version info from the contents of this plugin file
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	private function loadVersionInfo()
	{
		$fileContents = file_get_contents(__FILE__);

		if (preg_match('#Version\s*:\s*(.*)#', $fileContents, $matches))
		{
			$this->version = $matches[1];
		}

		if (preg_match('#Requires PHP\s*:\s*(.*)#', $fileContents, $matches))
		{
			$this->php = $matches[1];
		}

		if (preg_match('#Panopticon API\s*:\s*(.*)#', $fileContents, $matches))
		{
			$this->apiLevel = $matches[1];
		}

		if (preg_match('#Release Date\s*:\s*(.*)#', $fileContents, $matches))
		{
			$this->releaseDate = $matches[1];
		}
	}

	/**
	 * Loads the included Composer dependencies
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	private function loadComposer()
	{
		$file = __DIR__ . '/vendor/autoload.php';

		if (@is_file($file) && @is_readable($file))
		{
			try
			{
				require_once $file;
			}
			catch (Throwable $e)
			{
				return;
			}
		}
	}

	/**
	 * Get the cached update information for this plugin, fetching and caching it anew when necessary.
	 *
	 * @return  false|object
	 * @since   1.1.0
	 */
	private function getUpdateInformation()
	{
		$remote = get_transient(self::UPDATE_CACHE_KEY);

		if ($remote !== false)
		{
			return json_decode($remote) ?: false;
		}

		$remote = wp_remote_get(self::UPDATE_URL, ['timeout' => 10]);

		if (is_wp_error($remote)
		    || wp_remote_retrieve_response_code($remote) !== 200
		    || empty(wp_remote_retrieve_body($remote)))
		{
			return false;
		}

		$body = wp_remote_retrieve_body($remote);

		set_transient(self::UPDATE_CACHE_KEY, $body, DAY_IN_SECONDS);

		return json_decode($body) ?: false;
	}
}

call_user_func(
	function () {
		// Initialize the plugin
		$panopticon = PanopticonPlugin::getInstance();

		// Register the various activation hooks
		register_activation_hook(__FILE__, [PanopticonPlugin::class, 'activation']);
		register_deactivation_hook(__FILE__, [PanopticonPlugin::class, 'deactivation']);

		// Register admin hooks
		add_action('admin_menu', [$panopticon, 'registerAdminMenu']);
		add_filter('determine_current_user', [$panopticon, 'authorizeAPIUser'], 20);

		// Register the options page
		$panopticon->initOptionsPageHandling();

		// Plugin self-update filters and hooks
		add_filter('plugins_api', [$panopticon, 'onGetPluginInformation'], 20, 3);
		add_filter('site_transient_update_plugins', [$panopticon, 'onUpdatePlugins']);
		add_action('upgrader_process_complete', [$panopticon, 'onUpdateComplete'], 10, 2);

		// WP-CLI integration
		if (defined('WP_CLI') && WP_CLI)
		{
			$panopticon->registerWpCliCommands();
		}
	}
);
