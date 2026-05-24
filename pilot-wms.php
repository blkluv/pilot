<?php
/**
 * Plugin Name: Pilot WMS
 * Plugin URI:  https://github.com/dhabib/pilot-wordpress-plugin
 * Description: Receives webhook events from Pilot WMS and creates WordPress posts automatically.
 * Version:     1.0.0
 * Author:      Pilot WMS
 * Author URI:  https://pilotwms.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pilot-wms
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PILOT_WMS_VERSION', '1.0.0' );
define( 'PILOT_WMS_TAG', 'pilot-wms' );
define( 'PILOT_WMS_META_PREFIX', '_pilot_' );
define( 'PILOT_WMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

register_activation_hook( __FILE__, 'pilot_wms_activate' );

/**
 * Run on plugin activation: create Staff user, marker tag, and default options.
 */
function pilot_wms_activate() {
    // Create "Staff" user with author role if it doesn't exist.
    if ( ! get_user_by( 'login', 'pilot-staff' ) ) {
        wp_insert_user( array(
            'user_login'   => 'pilot-staff',
            'user_pass'    => wp_generate_password( 32, true, true ),
            'user_email'   => 'pilot-staff@localhost',
            'display_name' => 'Staff',
            'role'         => 'author',
        ) );
    }

    // Create marker tag if it doesn't exist.
    if ( ! term_exists( PILOT_WMS_TAG, 'post_tag' ) ) {
        wp_insert_term( PILOT_WMS_TAG, 'post_tag' );
    }

    // Set default options (don't overwrite existing).
    add_option( 'pilot_wms_post_status', 'draft' );
    add_option( 'pilot_wms_tag', PILOT_WMS_TAG );
    add_option( 'pilot_wms_webhook_secret', '' );
    add_option( 'pilot_wms_default_category', get_option( 'default_category', 1 ) );
    // Jetonomy-specific defaults
    add_option( 'pilot_wms_default_region', 'general' );
    add_option( 'pilot_wms_default_space', 0 );
}

add_action( 'plugins_loaded', 'pilot_wms_init' );

/**
 * Load plugin classes after all plugins are loaded.
 */
function pilot_wms_init() {
    // Load Jetonomy REST API helper first
    require_once PILOT_WMS_PLUGIN_DIR . 'includes/jetonomy-helper.php';
    require_once PILOT_WMS_PLUGIN_DIR . 'includes/class-pilot-settings.php';
    require_once PILOT_WMS_PLUGIN_DIR . 'includes/class-pilot-image-handler.php';
    require_once PILOT_WMS_PLUGIN_DIR . 'includes/class-pilot-post-handler.php';
    require_once PILOT_WMS_PLUGIN_DIR . 'includes/class-pilot-webhook.php';

    new Pilot_Settings();
    new Pilot_Webhook( new Pilot_Post_Handler( new Pilot_Image_Handler() ) );
}

/**
 * Register a hidden custom post type to store Pilot metadata
 * (acts as a "shadow" reference for Jetonomy posts).
 */
function pilot_register_shadow_post_type() {
    register_post_type( 'pilot_shadow', [
        'public'       => false,
        'show_ui'      => false,
        'supports'     => [ 'title', 'custom-fields' ],
        'capability_type' => 'post',
        'map_meta_cap' => true,
    ] );
}
add_action( 'init', 'pilot_register_shadow_post_type' );
