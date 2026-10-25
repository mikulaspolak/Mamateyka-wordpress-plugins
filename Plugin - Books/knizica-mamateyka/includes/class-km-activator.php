<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KM_Activator {
	public static function activate() {
		KM_DB::create_tables();
		KM_Roles::add_roles();

		if ( false === get_option( 'km_settings' ) ) {
			update_option( 'km_settings', KM_Settings::defaults() );
		}

		flush_rewrite_rules();
	}
}
