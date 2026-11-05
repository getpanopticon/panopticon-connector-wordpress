<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 *
 * Template of the config.php which tests/integration/docker/run.sh GENERATES on every provisioning run. Do not copy
 * it by hand: the token only exists once the throwaway site does.
 */

$testConfiguration = [
	// The throwaway site, and its web root (bind-mounted into the containers) on the host.
	'site'      => [
		'url'  => 'http://localhost:8230',
		'root' => __DIR__ . '/docker/www',
	],
	// Where docker-compose.yml lives; the tests run WP-CLI in the php container from there.
	'docker'    => __DIR__ . '/docker',
	// The Connector's API token, read with `wp panopticon token get --porcelain`.
	'token'     => '',
	// The WordPress version installed.
	'wordpress' => '',
	'fixtures'  => __DIR__ . '/fixtures',
];
