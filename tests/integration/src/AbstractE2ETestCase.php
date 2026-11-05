<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\WordPress\Tests\Integration;

use Akeeba\Panopticon\WordPress\Tests\Integration\Engine\WpCli;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the E2E tests: real HTTP against the throwaway site run.sh provisioned, the way Panopticon talks to
 * the Connector.
 */
abstract class AbstractE2ETestCase extends TestCase
{
	/** Use the Connector's real API token. */
	protected const SITE_TOKEN = "\0site-token\0";

	/** Where PHP logs errors on the site (docker/config/php.ini), relative to the web root. */
	private const PHP_ERROR_LOG = 'wp-content/e2e-php-errors.log';

	protected static function config(): array
	{
		return $GLOBALS['testConfiguration'];
	}

	protected static function wp(): WpCli
	{
		return new WpCli(self::config()['docker']);
	}

	/**
	 * Calls a Connector API endpoint, like Panopticon does.
	 *
	 * @param   string       $method  HTTP method
	 * @param   string       $route   Route under the Connector's namespace, e.g. "extensions"
	 * @param   array|null   $body    JSON body, if any
	 * @param   string|null  $token   API token; null sends none
	 * @param   string       $header  How to send the token: "X-Panopticon-Token" or "Authorization"
	 *
	 * @return  array{status: int, json: mixed, raw: string}
	 */
	protected function api(
		string $method, string $route, ?array $body = null, ?string $token = self::SITE_TOKEN,
		string $header = 'X-Panopticon-Token'
	): array
	{
		$token   = $token === self::SITE_TOKEN ? self::config()['token'] : $token;
		$url     = self::config()['site']['url'] . '/wp-json/v1/panopticon/' . ltrim($route, '/');
		$headers = [
			'Accept: application/vnd.api+json',
			'Content-Type: application/json',
			'User-Agent: panopticon/e2e',
		];

		if ($token !== null)
		{
			$headers[] = $header === 'Authorization' ? 'Authorization: Bearer ' . $token : $header . ': ' . $token;
		}

		$ch = curl_init($url);
		curl_setopt_array(
			$ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST  => strtoupper($method),
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_TIMEOUT        => 120,
			]
		);

		if (strtoupper($method) !== 'GET')
		{
			// Panopticon POSTs the update requests without a body.
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body === null ? '' : json_encode($body));
		}

		$raw = curl_exec($ch);

		if ($raw === false)
		{
			$this->fail(sprintf('%s %s failed: %s', $method, $url, curl_error($ch)));
		}

		return [
			'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
			'json'   => json_decode((string) $raw, true),
			'raw'    => (string) $raw,
		];
	}

	/**
	 * The current size of the site's PHP error log, to pass to phpErrorsSince() later.
	 */
	protected static function phpErrorLogPosition(): int
	{
		clearstatcache();
		$file = self::config()['site']['root'] . '/' . self::PHP_ERROR_LOG;

		return is_file($file) ? filesize($file) : 0;
	}

	/**
	 * What the site's PHP error log gained since phpErrorLogPosition() returned $position.
	 */
	protected static function phpErrorsSince(int $position): string
	{
		clearstatcache();
		$file = self::config()['site']['root'] . '/' . self::PHP_ERROR_LOG;

		if (!is_file($file))
		{
			return '';
		}

		return (string) file_get_contents($file, false, null, $position);
	}
}
