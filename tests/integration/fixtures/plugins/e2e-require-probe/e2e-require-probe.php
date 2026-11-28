<?php
/*
 * Plugin Name: Panopticon E2E require probe
 * Description: Test fixture. Loads its functions on init, and its activation hook loads them again with require instead of require_once, like WP Rocket and JetMenu do. Activating it in a request which already loaded it dies with "Cannot redeclare function". See GitHub issue #14.
 * Version:     ##VERSION##
 */

defined('ABSPATH') || die;

add_action(
	'init',
	function () {
		require_once __DIR__ . '/functions.php';
	}
);

register_activation_hook(
	__FILE__,
	function () {
		require __DIR__ . '/functions.php';

		update_option('e2e_require_probe_activated', e2e_require_probe_version());
	}
);
