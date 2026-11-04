<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

/**
 * Minimal bootstrap for the unit tests.
 *
 * The real WP_Filesystem_Direct class is loaded from a WordPress checkout, given in the WP_ROOT environment variable,
 * so that tests exercise WordPress' actual behaviour. Everything else WordPress provides is stubbed here.
 */

$wpRoot = getenv('WP_ROOT');

if (!$wpRoot || !is_file(rtrim($wpRoot, '/') . '/wp-admin/includes/class-wp-filesystem-direct.php'))
{
	fwrite(STDERR, "Set the WP_ROOT environment variable to the path of a WordPress checkout.\n");
	exit(1);
}

define('ABSPATH', rtrim($wpRoot, '/') . '/');

class WP_REST_Controller
{
}

class WP_Error
{
	public $code;
	public $message;

	public function __construct($code = '', $message = '', $data = '')
	{
		$this->code    = $code;
		$this->message = $message;
	}
}

function __($text, $domain = 'default')
{
	return $text;
}

function untrailingslashit($value)
{
	return rtrim($value, '/\\');
}

function trailingslashit($value)
{
	return untrailingslashit($value) . '/';
}

function is_wp_error($thing)
{
	return $thing instanceof WP_Error;
}

function get_temp_dir()
{
	return rtrim(sys_get_temp_dir(), '/') . '/';
}

function wp_mkdir_p($dir)
{
	return is_dir($dir) || mkdir($dir, 0777, true);
}

function wp_generate_password($length = 12, $special = true)
{
	return substr(bin2hex(random_bytes($length)), 0, $length);
}

function WP_Filesystem()
{
	global $wp_filesystem;

	$wp_filesystem = new stdClass();

	return true;
}

function unzip_file($file, $to)
{
	global $wp_filesystem;

	// Like the real one, this needs the global filesystem object to have been initialised.
	if (!is_object($wp_filesystem))
	{
		return new WP_Error('fs_unavailable', 'Could not access filesystem.');
	}

	$zip = new ZipArchive();

	if ($zip->open($file) !== true)
	{
		return new WP_Error('incompatible_archive', 'Cannot open archive');
	}

	$zip->extractTo($to);
	$zip->close();

	return true;
}

require_once __DIR__ . '/../includes/panopticon-extensions.php';
