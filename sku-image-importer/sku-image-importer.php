<?php
/**
 * Plugin Name: WooCommerce Bulk Product Image Import
 * Description: Bulk import front and back images by parent SKU for existing WooCommerce variable products. The front replaces the featured image and images on eligible variations, and the back replaces the product gallery.
 * Version: 1.0.5
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Reasad Azim
 * License: GPL-2.0-or-later
 * Text Domain: sku-image-importer
 */

defined( 'ABSPATH' ) || exit;

define( 'SII_VERSION', '1.0.5' );
define( 'SII_PATH', plugin_dir_path( __FILE__ ) );
define( 'SII_URL', plugin_dir_url( __FILE__ ) );

require_once SII_PATH . 'includes/class-sii-batch.php';
require_once SII_PATH . 'includes/class-sii-importer.php';
require_once SII_PATH . 'includes/class-sii-admin.php';

add_action( 'plugins_loaded', static function () {
	if ( class_exists( 'WooCommerce' ) ) {
		new SII_Admin( new SII_Importer( new SII_Batch( SII_PATH . 'Batch' ) ) );
	} else {
		add_action( 'admin_notices', static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>WooCommerce Bulk Product Image Import requires WooCommerce to be active.</p></div>';
			}
		} );
	}
} );
