<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 *
 * E2E bootstrap. Loads no Connector or WordPress code: the code under test runs in Docker. It registers the suite's
 * own autoloader, loads the generated config.php, and checks the site is reachable, so an unprovisioned run fails with
 * one clear message.
 */

spl_autoload_register(
	static function (string $class): void
	{
		$prefix = 'Akeeba\\Panopticon\\WordPress\\Tests\\Integration\\';

		if (str_starts_with($class, $prefix))
		{
			$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

			if (is_file($file))
			{
				require $file;
			}
		}
	}
);

$notProvisioned = static function (string $why): never
{
	fwrite(STDERR, "\n$why\nProvision the throwaway site first:\n\n    tests/integration/docker/run.sh --keep-containers\n\n");

	exit(1);
};

if (!is_file(__DIR__ . '/config.php'))
{
	$notProvisioned('No tests/integration/config.php found.');
}

require __DIR__ . '/config.php';

$GLOBALS['testConfiguration'] = $testConfiguration;

$ch = curl_init($testConfiguration['site']['url']);
curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
curl_exec($ch);

if (curl_errno($ch) !== 0)
{
	$notProvisioned('Could not reach the throwaway site at ' . $testConfiguration['site']['url'] . '.');
}
