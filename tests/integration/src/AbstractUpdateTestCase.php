<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\WordPress\Tests\Integration;

/**
 * Base class for the tests which update plugins and themes through the Connector.
 *
 * Fixture extensions live in fixtures/plugins and fixtures/themes; every ##VERSION## in their files is replaced with
 * the version being installed. Updates are offered by fixtures/mu-plugins/e2e-fake-updates.php, from the packages and
 * the updates.json this class writes to wp-content/e2e-updates. Whatever a test installs is removed after it.
 */
abstract class AbstractUpdateTestCase extends AbstractE2ETestCase
{
	protected const OLD_VERSION = '1.0.0';

	protected const NEW_VERSION = '1.1.0';

	/** @var string[]  Plugin slugs to delete after the test */
	private array $installedPlugins = [];

	/** @var string[]  Theme slugs to delete after the test */
	private array $installedThemes = [];

	/** The active theme before the test, restored after it. */
	private ?string $originalTheme = null;

	protected function tearDown(): void
	{
		$this->removeDirectory($this->updatesDir());

		if ($this->originalTheme !== null)
		{
			$this->wpQuietly('theme', 'activate', $this->originalTheme);
		}

		foreach ($this->installedThemes as $slug)
		{
			$this->wpQuietly('theme', 'delete', $slug);
		}

		foreach ($this->installedPlugins as $slug)
		{
			$this->wpQuietly('plugin', 'delete', $slug);
		}

		parent::tearDown();
	}

	/**
	 * Copies a fixture plugin into the site at the given version, and activates it.
	 */
	protected function installActivePlugin(string $slug, string $version = self::OLD_VERSION): void
	{
		$this->installedPlugins[] = $slug;
		$this->copyFixture('plugins', $slug, $version, self::config()['site']['root'] . '/wp-content/plugins/' . $slug);
		self::wp()->run('plugin', 'activate', $slug);
	}

	/**
	 * Copies a fixture theme into the site at the given version, and makes it the active theme.
	 */
	protected function installActiveTheme(string $slug, string $version = self::OLD_VERSION): void
	{
		$this->installedThemes[] = $slug;
		$this->originalTheme     ??= self::wp()->run('option', 'get', 'stylesheet');
		$this->copyFixture('themes', $slug, $version, self::config()['site']['root'] . '/wp-content/themes/' . $slug);
		self::wp()->run('theme', 'activate', $slug);
	}

	/**
	 * Writes the update package of a fixture plugin or theme, and offers it as an update.
	 *
	 * @param   string  $type  "plugins" or "themes"
	 */
	protected function offerUpdate(string $type, string $slug, string $version = self::NEW_VERSION): void
	{
		$dir     = $this->updatesDir();
		$zipFile = $slug . '-' . $version . '.zip';

		@mkdir($dir, 0755, true);

		$zip = new \ZipArchive();
		$zip->open($dir . '/' . $zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

		foreach ($this->fixtureFiles($type, $slug, $version) as $name => $contents)
		{
			$zip->addFromString($slug . '/' . $name, $contents);
		}

		$zip->close();

		$updates = is_file($dir . '/updates.json')
			? json_decode(file_get_contents($dir . '/updates.json'), true)
			: ['plugins' => [], 'themes' => []];
		$key     = $type === 'plugins' ? $slug . '/' . $slug . '.php' : $slug;

		$updates[$type][$key] = [
			'version' => $version,
			// The path inside the php container.
			'package' => '/var/www/html/wp-content/e2e-updates/' . $zipFile,
		];

		file_put_contents($dir . '/updates.json', json_encode($updates));
	}

	/**
	 * Calls a Connector update endpoint, and asserts it replied with success and logged no fatal error.
	 */
	protected function assertUpdateSucceeds(string $route): void
	{
		$errorLog = self::phpErrorLogPosition();
		$response = $this->api('POST', $route);
		$errors   = self::phpErrorsSince($errorLog);

		$this->assertSame(
			200, $response['status'], "The update request failed.\nReply: {$response['raw']}\nPHP errors:\n{$errors}"
		);
		$this->assertTrue($response['json']['status'] ?? null, $response['raw']);
		$this->assertStringNotContainsString('PHP Fatal error', $errors);
	}

	private function copyFixture(string $type, string $slug, string $version, string $target): void
	{
		@mkdir($target, 0755, true);

		foreach ($this->fixtureFiles($type, $slug, $version) as $name => $contents)
		{
			file_put_contents($target . '/' . $name, $contents);
		}
	}

	/**
	 * @return  array<string,string>  File name => contents, with ##VERSION## replaced.
	 */
	private function fixtureFiles(string $type, string $slug, string $version): array
	{
		$files = [];

		foreach (glob(self::config()['fixtures'] . '/' . $type . '/' . $slug . '/*') as $file)
		{
			$files[basename($file)] = str_replace('##VERSION##', $version, file_get_contents($file));
		}

		$this->assertNotEmpty($files, "No fixture $type/$slug.");

		return $files;
	}

	private function updatesDir(): string
	{
		return self::config()['site']['root'] . '/wp-content/e2e-updates';
	}

	private function removeDirectory(string $dir): void
	{
		foreach (glob($dir . '/*') ?: [] as $file)
		{
			@unlink($file);
		}

		@rmdir($dir);
	}

	/**
	 * Runs WP-CLI during clean-up, where a failure (e.g. the test failed before installing something) is not an error.
	 */
	private function wpQuietly(string ...$arguments): void
	{
		try
		{
			self::wp()->run(...$arguments);
		}
		catch (\RuntimeException $e)
		{
			// Nothing to clean up.
		}
	}
}
