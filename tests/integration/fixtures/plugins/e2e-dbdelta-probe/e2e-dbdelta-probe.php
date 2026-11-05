<?php
/*
 * Plugin Name: Panopticon E2E dbDelta probe
 * Description: Test fixture. Creates a table on activation the way the Plugin Handbook shows, which loads wp-admin/includes/upgrade.php (and through it misc.php). See GitHub issue #12.
 * Version:     ##VERSION##
 */

defined('ABSPATH') || die;

register_activation_hook(
	__FILE__,
	function () {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}e2e_dbdelta_probe (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY  (id)) "
			. $wpdb->get_charset_collate() . ';'
		);
	}
);
