<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Tests for updating plugins and themes.
 *
 * Updating must coexist with WordPress' own show_message() (GitHub issue #12). Updating a plugin must leave its
 * activation alone, like WordPress' own "Update Now" does (GitHub issue #14). Plugin and theme updates must report
 * every way the update can fail.
 *
 * Each test runs in its own process. The tests declare WordPress functions and load WordPress' real
 * wp-admin/includes/misc.php, neither of which can be undone. On the code with the bug, the tests that load misc.php
 * die with an uncatchable "Cannot redeclare function show_message()" fatal error.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class UpdatesShowMessageTest extends TestCase
{
	protected function setUp(): void
	{
		require_once __DIR__ . '/Stubs/updates.php';

		$GLOBALS['panopticon_test_activated']      = [];
		$GLOBALS['panopticon_test_on_activate']    = null;
		$GLOBALS['panopticon_test_during_upgrade'] = null;
		$GLOBALS['panopticon_test_upgrade_outcome'] = 'success';
	}

	/**
	 * Updating a plugin must not (re-)activate it. Doing so in the same request runs the activation hook of the old
	 * version, which is already loaded, and kills the request when that hook loads misc.php (#12), requires a file
	 * again, or declares anything unguarded (#14).
	 */
	public function testPluginUpdateDoesNotRunTheActivationHook()
	{
		$GLOBALS['panopticon_test_on_activate'] = function () {
			require_once ABSPATH . 'wp-admin/includes/misc.php';

			return null;
		};

		$data = $this->updatePlugin();

		$this->assertTrue($data['status']);
		$this->assertSame([], $GLOBALS['panopticon_test_activated'], 'The plugin update must not activate the plugin.');
		$this->assertStringContainsString('Update installed successfully.', $data['messages']);
	}

	/**
	 * When WordPress cannot connect to the filesystem, nothing is installed. That's not a success.
	 */
	public function testPluginUpdateReportsFilesystemFailure()
	{
		$GLOBALS['panopticon_test_upgrade_outcome'] = 'fs_failure';

		$error = $this->failedPluginUpdate();

		$this->assertSame('rest_plugin_update_failed', $error->get_error_code());
	}

	public function testPluginUpdateReportsInvalidPackage()
	{
		$GLOBALS['panopticon_test_upgrade_outcome'] = 'package_error';

		$error = $this->failedPluginUpdate();

		$this->assertSame('incompatible_archive_no_plugins', $error->get_error_code());
	}

	/**
	 * WordPress itself finds no update to install, e.g. the update was already installed.
	 */
	public function testPluginUpdateReportsNoUpdate()
	{
		$GLOBALS['panopticon_test_upgrade_outcome'] = 'up_to_date';

		$error = $this->failedPluginUpdate();

		$this->assertSame('no_such_update', $error->get_error_code());
	}

	public function testThemeUpdateReportsFilesystemFailure()
	{
		$GLOBALS['panopticon_test_upgrade_outcome'] = 'fs_failure';

		$error = $this->failedThemeUpdate();

		$this->assertSame('rest_theme_update_failed', $error->get_error_code());
	}

	public function testThemeUpdateReportsInvalidPackage()
	{
		$GLOBALS['panopticon_test_upgrade_outcome'] = 'package_error';

		$error = $this->failedThemeUpdate();

		$this->assertSame('incompatible_archive_no_plugins', $error->get_error_code());
	}

	public function testThemeUpdateReportsNoUpdate()
	{
		$GLOBALS['panopticon_test_upgrade_outcome'] = 'up_to_date';

		$error = $this->failedThemeUpdate();

		$this->assertSame('no_such_update', $error->get_error_code());
	}

	public function testThemeUpdateSurvivesCodeLoadingMiscPhp()
	{
		$GLOBALS['panopticon_test_during_upgrade'] = function () {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		};

		$data = $this->updateTheme();

		$this->assertTrue($data['status']);
		$this->assertStringContainsString('Update installed successfully.', $data['messages']);
	}

	/**
	 * When misc.php is already loaded, the reply must still be nothing but JSON.
	 */
	public function testPluginUpdateOutputsNothingWhenMiscPhpIsLoaded()
	{
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		ob_start();
		$data   = $this->updatePlugin();
		$output = ob_get_clean();

		$this->assertSame('', $output, 'The reply must not have any output before the JSON data.');
		$this->assertTrue($data['status']);
		$this->assertStringContainsString('Update installed successfully.', $data['messages']);
	}

	public function testThemeUpdateOutputsNothingWhenMiscPhpIsLoaded()
	{
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		ob_start();
		$data   = $this->updateTheme();
		$output = ob_get_clean();

		$this->assertSame('', $output, 'The reply must not have any output before the JSON data.');
		$this->assertTrue($data['status']);
		$this->assertStringContainsString('Update installed successfully.', $data['messages']);
	}

	private function updatePlugin(): array
	{
		$response = (new Panopticon_Updates())->updatePlugin(
			new WP_REST_Request(['plugin' => PANOPTICON_TEST_PLUGIN])
		);

		$this->assertInstanceOf(WP_REST_Response::class, $response);

		return $response->get_data();
	}

	private function failedPluginUpdate(): WP_Error
	{
		$response = (new Panopticon_Updates())->updatePlugin(
			new WP_REST_Request(['plugin' => PANOPTICON_TEST_PLUGIN])
		);

		$this->assertInstanceOf(WP_Error::class, $response, 'The failed plugin update was reported as a success.');

		return $response;
	}

	private function updateTheme(): array
	{
		$response = (new Panopticon_Updates())->updateTheme(
			new WP_REST_Request(['theme' => PANOPTICON_TEST_THEME])
		);

		$this->assertInstanceOf(WP_REST_Response::class, $response);

		return $response->get_data();
	}

	private function failedThemeUpdate(): WP_Error
	{
		$response = (new Panopticon_Updates())->updateTheme(
			new WP_REST_Request(['theme' => PANOPTICON_TEST_THEME])
		);

		$this->assertInstanceOf(WP_Error::class, $response, 'The failed theme update was reported as a success.');

		return $response;
	}
}
