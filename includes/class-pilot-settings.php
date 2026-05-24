<?php
/**
 * Settings page for Pilot WMS (Settings > Pilot WMS).
 * Includes Jetonomy integration settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pilot_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_menu() {
        add_options_page(
            'Pilot WMS',
            'Pilot WMS',
            'manage_options',
            'pilot-wms',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        // ========================
        // Connection section
        // ========================
        add_settings_section(
            'pilot_wms_connection',
            'Connection',
            array( $this, 'render_connection_section' ),
            'pilot-wms'
        );

        add_settings_field(
            'pilot_wms_webhook_secret',
            'Webhook Secret',
            array( $this, 'render_secret_field' ),
            'pilot-wms',
            'pilot_wms_connection'
        );
        register_setting( 'pilot_wms', 'pilot_wms_webhook_secret', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        // ========================
        // Content Defaults section (legacy WordPress posts)
        // ========================
        add_settings_section(
            'pilot_wms_content',
            'Content Defaults (Legacy)',
            array( $this, 'render_content_section_note' ),
            'pilot-wms'
        );

        add_settings_field(
            'pilot_wms_default_category',
            'Default Category',
            array( $this, 'render_category_field' ),
            'pilot-wms',
            'pilot_wms_content'
        );
        register_setting( 'pilot_wms', 'pilot_wms_default_category', array(
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ) );

        add_settings_field(
            'pilot_wms_post_status',
            'Post Status',
            array( $this, 'render_status_field' ),
            'pilot-wms',
            'pilot_wms_content'
        );
        register_setting( 'pilot_wms', 'pilot_wms_post_status', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_post_status' ),
        ) );

        add_settings_field(
            'pilot_wms_tag',
            'Marker Tag',
            array( $this, 'render_tag_field' ),
            'pilot-wms',
            'pilot_wms_content'
        );
        register_setting( 'pilot_wms', 'pilot_wms_tag', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        // ========================
        // Jetonomy Integration section
        // ========================
        add_settings_section(
            'pilot_wms_jetonomy',
            'Jetonomy Integration',
            array( $this, 'render_jetonomy_section' ),
            'pilot-wms'
        );

        add_settings_field(
            'pilot_wms_default_region',
            'Default Topic Region',
            array( $this, 'render_default_region_field' ),
            'pilot-wms',
            'pilot_wms_jetonomy'
        );
        register_setting( 'pilot_wms', 'pilot_wms_default_region', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        add_settings_field(
            'pilot_wms_default_space',
            'Fallback Space ID',
            array( $this, 'render_default_space_field' ),
            'pilot-wms',
            'pilot_wms_jetonomy'
        );
        register_setting( 'pilot_wms', 'pilot_wms_default_space', array(
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ) );

        add_settings_field(
            'pilot_wms_auto_create_space',
            'Auto‑create Spaces',
            array( $this, 'render_auto_create_space_field' ),
            'pilot-wms',
            'pilot_wms_jetonomy'
        );
        register_setting( 'pilot_wms', 'pilot_wms_auto_create_space', array(
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );
    }

    // ========================
    // Section Callbacks
    // ========================
    public function render_connection_section() {
        $secret = get_option( 'pilot_wms_webhook_secret' );
        if ( empty( $secret ) ) {
            echo '<p style="color:#d63638;">⚠️ No webhook secret configured. The plugin will reject all incoming webhooks.</p>';
        } else {
            echo '<p style="color:#00a32a;">✓ Webhook secret is configured. Endpoint: <code>'
                . esc_url( rest_url( 'pilot/v1/webhook' ) ) . '</code></p>';
        }
    }

    public function render_content_section_note() {
        echo '<p class="description">These settings are used only if you fall back to standard WordPress posts. When Jetonomy is active, posts go to Jetonomy instead.</p>';
    }

    public function render_jetonomy_section() {
        // Check if Jetonomy plugin is active
        $jetonomy_active = defined( 'JETONOMY_VERSION' ) || class_exists( 'Jetonomy' );
        if ( ! $jetonomy_active ) {
            echo '<p style="color:#d63638;">⚠️ Jetonomy plugin is not active. Please install and activate Jetonomy to use these features.</p>';
        } else {
            echo '<p>Configure how Pilot WMS interacts with Jetonomy spaces and posts.</p>';
        }
    }

    // ========================
    // Field Callbacks
    // ========================
    public function render_secret_field() {
        $value = get_option( 'pilot_wms_webhook_secret' );
        echo '<input type="password" name="pilot_wms_webhook_secret" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description">Paste the webhook secret from your Pilot WMS channel settings.</p>';
    }

    public function render_category_field() {
        $selected = (int) get_option( 'pilot_wms_default_category', 1 );
        wp_dropdown_categories( array(
            'name'             => 'pilot_wms_default_category',
            'selected'         => $selected,
            'hide_empty'       => false,
            'show_option_none' => false,
            'hierarchical'     => true,
        ) );
        echo '<p class="description">Category assigned to new posts when the payload topic doesn\'t match an existing category.</p>';
    }

    public function render_status_field() {
        $value = get_option( 'pilot_wms_post_status', 'draft' );
        echo '<select name="pilot_wms_post_status">';
        echo '<option value="draft"' . selected( $value, 'draft', false ) . '>Draft</option>';
        echo '<option value="pending"' . selected( $value, 'pending', false ) . '>Pending Review</option>';
        echo '</select>';
        echo '<p class="description">Status assigned to newly created posts.</p>';
    }

    public function render_tag_field() {
        $value = get_option( 'pilot_wms_tag', PILOT_WMS_TAG );
        echo '<input type="text" name="pilot_wms_tag" value="' . esc_attr( $value ) . '" class="regular-text" />';
        echo '<p class="description">Tag applied to all posts created by Pilot WMS (only for legacy WordPress posts).</p>';
    }

    public function render_default_region_field() {
        $value = get_option( 'pilot_wms_default_region', 'general' );
        echo '<input type="text" name="pilot_wms_default_region" value="' . esc_attr( $value ) . '" class="regular-text" />';
        echo '<p class="description">When the Pilot payload has no <code>topic_region</code> metadata, this slug is used to find or create a Jetonomy space.</p>';
    }

    public function render_default_space_field() {
        $value = (int) get_option( 'pilot_wms_default_space', 0 );
        echo '<input type="number" name="pilot_wms_default_space" value="' . esc_attr( $value ) . '" class="small-text" />';
        echo '<p class="description">Fallback Jetonomy space ID if space auto‑creation fails. Leave 0 to disable fallback.</p>';
        if ( function_exists( 'pilot_call_jetonomy_api' ) && $value > 0 ) {
            $space = pilot_call_jetonomy_api( '/spaces/' . $value, 'GET' );
            if ( $space && isset( $space['id'] ) ) {
                echo '<p style="color:#00a32a;">✓ Space "' . esc_html( $space['title'] ) . '" exists.</p>';
            } elseif ( $value > 0 ) {
                echo '<p style="color:#d63638;">⚠️ Space ID ' . $value . ' does not exist in Jetonomy.</p>';
            }
        }
    }

    public function render_auto_create_space_field() {
        $value = (bool) get_option( 'pilot_wms_auto_create_space', true );
        echo '<label><input type="checkbox" name="pilot_wms_auto_create_space" value="1"' . checked( $value, true, false ) . ' /> Automatically create a new Jetonomy space if the topic_region does not match an existing space slug.</label>';
    }

    // ========================
    // Sanitization
    // ========================
    public function sanitize_post_status( $value ) {
        return in_array( $value, array( 'draft', 'pending' ), true ) ? $value : 'draft';
    }

    // ========================
    // Page Render
    // ========================
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Pilot WMS</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'pilot_wms' );
                do_settings_sections( 'pilot-wms' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
