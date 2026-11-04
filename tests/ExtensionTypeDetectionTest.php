<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests detecting whether an uploaded/downloaded package is a plugin or a theme. See GitHub issue #10.
 */
class ExtensionTypeDetectionTest extends TestCase
{
	private const PLUGIN_HEADER = "<?php\n/*\nPlugin Name: Hello Dolly\nVersion: 1.0\n*/\n";

	private const THEME_HEADER = "/*\nTheme Name: My Theme\nVersion: 1.0\n*/\n";

	/** @var string[] */
	private $zips = [];

	protected function setUp(): void
	{
		// A REST request starts without an initialised filesystem object (GitHub issue #10, follow-up).
		$GLOBALS['wp_filesystem'] = null;
	}

	protected function tearDown(): void
	{
		foreach ($this->zips as $zip)
		{
			@unlink($zip);
		}
	}

	public function testPluginInTopLevelFolder()
	{
		$this->assertSame('plugin', $this->detect(['hello-dolly/hello.php' => self::PLUGIN_HEADER]));
	}

	public function testThemeInTopLevelFolder()
	{
		$this->assertSame('theme', $this->detect([
			'mytheme/style.css'    => self::THEME_HEADER,
			'mytheme/functions.php' => "<?php\n",
		]));
	}

	public function testPluginWithOtherFilesBeforeMainFile()
	{
		$this->assertSame('plugin', $this->detect([
			'myplugin/index.php'    => "<?php\n// Silence is golden.\n",
			'myplugin/readme.txt'   => 'Readme',
			'myplugin/myplugin.php' => self::PLUGIN_HEADER,
		]));
	}

	public function testPluginWithoutFolder()
	{
		$this->assertSame('plugin', $this->detect(['hello.php' => self::PLUGIN_HEADER]));
	}

	public function testThemeWithoutFolder()
	{
		$this->assertSame('theme', $this->detect(['style.css' => self::THEME_HEADER]));
	}

	public function testUnrecognisedPackage()
	{
		$this->assertSame('unknown', $this->detect(['stuff/readme.txt' => 'Nothing to see here']));
	}

	/**
	 * Build a ZIP with the given contents and run the private detectExtensionType() on it.
	 *
	 * @param   array<string,string>  $files  Relative path => contents
	 */
	private function detect(array $files): string
	{
		$path = tempnam(sys_get_temp_dir(), 'pnptest');
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);

		foreach ($files as $name => $contents)
		{
			$zip->addFromString($name, $contents);
		}

		$zip->close();
		$this->zips[] = $path;

		$method = new ReflectionMethod(Panopticon_Extensions::class, 'detectExtensionType');

		return $method->invoke(new Panopticon_Extensions(), $path);
	}
}
