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
 * Tests that updating plugins and themes coexists with WordPress' own show_message(). See GitHub issue #12.
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
	}

	/**
	 * A plugin whose activation hook loads wp-admin/includes/upgrade.php (to use dbDelta) also loads misc.php.
	 */
	public function testPluginUpdateSurvivesActivationHookLoadingMiscPhp()
	{
		$GLOBALS['panopticon_test_on_activate'] = function () {
			require_once ABSPATH . 'wp-admin/includes/misc.php';

			return null;
		};

		$data = $this->updatePlugin();

		$this->assertTrue($data['status']);
		$this->assertSame([PANOPTICON_TEST_PLUGIN], $GLOBALS['panopticon_test_activated']);
		$this->assertStringContainsString('Update installed successfully.', $data['messages']);
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

	/**
	 * WordPress deactivates the plugin before updating it. If re-activating it fails, say so.
	 */
	public function testPluginUpdateReportsFailedReactivation()
	{
		$GLOBALS['panopticon_test_on_activate'] = function () {
			return new WP_Error('plugin_missing_dependencies', 'Missing required plugins.');
		};

		$data = $this->updatePlugin();

		$this->assertTrue($data['status']);
		$this->assertStringContainsString(
			'The plugin was installed but activation failed: Missing required plugins.', (string) $data['messages']
		);
	}

	private function updatePlugin(): array
	{
		$response = (new Panopticon_Updates())->updatePlugin(
			new WP_REST_Request(['plugin' => PANOPTICON_TEST_PLUGIN])
		);

		$this->assertInstanceOf(WP_REST_Response::class, $response);

		return $response->get_data();
	}

	private function updateTheme(): array
	{
		$response = (new Panopticon_Updates())->updateTheme(
			new WP_REST_Request(['theme' => PANOPTICON_TEST_THEME])
		);

		$this->assertInstanceOf(WP_REST_Response::class, $response);

		return $response->get_data();
	}
}
