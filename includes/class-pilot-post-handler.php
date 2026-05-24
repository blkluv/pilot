<?php
/**
 * Creates, updates, and unpublishes Jetonomy posts from Pilot WMS webhook payloads.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pilot_Post_Handler {

    /** @var Pilot_Image_Handler */
    private $image_handler;

    public function __construct( Pilot_Image_Handler $image_handler ) {
        $this->image_handler = $image_handler;
    }

    /**
     * Handle content.published event.
     *
     * @param array $payload Full webhook payload.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_publish( array $payload ) {
        $content = $payload['content'] ?? [];
        $projection_id = sanitize_text_field( $content['projection_id'] ?? '' );

        if ( empty( $projection_id ) ) {
            return new WP_Error( 'pilot_wms_missing_id', 'Missing projection_id.', [ 'status' => 400 ] );
        }

        // Idempotency: if already exists, delegate to update
        $existing = $this->find_jetonomy_post_by_projection_id( $projection_id );
        if ( $existing ) {
            $payload['content']['external_id'] = (string) $existing;
            return $this->handle_update( $payload );
        }

        // Determine space ID from topic_region
        $region_slug = $content['metadata']['topic_region'] ?? get_option( 'pilot_wms_default_region', 'general' );
        $space_id = pilot_find_or_create_jetonomy_space( $region_slug );
        if ( ! $space_id ) {
            return new WP_Error( 'pilot_wms_space_error', 'Could not find or create Jetonomy space.', [ 'status' => 500 ] );
        }

        // Map WordPress author to Jetonomy user
        $staff_wp_id = $this->get_or_create_staff_user();
        $jetonomy_author_id = pilot_get_jetonomy_user_id( $staff_wp_id );
        if ( ! $jetonomy_author_id ) {
            $jetonomy_author_id = 1; // fallback to admin
        }

        // Prepare Jetonomy post data
        $post_data = [
            'title'       => sanitize_text_field( $content['title'] ?? '' ),
            'content'     => wp_kses_post( $content['body'] ?? '' ),
            'space_id'    => $space_id,
            'excerpt'     => sanitize_text_field( $content['summary'] ?? '' ),
            'author_id'   => $jetonomy_author_id,
            'status'      => get_option( 'pilot_wms_post_status', 'draft' ),
        ];

        // Call Jetonomy API to create the post
        $result = pilot_call_jetonomy_api( '/posts', 'POST', $post_data );

        if ( ! $result || empty( $result['id'] ) ) {
            return new WP_Error( 'pilot_wms_jetonomy_failed', 'Jetonomy post creation failed.', [ 'status' => 500 ] );
        }

        $jetonomy_post_id = $result['id'];

        // Store Pilot metadata as WordPress post meta (using a hidden "shadow" post)
        $shadow_post_id = $this->store_shadow_post( $content, $payload, $jetonomy_post_id );

        // Handle featured image if present
        if ( ! empty( $content['image_url'] ) ) {
            $this->maybe_attach_image_to_jetonomy_post( $jetonomy_post_id, $content['image_url'], $content['image_alt'] ?? '' );
        }

        return new WP_REST_Response( [
            'status'       => 'ok',
            'external_id'  => (string) $jetonomy_post_id,
            'external_url' => $result['permalink'] ?? '',
        ], 200 );
    }

    /**
     * Handle content.updated event.
     *
     * @param array $payload Full webhook payload.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_update( array $payload ) {
        $content = $payload['content'] ?? [];
        $jetonomy_post_id = $this->resolve_jetonomy_post_id( $content );

        if ( ! $jetonomy_post_id ) {
            return new WP_Error( 'pilot_wms_not_found', 'Jetonomy post not found.', [ 'status' => 404 ] );
        }

        $update_data = [
            'title'   => sanitize_text_field( $content['title'] ?? '' ),
            'content' => wp_kses_post( $content['body'] ?? '' ),
            'excerpt' => sanitize_text_field( $content['summary'] ?? '' ),
        ];

        // Update region -> space if changed
        $region_slug = $content['metadata']['topic_region'] ?? '';
        if ( $region_slug ) {
            $space_id = pilot_find_or_create_jetonomy_space( $region_slug );
            if ( $space_id ) {
                $update_data['space_id'] = $space_id;
            }
        }

        $result = pilot_call_jetonomy_api( '/posts/' . $jetonomy_post_id, 'PUT', $update_data );

        if ( ! $result ) {
            return new WP_Error( 'pilot_wms_update_failed', 'Jetonomy post update failed.', [ 'status' => 500 ] );
        }

        // Update shadow post meta
        $this->update_shadow_post( $jetonomy_post_id, $content, $payload );

        // Update image if URL changed
        $current_image = get_post_meta( $this->get_shadow_post_id( $jetonomy_post_id ), '_pilot_image_url', true );
        $new_image_url = $content['image_url'] ?? '';
        if ( ! empty( $new_image_url ) && $new_image_url !== $current_image ) {
            $this->maybe_attach_image_to_jetonomy_post( $jetonomy_post_id, $new_image_url, $content['image_alt'] ?? '' );
        }

        return new WP_REST_Response( [
            'status'       => 'ok',
            'external_id'  => (string) $jetonomy_post_id,
            'external_url' => $result['permalink'] ?? '',
        ], 200 );
    }

    /**
     * Handle content.unpublished event.
     *
     * @param array $payload Full webhook payload.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_unpublish( array $payload ) {
        $content = $payload['content'] ?? [];
        $jetonomy_post_id = $this->resolve_jetonomy_post_id( $content );

        if ( ! $jetonomy_post_id ) {
            // Idempotent: already gone
            return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
        }

        $result = pilot_call_jetonomy_api( '/posts/' . $jetonomy_post_id, 'PUT', [ 'status' => 'draft' ] );

        return new WP_REST_Response( [ 'status' => 'ok', 'external_id' => (string) $jetonomy_post_id ], 200 );
    }

    // -------------------------------------------------------------------------
    // Private helpers for shadow posts (store Pilot metadata in wp_posts)
    // -------------------------------------------------------------------------

    private function find_jetonomy_post_by_projection_id( string $projection_id ) {
        $shadow = get_posts( [
            'post_type'      => 'pilot_shadow',
            'post_status'    => 'any',
            'meta_key'       => '_pilot_projection_id',
            'meta_value'     => $projection_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ] );
        if ( empty( $shadow ) ) {
            return null;
        }
        return (int) get_post_meta( $shadow[0], '_jetonomy_post_id', true );
    }

    private function store_shadow_post( $content, $payload, $jetonomy_post_id ) {
        $shadow_id = wp_insert_post( [
            'post_title'  => sanitize_text_field( $content['title'] ?? 'Pilot Shadow' ),
            'post_type'   => 'pilot_shadow',
            'post_status' => 'private',
            'meta_input'  => [
                '_pilot_projection_id'   => sanitize_text_field( $content['projection_id'] ?? '' ),
                '_pilot_delivery_id'     => sanitize_text_field( $payload['delivery_id'] ?? '' ),
                '_pilot_tenant_id'       => sanitize_text_field( $payload['tenant']['id'] ?? '' ),
                '_pilot_source_artifact_ids' => wp_json_encode( array_column( $content['source_artifacts'] ?? [], 'id' ) ),
                '_pilot_image_url'       => sanitize_url( $content['image_url'] ?? '' ),
                '_jetonomy_post_id'      => $jetonomy_post_id,
            ],
        ] );
        return $shadow_id;
    }

    private function get_shadow_post_id( $jetonomy_post_id ) {
        $shadows = get_posts( [
            'post_type'      => 'pilot_shadow',
            'meta_key'       => '_jetonomy_post_id',
            'meta_value'     => $jetonomy_post_id,
            'fields'         => 'ids',
            'posts_per_page' => 1,
        ] );
        return $shadows[0] ?? 0;
    }

    private function update_shadow_post( $jetonomy_post_id, $content, $payload ) {
        $shadow_id = $this->get_shadow_post_id( $jetonomy_post_id );
        if ( ! $shadow_id ) {
            return;
        }
        update_post_meta( $shadow_id, '_pilot_projection_id', sanitize_text_field( $content['projection_id'] ?? '' ) );
        update_post_meta( $shadow_id, '_pilot_delivery_id', sanitize_text_field( $payload['delivery_id'] ?? '' ) );
        update_post_meta( $shadow_id, '_pilot_image_url', sanitize_url( $content['image_url'] ?? '' ) );
    }

    private function resolve_jetonomy_post_id( $content ) {
        // First try external_id (Jetonomy post ID stored from publish)
        if ( ! empty( $content['external_id'] ) ) {
            $pid = (int) $content['external_id'];
            // Verify it exists in Jetonomy via API
            $check = pilot_call_jetonomy_api( '/posts/' . $pid, 'GET' );
            if ( $check && isset( $check['id'] ) ) {
                return $pid;
            }
        }
        // Fallback: look up by projection_id
        $projection_id = $content['projection_id'] ?? '';
        if ( $projection_id ) {
            return $this->find_jetonomy_post_by_projection_id( $projection_id );
        }
        return null;
    }

    private function get_or_create_staff_user(): int {
        $user = get_user_by( 'login', 'pilot-staff' );
        if ( $user ) {
            return $user->ID;
        }
        $user_id = wp_insert_user( [
            'user_login'   => 'pilot-staff',
            'user_pass'    => wp_generate_password( 32, true, true ),
            'user_email'   => 'pilot-staff@' . wp_parse_url( home_url(), PHP_URL_HOST ),
            'display_name' => 'Pilot Staff',
            'role'         => 'author',
        ] );
        return is_wp_error( $user_id ) ? 1 : $user_id;
    }

    private function maybe_attach_image_to_jetonomy_post( $jetonomy_post_id, $image_url, $alt_text ) {
        // Sideload image into WP media library
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_sideload_image( $image_url, 0, $alt_text, 'id' );
        if ( is_wp_error( $attachment_id ) ) {
            error_log( 'Image sideload failed: ' . $attachment_id->get_error_message() );
            return;
        }
        // Attach to Jetonomy post via API
        pilot_call_jetonomy_api( '/posts/' . $jetonomy_post_id . '/featured_image', 'PUT', [ 'image_id' => $attachment_id ] );
        update_post_meta( $this->get_shadow_post_id( $jetonomy_post_id ), '_pilot_image_url', $image_url );
    }
}
