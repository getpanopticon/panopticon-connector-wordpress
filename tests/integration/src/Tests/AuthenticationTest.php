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
 * The Connector's API answers only to its token, in every form Panopticon may send it.
 */
class AuthenticationTest extends AbstractE2ETestCase
{
	public static function tokenHeaders(): array
	{
		return [
			'X-Panopticon-Token'    => ['X-Panopticon-Token'],
			'Authorization: Bearer' => ['Authorization'],
			'X-Joomla-Token'        => ['X-Joomla-Token'],
		];
	}

	#[DataProvider('tokenHeaders')]
	public function testTheTokenIsAccepted(string $header)
	{
		$response = $this->api('GET', 'extensions', null, self::SITE_TOKEN, $header);

		$this->assertSame(200, $response['status'], $response['raw']);
		$this->assertIsArray($response['json'], $response['raw']);
	}

	public function testNoTokenIsRefused()
	{
		$response = $this->api('GET', 'extensions', null, null);

		$this->assertSame(401, $response['status'], $response['raw']);
		$this->assertSame('rest_forbidden', $response['json']['code'] ?? null, $response['raw']);
	}

	#[DataProvider('tokenHeaders')]
	public function testAWrongTokenIsRefused(string $header)
	{
		// The right token with one character changed: as close to it as a wrong token gets.
		$token    = self::config()['token'];
		$token[0] = $token[0] === 'a' ? 'b' : 'a';
		$response = $this->api('GET', 'extensions', null, $token, $header);

		$this->assertSame(401, $response['status'], $response['raw']);
		$this->assertSame('rest_forbidden', $response['json']['code'] ?? null, $response['raw']);
	}
}
