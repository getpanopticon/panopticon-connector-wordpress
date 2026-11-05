<?php
/*
 * Plugin Name: Panopticon E2E upgrader hook probe
 * Description: Test fixture. Runs after every update, like plugins which migrate their data once something was updated, and loads wp-admin/includes/upgrade.php (and through it misc.php) to do so. See GitHub issue #12.
 * Version:     ##VERSION##
 */

defined('ABSPATH') || die;

add_action(
	'upgrader_process_complete',
	function () {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
);
