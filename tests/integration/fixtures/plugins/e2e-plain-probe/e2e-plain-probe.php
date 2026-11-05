<?php
/*
 * Plugin Name: Panopticon E2E plain probe
 * Description: Test fixture. Its activation hook only writes an option, and loads no wp-admin file.
 * Version:     ##VERSION##
 */

defined('ABSPATH') || die;

register_activation_hook(
	__FILE__,
	function () {
		update_option('e2e_plain_probe_activated', '##VERSION##');
	}
);
