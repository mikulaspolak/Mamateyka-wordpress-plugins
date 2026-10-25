<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KM_Deactivator {
	public static function deactivate() {
		// Keep data and roles intact; only flush rewrite rules.
		flush_rewrite_rules();
	}
}
