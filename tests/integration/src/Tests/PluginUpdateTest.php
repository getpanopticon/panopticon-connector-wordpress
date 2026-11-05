<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\WordPress\Tests\Integration\Tests;

use Akeeba\Panopticon\WordPress\Tests\Integration\AbstractUpdateTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Updating an active plugin through the Connector leaves it updated AND active.
 *
 * WordPress deactivates a plugin before updating it, and the Connector re-activates it in the same request, which runs
 * the plugin's activation hook. See GitHub issue #12: an activation hook which loads wp-admin/includes/upgrade.php
 * (the usual way to get dbDelta) killed the request with a fatal error, leaving the plugin deactivated.
 */
class PluginUpdateTest extends AbstractUpdateTestCase
{
	public static function plugins(): array
	{
		return [
			'activation hook writes an option'        => ['e2e-plain-probe'],
			'activation hook loads upgrade.php (#12)' => ['e2e-dbdelta-probe'],
		];
	}

	#[DataProvider('plugins')]
	public function testUpdatedPluginIsStillActive(string $slug)
	{
		$this->installActivePlugin($slug);
		$this->offerUpdate('plugins', $slug);

		$this->assertUpdateSucceeds('update/plugin/' . $slug . '/' . $slug . '.php');

		$installed = self::wp()->json('plugin', 'get', $slug, '--fields=version,status');

		$this->assertSame(self::NEW_VERSION, $installed['version'], 'The plugin was not updated.');
		$this->assertSame('active', $installed['status'], 'The plugin was left deactivated after the update.');
	}
}
