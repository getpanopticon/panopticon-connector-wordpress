<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\WordPress\Tests\Integration\Engine;

/**
 * Runs WP-CLI on the throwaway site, in the php container, through `docker compose exec`.
 *
 * For fixtures and for reading the site's state only. The behaviour under test is always driven over HTTP.
 */
final class WpCli
{
	public function __construct(private string $dockerDir) {}

	/**
	 * Runs a WP-CLI command and returns its standard output.
	 *
	 * @throws  \RuntimeException  When the command fails.
	 */
	public function run(string ...$arguments): string
	{
		$process = proc_open(
			['docker', 'compose', 'exec', '-T', '-u', 'www-data', 'php', 'wp', '--path=/var/www/html', ...$arguments],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			$this->dockerDir
		);

		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		if (proc_close($process) !== 0)
		{
			throw new \RuntimeException(sprintf("wp %s failed:\n%s", implode(' ', $arguments), trim($stderr ?: $stdout)));
		}

		return trim($stdout);
	}

	/**
	 * Runs a WP-CLI command with --format=json and decodes its output.
	 */
	public function json(string ...$arguments): mixed
	{
		return json_decode($this->run(...[...$arguments, '--format=json']), true, 512, JSON_THROW_ON_ERROR);
	}
}
