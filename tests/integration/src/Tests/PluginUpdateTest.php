<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\WordPress\Tests\Integration\Tests;

use Akeeba\Panopticon\WordPress\Tests\Integration\AbstractE2ETestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Updating an active plugin through the Connector leaves it updated AND active.
 *
 * WordPress deactivates a plugin before updating it, and the Connector re-activates it in the same request, which runs
 * the plugin's activation hook. See GitHub issue #12: an activation hook which loads wp-admin/includes/upgrade.php
 * (the usual way to get dbDelta) killed the request with a fatal error, leaving the plugin deactivated.
 *
 * The fixture plugins live in fixtures/plugins. The update is offered by fixtures/mu-plugins/e2e-fake-updates.php,
 * from the package this test writes to wp-content/e2e-updates.
 */
class PluginUpdateTest extends AbstractE2ETestCase
{
	private const OLD_VERSION = '1.0.0';

	private const NEW_VERSION = '1.1.0';

	private string $slug = '';

	public static function plugins(): array
	{
		return [
			'activation hook writes an option'     => ['e2e-plain-probe'],
			'activation hook loads upgrade.php (#12)' => ['e2e-dbdelta-probe'],
		];
	}

	protected function tearDown(): void
	{
		if ($this->slug !== '')
		{
			@unlink($this->updatesDir() . '/updates.json');
			@unlink($this->updatesDir() . '/' . $this->slug . '-' . self::NEW_VERSION . '.zip');

			try
			{
				self::wp()->run('plugin', 'delete', $this->slug);
			}
			catch (\RuntimeException $e)
			{
				// Already gone, or the test failed before installing it.
			}
		}

		parent::tearDown();
	}

	#[DataProvider('plugins')]
	public function testUpdatedPluginIsStillActive(string $slug)
	{
		$this->slug = $slug;
		$plugin     = $slug . '/' . $slug . '.php';

		$this->installFixture($slug, self::OLD_VERSION);
		self::wp()->run('plugin', 'activate', $slug);
		$this->offerUpdate($slug, self::NEW_VERSION);

		$errorLog = self::phpErrorLogPosition();
		$response = $this->api('POST', 'update/plugin/' . $plugin);
		$errors   = self::phpErrorsSince($errorLog);

		$this->assertSame(
			200, $response['status'], "The update request failed.\nReply: {$response['raw']}\nPHP errors:\n{$errors}"
		);
		$this->assertTrue($response['json']['status'] ?? null, $response['raw']);
		$this->assertStringNotContainsString('PHP Fatal error', $errors);

		$installed = self::wp()->json('plugin', 'get', $slug, '--fields=version,status');

		$this->assertSame(self::NEW_VERSION, $installed['version'], 'The plugin was not updated.');
		$this->assertSame('active', $installed['status'], 'The plugin was left deactivated after the update.');
	}

	/**
	 * Copies a fixture plugin into the site, at the given version.
	 */
	private function installFixture(string $slug, string $version): void
	{
		$target = self::config()['site']['root'] . '/wp-content/plugins/' . $slug;

		@mkdir($target, 0755, true);

		foreach ($this->fixtureFiles($slug, $version) as $name => $contents)
		{
			file_put_contents($target . '/' . $name, $contents);
		}
	}

	/**
	 * Writes the update package of a fixture plugin, and offers it as an update.
	 */
	private function offerUpdate(string $slug, string $version): void
	{
		$dir = $this->updatesDir();

		@mkdir($dir, 0755, true);

		$zipFile = $slug . '-' . $version . '.zip';
		$zip     = new \ZipArchive();
		$zip->open($dir . '/' . $zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

		foreach ($this->fixtureFiles($slug, $version) as $name => $contents)
		{
			$zip->addFromString($slug . '/' . $name, $contents);
		}

		$zip->close();

		file_put_contents(
			$dir . '/updates.json', json_encode(
				[
					$slug . '/' . $slug . '.php' => [
						'version' => $version,
						// The path inside the php container.
						'package' => '/var/www/html/wp-content/e2e-updates/' . $zipFile,
					],
				]
			)
		);
	}

	/**
	 * @return  array<string,string>  File name => contents, with ##VERSION## replaced.
	 */
	private function fixtureFiles(string $slug, string $version): array
	{
		$files = [];

		foreach (glob(self::config()['fixtures'] . '/plugins/' . $slug . '/*') as $file)
		{
			$files[basename($file)] = str_replace('##VERSION##', $version, file_get_contents($file));
		}

		$this->assertNotEmpty($files, "No fixture plugin $slug.");

		return $files;
	}

	private function updatesDir(): string
	{
		return self::config()['site']['root'] . '/wp-content/e2e-updates';
	}
}
