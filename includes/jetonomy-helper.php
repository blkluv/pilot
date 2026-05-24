<?php
/**
 * Jetonomy REST API helper functions for Pilot WMS plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Call Jetonomy REST API endpoint.
 *
 * @param string $endpoint API endpoint (e.g., '/spaces', '/posts').
 * @param string $method   HTTP method (GET, POST, PUT, DELETE).
 * @param array  $data     Payload for POST/PUT.
 * @return array|false Response body as array, or false on error.
 */
function pilot_call_jetonomy_api( $endpoint, $method = 'GET', $data = [] ) {
    $rest_url = rest_url( 'jetonomy/v1' . $endpoint );

    $args = [
        'method'  => strtoupper( $method ),
        'timeout' => 30,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-WP-Nonce'   => wp_create_nonce( 'wp_rest' ),
        ],
    ];

    if ( ! empty( $data ) && in_array( $method, [ 'POST', 'PUT', 'PATCH' ] ) ) {
        $args['body'] = json_encode( $data );
    }

    $response = wp_remote_request( $rest_url, $args );

    if ( is_wp_error( $response ) ) {
        error_log( 'Jetonomy API error: ' . $response->get_error_message() );
        return false;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $status_code >= 400 ) {
        error_log( "Jetonomy API HTTP $status_code: " . print_r( $body, true ) );
        return false;
    }

    return $body;
}

/**
 * Find or create a Jetonomy space by slug.
 *
 * @param string $slug Space slug (e.g., 'announcements').
 * @return int|null Space ID, or null if failed.
 */
function pilot_find_or_create_jetonomy_space( $slug ) {
    // Try to find existing space by slug
    $spaces = pilot_call_jetonomy_api( "/spaces?slug=" . urlencode( $slug ), 'GET' );
    if ( isset( $spaces['data'][0]['id'] ) ) {
        return (int) $spaces['data'][0]['id'];
    }

    // Create new space
    $title = ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
    $new_space = pilot_call_jetonomy_api( '/spaces', 'POST', [
        'title'       => $title,
        'slug'        => $slug,
        'type'        => 'forum',   // or 'qna', 'idea', 'feed'
        'description' => 'Auto-created from Pilot WMS',
    ] );

    if ( isset( $new_space['id'] ) ) {
        return (int) $new_space['id'];
    }

    // Fallback to default space ID from settings
    $default_space = (int) get_option( 'pilot_wms_default_space', 0 );
    return $default_space ?: null;
}

/**
 * Get Jetonomy user ID from WordPress user ID.
 * Creates a Jetonomy user if not exists (maps by email).
 *
 * @param int $wp_user_id WordPress user ID.
 * @return int|null Jetonomy user ID.
 */
function pilot_get_jetonomy_user_id( $wp_user_id ) {
    $wp_user = get_userdata( $wp_user_id );
    if ( ! $wp_user ) {
        return null;
    }

    // Try to find existing Jetonomy user by email
    $users = pilot_call_jetonomy_api( "/users?email=" . urlencode( $wp_user->user_email ), 'GET' );
    if ( isset( $users['data'][0]['id'] ) ) {
        return (int) $users['data'][0]['id'];
    }

    // Create new Jetonomy user
    $new_user = pilot_call_jetonomy_api( '/users', 'POST', [
        'email'        => $wp_user->user_email,
        'display_name' => $wp_user->display_name,
        'wp_user_id'   => $wp_user_id,
    ] );

    return isset( $new_user['id'] ) ? (int) $new_user['id'] : null;
}
