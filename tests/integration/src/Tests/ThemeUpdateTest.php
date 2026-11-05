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
 * Updating the active theme through the Connector updates it, and it stays the active theme.
 *
 * See GitHub issue #12: the Connector used to declare its own show_message(), so any code loading
 * wp-admin/includes/misc.php later in the same request (here: a plugin which loads upgrade.php after every update)
 * killed the request with a fatal error.
 */
class ThemeUpdateTest extends AbstractUpdateTestCase
{
	private const THEME = 'e2e-theme-probe';

	public static function sites(): array
	{
		return [
			'nothing else runs after the update'        => [null],
			'a plugin loads upgrade.php after it (#12)' => ['e2e-upgrader-hook-probe'],
		];
	}

	#[DataProvider('sites')]
	public function testUpdatedThemeIsStillActive(?string $plugin)
	{
		if ($plugin !== null)
		{
			$this->installActivePlugin($plugin);
		}

		$this->installActiveTheme(self::THEME);
		$this->offerUpdate('themes', self::THEME);

		$this->assertUpdateSucceeds('update/theme/' . self::THEME);

		$themes = array_column(self::wp()->json('theme', 'list', '--fields=name,version,status'), null, 'name');

		$this->assertSame(self::NEW_VERSION, $themes[self::THEME]['version'] ?? null, 'The theme was not updated.');
		$this->assertSame('active', $themes[self::THEME]['status'] ?? null, 'The theme is no longer the active theme.');
	}
}
