<?php
/**
 * Plugin Name:       MetForm to Laravel API Integration
 * Plugin URI:        https://example.com/plugins/the-basics/
 * Description:       Sends MetForm submission data to a specified Laravel API endpoint.
 * Version:           1.0.1
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Author:            Your Name or Company
 * Author URI:        https://example.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       metform-laravel-api
 * Domain Path:       /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Action Scheduler dependency check removed. Using WP-Cron instead.

/**
 * Hook into MetForm submission after data is stored.
 *
 * @param int   $form_id    The ID of the submitted form.
 * @param array $form_data  The raw form submission data.
 * @param array $entry_data Data prepared for entry storage (might be more structured).
 */
function mfla_handle_metform_submission( $form_id, $form_data, $entry_data ) {
    // Check if this is the specific form we want to integrate.
    // Note: MetForm might pass the form *name* or *ID*. Adjust comparison as needed.
    if ( METFORM_TARGET_FORM_ID !== $form_id && METFORM_TARGET_FORM_ID !== $entry_data['form_name'] ) {
         // If you are unsure if $form_id or $entry_data['form_name'] holds the value defined in METFORM_TARGET_FORM_ID,
         // you might need to log both values initially to see which one matches your target form identifier.
         // mfla_log_message( 'Skipping form. Target: ' . METFORM_TARGET_FORM_ID . ', Submitted ID: ' . $form_id . ', Submitted Name: ' . $entry_data['form_name'] );
        return;
    }

    mfla_log_message( 'Handling submission for form ID/Name: ' . $form_id . '/' . $entry_data['form_name'] );
    
    // Schedule the API call to run asynchronously using WP-Cron
    // Pass only the necessary data. Ensure the data is serializable.
    $cron_args = array(
        'form_submission_data' => $entry_data['form_data'] // Pass the structured form data
    );

    // Schedule a single event to run as soon as possible.
    // WP-Cron's timing depends on site traffic.
    if ( ! wp_next_scheduled( 'mfla_process_scheduled_submission_hook', array( $cron_args ) ) ) {
         wp_schedule_single_event( time(), 'mfla_process_scheduled_submission_hook', array( $cron_args ) );
         mfla_log_message( 'Scheduled WP-Cron event mfla_process_scheduled_submission_hook for form ' . $form_id );
    } else {
         mfla_log_message( 'WP-Cron event mfla_process_scheduled_submission_hook already scheduled for similar args. Skipping duplicate scheduling for form ' . $form_id );
    }

}
// Potential Hooks: metform_after_store_form_data, metform_after_entries_table_data
// We'll use metform_after_store_form_data as it seems appropriate.
// The number '10' is the priority, '3' is the number of arguments the function accepts.
add_action( 'metform_after_store_form_data', 'mfla_handle_metform_submission', 10, 3 );


/**
 * Orchestrates sending the data to the Laravel API.
 * WP-Cron hook callback. Orchestrates sending the data to the Laravel API.
 *
 * @param array $cron_args Arguments passed by wp_schedule_single_event. Expects ['form_submission_data' => [...]].
 */
function mfla_process_scheduled_submission( $cron_args ) {
    mfla_log_message( '[WP-Cron] Processing scheduled submission...' );

    // Extract the actual form data from the arguments passed by WP-Cron
    if ( ! isset( $cron_args['form_submission_data'] ) || empty( $cron_args['form_submission_data'] ) || ! is_array( $cron_args['form_submission_data'] ) ) {
        mfla_log_message( '[WP-Cron] Error: Invalid or empty form data received in scheduled action arguments.' );
        mfla_log_message( '[WP-Cron] Received args: ' . print_r( $cron_args, true ) ); // Log received args for debugging
        return; // Stop processing if data is bad
    }
    $form_submission_data = $cron_args['form_submission_data'];

    $token = mfla_get_laravel_api_token();

    if ( ! $token ) {
        mfla_log_message( 'Error: Could not obtain Laravel API token. Aborting data send.' );
        // Consider notifying admin here if login fails persistently.
        return;
    }

    mfla_log_message( 'Obtained API Token. Preparing data...' );

    // --- Data Mapping ---
    // IMPORTANT: Define the mapping from MetForm field names to Laravel API field names.
    // This is a placeholder - you MUST customize this based on Fase 0 requirements.
    $api_payload = array();
    $field_map = array(
        'mf-text-input-1' => 'nombre_api',       // Example: MetForm field 'mf-text-input-1' maps to 'nombre_api' in Laravel
        'mf-email'        => 'correo_electronico', // Example: MetForm field 'mf-email' maps to 'correo_electronico'
        'mf-textarea'     => 'mensaje_detalle',    // Example: MetForm field 'mf-textarea' maps to 'mensaje_detalle'
        // Add all other necessary field mappings here...
    );

    foreach ( $field_map as $metform_field => $api_field ) {
        if ( isset( $form_submission_data[ $metform_field ] ) ) {
            $api_payload[ $api_field ] = sanitize_text_field( $form_submission_data[ $metform_field ] ); // Basic sanitization
        } else {
            // Optional: Log if a mapped field is missing, or set a default, or skip.
             mfla_log_message( '[WP-Cron] Warning: Mapped MetForm field "' . $metform_field . '" not found in submission data.' );
        }
    }

    if ( empty( $api_payload ) ) {
        mfla_log_message( '[WP-Cron] Error: No data mapped for API payload. Aborting.' );
        return;
    }

    mfla_log_message( '[WP-Cron] Data mapped. Sending POST request to create endpoint.' );
    mfla_log_message( '[WP-Cron] Payload: ' . json_encode($api_payload) ); // Log the payload being sent

    // --- API Call ---
    $api_url = trailingslashit( LARAVEL_API_BASE_URL ) . ltrim( LARAVEL_API_CREATE_ENDPOINT, '/' );
    $args = array(
        'method'  => 'POST',
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ),
        'body'    => json_encode( $api_payload ),
        'timeout' => 30, // Increase timeout if needed
    );

    $response = wp_remote_post( $api_url, $args );

    // --- Handle Response ---
    if ( is_wp_error( $response ) ) {
        $error_message = $response->get_error_message();
        mfla_log_message( '[WP-Cron] WP Error during API call: ' . $error_message );
        // TODO: Implement retry logic or admin notification for WP errors with WP-Cron (might need custom handling).
        return;
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );

    mfla_log_message( '[WP-Cron] API Response Code: ' . $response_code );
    mfla_log_message( '[WP-Cron] API Response Body: ' . $response_body );

    if ( $response_code >= 200 && $response_code < 300 ) {
        // Success (e.g., 200 OK, 201 Created)
        mfla_log_message( '[WP-Cron] Success: Data sent to Laravel API.' );
        // Optionally parse $response_body if needed.
    } elseif ( $response_code === 401 ) {
        // Unauthorized - Token likely expired or invalid. Attempt a single retry.
        mfla_log_message( '[WP-Cron] Error: API returned 401 Unauthorized. Invalidating token and attempting retry.' );

        // Invalidate the stored token
        delete_transient( '_mfla_laravel_api_token' );
        delete_transient( '_mfla_laravel_api_token_expiry' );

        // Attempt to get a fresh token (will trigger login)
        $retry_token = mfla_get_laravel_api_token(); // This now attempts login

        if ( $retry_token ) {
            mfla_log_message( '[WP-Cron] Obtained new token. Retrying API call...' );
            // Prepare args again with the new token
            $retry_args = array(
                'method'  => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $retry_token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body'    => json_encode( $api_payload ),
                'timeout' => 30,
            );

            // Make the second attempt
            $retry_response = wp_remote_post( $api_url, $retry_args );

            if ( is_wp_error( $retry_response ) ) {
                $retry_error_message = $retry_response->get_error_message();
                mfla_log_message( '[WP-Cron] WP Error during API call retry: ' . $retry_error_message );
                // Stop after retry failure
            } else {
                $retry_response_code = wp_remote_retrieve_response_code( $retry_response );
                $retry_response_body = wp_remote_retrieve_body( $retry_response );
                mfla_log_message( '[WP-Cron] Retry API Response Code: ' . $retry_response_code );
                mfla_log_message( '[WP-Cron] Retry API Response Body: ' . $retry_response_body );

                if ( $retry_response_code >= 200 && $retry_response_code < 300 ) {
                    mfla_log_message( '[WP-Cron] Success: Data sent to Laravel API on retry.' );
                } elseif ( $retry_response_code === 422 ) {
                     mfla_log_message( '[WP-Cron] Error: Retry API call returned 422 Unprocessable Entity (Validation Error). Details: ' . $retry_response_body );
                     // Log validation errors from retry
                } else {
                    mfla_log_message( '[WP-Cron] Error: Retry API call failed with HTTP status ' . $retry_response_code . '. Body: ' . $retry_response_body );
                    // Log other errors from retry
                }
            }
        } else {
            mfla_log_message( '[WP-Cron] Error: Failed to obtain a new token after 401. Aborting retry.' );
            // Stop if login failed after 401
        }

    } elseif ( $response_code === 422 ) {
        // Validation Error (from the first attempt)
        mfla_log_message( '[WP-Cron] Error: API returned 422 Unprocessable Entity (Validation Error) on first attempt. Details: ' . $response_body );
        // Log the specific validation errors from $response_body.
        // Consider notifying admin or storing the failed submission data for review.
    } else {
        // Other API errors (4xx, 5xx) from the first attempt
        mfla_log_message( '[WP-Cron] Error: API returned HTTP status ' . $response_code . ' on first attempt. Body: ' . $response_body );
        // Consider notifying admin.
    }
}


/**
 * Gets a valid Bearer token for the Laravel API.
 * Handles fetching a new token if the stored one is missing or expired.
 *
 * @return string|false The Bearer token on success, false on failure.
 */
function mfla_get_laravel_api_token() {
    $token = get_transient( '_mfla_laravel_api_token' );
    $expiry_timestamp = get_transient( '_mfla_laravel_api_token_expiry' );
    $current_timestamp = time();
    $buffer = 60; // 60 seconds buffer before expiry

    // Check if token exists and is not expired (with buffer)
    if ( $token && $expiry_timestamp && ( $expiry_timestamp > ( $current_timestamp + $buffer ) ) ) {
        mfla_log_message( 'Using existing valid API token from transient.' );
        // Optional: Add a check against a status endpoint if available and needed frequently
        // if (defined('LARAVEL_API_STATUS_ENDPOINT') && !mfla_verify_token_with_api($token)) {
        //     mfla_log_message('Existing token failed API verification. Fetching new token.');
        //     // Fall through to login logic
        // } else {
             return $token;
        // }
    }

    // If token is missing, expired, or failed verification, attempt login
    mfla_log_message( 'No valid token found or token expired. Attempting API login.' );
    return mfla_login_to_laravel_api();
}


/**
 * Logs into the Laravel API to retrieve a new Bearer token.
 * Stores the token and its expiry time in transients.
 *
 * @return string|false The new Bearer token on success, false on failure.
 */
function mfla_login_to_laravel_api() {
    $login_url = trailingslashit( LARAVEL_API_BASE_URL ) . ltrim( LARAVEL_API_LOGIN_ENDPOINT, '/' );
    $username = defined( 'LARAVEL_API_USERNAME' ) ? LARAVEL_API_USERNAME : '';
    $password = defined( 'LARAVEL_API_PASSWORD' ) ? LARAVEL_API_PASSWORD : '';

    if ( empty( $username ) || empty( $password ) ) {
        mfla_log_message( 'Error: API Username or Password not defined.' );
        return false;
    }

    $args = array(
        'method'  => 'POST',
        'headers' => array(
            'Content-Type' => 'application/json', // Assuming Laravel expects JSON login
            'Accept'       => 'application/json',
        ),
        'body'    => json_encode( array(
            // Adjust field names if Laravel expects different ones (e.g., 'email')
            'username' => $username,
            'password' => $password,
        ) ),
        'timeout' => 20,
    );

    mfla_log_message( 'Sending login request to: ' . $login_url );
    $response = wp_remote_post( $login_url, $args );

    if ( is_wp_error( $response ) ) {
        $error_message = $response->get_error_message();
        mfla_log_message( 'WP Error during login API call: ' . $error_message );
        return false;
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $response_data = json_decode( $response_body, true );

    mfla_log_message( 'Login API Response Code: ' . $response_code );
    // Avoid logging sensitive data like the token itself in production logs if possible
    // mfla_log_message( 'Login API Response Body: ' . $response_body );

    if ( $response_code === 200 && isset( $response_data['token'] ) && isset( $response_data['expires_in'] ) ) {
        $token = $response_data['token'];
        $expires_in = (int) $response_data['expires_in']; // Duration in seconds
        $expiry_timestamp = time() + $expires_in;

        // Store the token and expiry timestamp in transients
        // Use $expires_in as the transient expiration time for automatic cleanup
        set_transient( '_mfla_laravel_api_token', $token, $expires_in );
        set_transient( '_mfla_laravel_api_token_expiry', $expiry_timestamp, $expires_in );

        mfla_log_message( 'Login successful. New token stored. Expires in: ' . $expires_in . ' seconds.' );
        return $token;
    } else {
        mfla_log_message( 'Error: Login failed. Code: ' . $response_code . '. Body: ' . $response_body );
        // Clear potentially invalid transients if login fails
        delete_transient( '_mfla_laravel_api_token' );
        delete_transient( '_mfla_laravel_api_token_expiry' );
        return false;
    }
}

/**
 * Simple logging function.
 * Writes messages to WordPress debug.log if WP_DEBUG_LOG is enabled.
 * For production, consider a more robust logging solution (e.g., writing to a dedicated file).
 *
 * @param string $message The message to log.
 */
function mfla_log_message( $message ) {
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG === true ) {
        error_log( '[MetForm->Laravel API]: ' . $message );
    }
    // You could add file logging here:
    // $log_file = WP_CONTENT_DIR . '/uploads/metform-laravel-api.log';
    // file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

// Optional: Function to verify token with API status endpoint
 function mfla_verify_token_with_api($token) {
     if (!defined('LARAVEL_API_STATUS_ENDPOINT')) {
         return true; // No endpoint defined, assume token is okay until it fails
     }
     $status_url = trailingslashit( LARAVEL_API_BASE_URL ) . ltrim( LARAVEL_API_STATUS_ENDPOINT, '/' );
     $args = array(
         'method'  => 'GET', // Or POST, depending on the API endpoint
         'headers' => array(
             'Authorization' => 'Bearer ' . $token,
             'Accept'        => 'application/json',
         ),
         'timeout' => 15,
     );
     $response = wp_remote_get( $status_url, $args ); // or wp_remote_post
     if (is_wp_error($response)) {
         mfla_log_message('WP Error during token status check: ' . $response->get_error_message());
         return false; // Treat error as potentially invalid token
     }
     $response_code = wp_remote_retrieve_response_code( $response );
     mfla_log_message('Token status check response code: ' . $response_code);
     return ($response_code >= 200 && $response_code < 300); // Assume 2xx means valid
}

/**
 * Hook the processing function to the WP-Cron action.
 * Note: The number of arguments accepted by the callback (1) must match
 * the number of arguments passed in wp_schedule_single_event's $args array.
 */
add_action( 'mfla_process_scheduled_submission_hook', 'mfla_process_scheduled_submission', 10, 1 );


?>
