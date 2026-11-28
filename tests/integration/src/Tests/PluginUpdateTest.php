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
 * The Connector used to update plugins with Plugin_Upgrader::upgrade(), which deactivates the plugin first, then
 * re-activated it in the same request, running the activation hook of the old version, which was already loaded. An
 * activation hook which loads wp-admin/includes/upgrade.php (the usual way to get dbDelta) killed the request with a
 * fatal error (GitHub issue #12), and so did one which loads a file with require instead of require_once (GitHub issue
 * #14). Either way, the plugin was left deactivated.
 */
class PluginUpdateTest extends AbstractUpdateTestCase
{
	public static function plugins(): array
	{
		return [
			'activation hook writes an option'        => ['e2e-plain-probe'],
			'activation hook loads upgrade.php (#12)' => ['e2e-dbdelta-probe'],
			'activation hook requires a file (#14)'   => ['e2e-require-probe'],
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
