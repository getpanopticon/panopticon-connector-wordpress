<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nikolaos Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

/**
 * WordPress stand-ins for the API authentication tests (AuthorizeApiUserTest).
 *
 * Only loaded inside the separate processes those tests run in, because they declare global functions and load the
 * plugin's main file, panopticon.php, which cannot be undone.
 *
 * The site is described by globals the test sets before calling the plugin:
 *
 * - panopticon_test_multisite     bool       What is_multisite() returns.
 * - panopticon_test_users         array      The users of the site (or the network), as WP_User doubles.
 * - panopticon_test_site_members  int[]      IDs of the users who belong to the current site. Like WordPress' own,
 *                                            get_users() only sees these.
 * - panopticon_test_super_admins  string[]   The site_admins network option: logins, not roles.
 */

const PANOPTICON_TEST_TOKEN = 'the-raw-panopticon-token';
const PANOPTICON_TEST_SALT  = 'the-auth-salt';

defined('WPINC') || define('WPINC', 'wp-includes');

class WP_User
{
	public $ID;

	public $user_login;

	public $roles;

	public function __construct($id, $login, array $roles)
	{
		$this->ID         = $id;
		$this->user_login = $login;
		$this->roles      = $roles;
	}
}

function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1)
{
}

function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1)
{
}

function register_activation_hook($file, $callback)
{
}

function register_deactivation_hook($file, $callback)
{
}

function wp_salt($scheme = 'auth')
{
	return PANOPTICON_TEST_SALT;
}

function get_option($option, $default = false)
{
	return $option === 'panopticon_token' ? PANOPTICON_TEST_TOKEN : $default;
}

function update_option($option, $value, $autoload = null)
{
	return true;
}

function is_multisite()
{
	return (bool) ($GLOBALS['panopticon_test_multisite'] ?? false);
}

function get_super_admins()
{
	return $GLOBALS['panopticon_test_super_admins'] ?? [];
}

/**
 * Like WordPress' own: network-wide, whatever site the user belongs to.
 */
function get_user_by($field, $value)
{
	foreach ($GLOBALS['panopticon_test_users'] ?? [] as $user)
	{
		if ($field === 'login' && $user->user_login === $value)
		{
			return $user;
		}

		if ($field === 'id' && $user->ID === (int) $value)
		{
			return $user;
		}
	}

	return false;
}

/**
 * Like WordPress' own: only the current site's users, and a role is matched against the roles users actually have
 * (case-insensitively, as MySQL's LIKE does). There is no "Super Admin" role, so asking for it finds nobody.
 */
function get_users($args = [])
{
	$members = $GLOBALS['panopticon_test_site_members'] ?? null;
	$users   = array_filter(
		$GLOBALS['panopticon_test_users'] ?? [],
		function ($user) use ($members, $args) {
			if (is_array($members) && !in_array($user->ID, $members, true))
			{
				return false;
			}

			if (!isset($args['role']))
			{
				return true;
			}

			return in_array(strtolower($args['role']), array_map('strtolower', $user->roles), true);
		}
	);

	usort(
		$users,
		function ($a, $b) {
			return $a->ID <=> $b->ID;
		}
	);

	return array_slice($users, 0, $args['number'] ?? null);
}
