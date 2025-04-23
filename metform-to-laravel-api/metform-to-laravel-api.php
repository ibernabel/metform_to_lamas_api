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
 * Helper function to convert common affirmative strings to boolean true.
 * Handles "Sí", "Si", "Yes", "Accepted", "1". Case-insensitive for the first three.
 *
 * @param mixed $value The input value from the form.
 * @return bool True if affirmative, false otherwise.
 */
function mfla_to_bool($value) {
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int)$value === 1;
    }
    if (is_string($value)) {
        // Normalize accents and case for comparison
        $cleaned_value = strtolower(trim(str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $value)));
        $affirmative = ['si', 'yes', 'accepted']; // 'sí' becomes 'si' after normalization
        return in_array($cleaned_value, $affirmative, true);
    }
    return false;
}

/**
 * Helper function to format a date string from 'd-m-Y' to 'Y-m-d'.
 * Returns null if the input is empty or invalid.
 *
 * @param string|null $date_string The date string from MetForm.
 * @return string|null The formatted date string 'Y-m-d' or null.
 */
function mfla_format_date($date_string) {
    if (empty($date_string) || !is_string($date_string)) return null;
    try {
        // Try parsing d-m-Y first
        $date = DateTime::createFromFormat('d-m-Y', trim($date_string));
        // Check if the created date object, when formatted back, matches the original string.
        // This validates that the input was strictly in 'd-m-Y' format.
        if ($date && $date->format('d-m-Y') === trim($date_string)) {
            return $date->format('Y-m-d');
        }
        // Optional: Try other common formats if needed (e.g., m/d/Y)
        // $date = DateTime::createFromFormat('m/d/Y', trim($date_string));
        // if ($date && $date->format('m/d/Y') === trim($date_string)) {
        //     return $date->format('Y-m-d');
        // }
    } catch (Exception $e) {
        // Log exception during date parsing
        mfla_log_message("[WP-Cron] Warning: Exception while parsing date '$date_string'. Error: " . $e->getMessage());
        return null;
    }
    // Log if format was not 'd-m-Y' after trying
    mfla_log_message("[WP-Cron] Warning: Invalid or non-'d-m-Y' date format received: '$date_string'. Could not parse.");
    return null; // Return null if format is invalid or parsing failed
}

/**
 * Helper function to convert a string value to a numeric type (int or float).
 * Removes common currency symbols and grouping separators.
 * Returns null if conversion fails or input is empty.
 *
 * @param string|null $value The input string.
 * @return int|float|null The numeric value or null.
 */
function mfla_to_numeric($value) {
     if ($value === null || trim($value) === '') return null; // Handle null and empty strings
     // Remove common currency symbols ($, €, etc.), spaces, and thousands separators (commas)
     // Keep the decimal separator (dot) and negative sign
     $cleaned = preg_replace('/[^\d.-]/', '', trim($value));

     // Check if the cleaned string is a valid numeric representation
     if (!is_numeric($cleaned)) {
         // Log the original and cleaned values if conversion fails
         mfla_log_message("[WP-Cron] Warning: Could not convert value to numeric after cleaning: Original='$value' -> Cleaned='$cleaned'");
         return null;
     }
     // Convert to float if it contains a decimal point, otherwise to int
     return strpos($cleaned, '.') !== false ? (float)$cleaned : (int)$cleaned;
}


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

    // --- Data Mapping & Transformation ---
    // Map MetForm fields to the nested structure expected by the Laravel API
    // and apply necessary transformations (boolean, date, numeric).

    $api_payload = [
        'customer' => [
            'details' => [
                'phones' => [],
                'addresses' => [],
                'vehicles' => [],
                'references' => [],
            ],
            'jobInfo' => [],
            'company' => [
                'phones' => [],
                'addresses' => [],
            ],
        ],
        'loanApplication' => [],
    ];

    $data = $form_submission_data; // Shorter alias for readability

    // --- Helper function to safely get and sanitize data ---
    // $key: MetForm field name
    // $default: Default value if key not set
    // $sanitize_callback: Sanitization function (e.g., 'sanitize_text_field', 'sanitize_email')
    // $transform_callback: Transformation function (e.g., 'mfla_to_bool', 'mfla_format_date', 'mfla_to_numeric')
    $get_value = function($key, $default = null, $sanitize_callback = 'sanitize_text_field', $transform_callback = null) use ($data) {
        if (!isset($data[$key]) || trim($data[$key]) === '') {
            return $default;
        }
        $value = $data[$key];
        if ($sanitize_callback && is_callable($sanitize_callback)) {
             // Apply basic WP sanitization first if needed (careful with numeric/bool)
             if ($sanitize_callback !== 'sanitize_email' && !is_numeric($value) && !is_bool($value)) {
                 $value = call_user_func($sanitize_callback, $value);
             } elseif ($sanitize_callback === 'sanitize_email') {
                 $value = call_user_func($sanitize_callback, $value);
             }
             // For numeric/bool, sanitization might happen during transformation
        }
        if ($transform_callback && is_callable($transform_callback)) {
            $value = call_user_func($transform_callback, $value);
        }
        return $value;
    };

    // --- Customer Details ---
    $api_payload['customer']['details']['first_name'] = $get_value('mf-listing-fname');
    $api_payload['customer']['details']['last_name'] = $get_value('apellido');
    $api_payload['customer']['NID'] = $get_value('cedula', null, null, function($val) { return preg_replace('/[^\d]/', '', $val); }); // Keep only digits
    $api_payload['customer']['details']['birthday'] = $get_value('fecha-nacimiento', null, null, 'mfla_format_date');
    $api_payload['customer']['details']['email'] = $get_value('mf-email', null, 'sanitize_email');

    // Map 'estado-civil' to API enum values
    $marital_status_raw = $get_value('estado-civil', null, null, 'strtolower');
    $marital_status_map = [
        'soltero(a)' => 'single',
        'casado(a)' => 'married',
        'divorciado(a)' => 'divorced',
        'viudo(a)' => 'widowed',
        // Add other potential form values mapping to 'other' or specific enums
    ];
    $api_payload['customer']['details']['marital_status'] = $marital_status_map[$marital_status_raw] ?? 'other'; // Default to 'other' if no match

    $api_payload['customer']['details']['nationality'] = $get_value('nacionalidad');

    // Map 'tipo-vivienda' to API enum values
    $housing_type_raw = $get_value('tipo-vivienda', null, null, 'strtolower');
    $housing_type_map = [
        'propia' => 'owned',
        'alquilada' => 'rented',
        'hipotecada' => 'mortgaged',
        'familiar' => 'other', // Assuming 'familiar' maps to 'other'
        // Add other potential form values
    ];
    $api_payload['customer']['details']['housing_type'] = $housing_type_map[$housing_type_raw] ?? 'other'; // Default to 'other' if no match

    $api_payload['customer']['details']['move_in_date'] = $get_value('fecha-de-mudanza', null, null, 'mfla_format_date');

    // --- Customer Phones ---
    $phones = [];
    $celular = $get_value('celular', null, null, function($val) { return preg_replace('/[^\d]/', '', $val); });
    $telefono_casa = $get_value('telefono-casa', null, null, function($val) { return preg_replace('/[^\d]/', '', $val); });
    if ($celular) {
        $phones[] = ['number' => $celular, 'type' => 'mobile']; // Hardcoded type
    }
    if ($telefono_casa) {
        $phones[] = ['number' => $telefono_casa, 'type' => 'home']; // Hardcoded type
    }
    if (!empty($phones)) {
        $api_payload['customer']['details']['phones'] = $phones;
    }

    // --- Customer Addresses ---
    $addresses = [];
    $street = $get_value('direccion');
    if ($street) {
        // Assuming 'direccion' is just the street. Add other fields if available from MetForm.
        // Add type 'home' as required by validation.
        $addresses[] = [
            'street' => $street,
            'type'   => 'home',
            // 'city' => $get_value('city_field'), 'state' => $get_value('state_field'), // etc.
        ];
    }
    if (!empty($addresses)) {
        $api_payload['customer']['details']['addresses'] = $addresses;
    }

    // --- Customer Vehicles ---
    $vehicles = [];
    $is_owned = $get_value('vehiculo-propio', null, null, 'mfla_to_bool');
    $is_financed = $get_value('vehiculo-financiado', null, null, 'mfla_to_bool');
    $brand = $get_value('vehiculo-marca');
    $year = $get_value('vehiculo-anno', null, null, 'mfla_to_numeric');
    // Add vehicle entry only if at least one vehicle field is present and non-null after processing
    if ($is_owned !== null || $is_financed !== null || $brand !== null || $year !== null) {
        $vehicles[] = [
            'is_owned' => $is_owned,
            'is_financed' => $is_financed,
            'brand' => $brand,
            'year' => $year,
        ];
    }
    if (!empty($vehicles)) {
        $api_payload['customer']['details']['vehicles'] = $vehicles;
    }

    // --- Customer References ---
    $references = [];
    // Reference 1
    $ref1_name = $get_value('nombre-referencia-1');
    if ($ref1_name) {
        $references[] = [
            'name' => $ref1_name,
            'occupation' => $get_value('ocupacion-referencia-1'),
            'relationship' => $get_value('parentesco-referencia-1'),
            'phone' => null, // Phone not in example mapping for ref 1
        ];
    }
    // Reference 2
    $ref2_name = $get_value('nombre-referencia-2');
    if ($ref2_name) {
        $references[] = [
            'name' => $ref2_name,
            'occupation' => $get_value('ocupacion-referencia-2'),
            'relationship' => $get_value('parentesco-referencia-2'),
            'phone' => null, // Phone not in example mapping for ref 2
        ];
    }
    // Spouse/Conyugue as Reference 3 (Assumption based on mapping file structure)
    $spouse_name = $get_value('conyugue');
    if ($spouse_name) {
        $references[] = [
            'name' => $spouse_name,
            'phone' => $get_value('celular-conyugue', null, null, function($val) { return preg_replace('/[^\d]/', '', $val); }),
            'relationship' => 'spouse', // Hardcoded assumption, confirm if API expects this or 'wife'/'husband' etc.
            'occupation' => null, // Not provided in form
        ];
    }
    // Assign references directly under 'customer' as per validation rules
    if (!empty($references)) {
        $api_payload['customer']['references'] = $references;
    }

    // --- Job Info ---
    $api_payload['customer']['jobInfo']['is_self_employed'] = $get_value('mf-switch', null, null, 'mfla_to_bool'); // Assuming mf-switch maps here
    $api_payload['customer']['jobInfo']['role'] = $get_value('ocupacion');
    $api_payload['customer']['jobInfo']['start_date'] = $get_value('laborando-desde', null, null, 'mfla_format_date');
    $api_payload['customer']['jobInfo']['salary'] = $get_value('sueldo-mensual', null, null, 'mfla_to_numeric');
    $api_payload['customer']['jobInfo']['other_income'] = $get_value('otros-ingresos', null, null, 'mfla_to_numeric');
    $api_payload['customer']['jobInfo']['other_incomes_source'] = $get_value('descripcion-otros-ingresos');
    $api_payload['customer']['jobInfo']['supervisor_name'] = $get_value('supervisor');

    // --- Company Info ---
    // Only include company info if the person is *not* self-employed (assuming is_self_employed maps correctly)
    // Or always include if provided? Let's always include if name is present.
    $company_name = $get_value('nombre-empresa');
    if ($company_name) {
        $api_payload['customer']['company']['name'] = $company_name;
        // Company Phones
        $company_phones = [];
        $company_phone_num = $get_value('telefono-empresa', null, null, function($val) { return preg_replace('/[^\d]/', '', $val); });
        if ($company_phone_num) {
            $company_phones[] = ['number' => $company_phone_num, 'type' => 'work']; // Hardcoded type
        }
        if (!empty($company_phones)) {
            $api_payload['customer']['company']['phones'] = $company_phones;
        }
        // Company Addresses
        $company_addresses = [];
        $company_street = $get_value('direccion-empresa');
        if ($company_street) {
            // Add type 'work' as required by validation/context.
            $company_addresses[] = [
                'street' => $company_street,
                'type'   => 'work',
                // 'city' => $get_value('company_city_field'), // etc.
            ];
        }
        if (!empty($company_addresses)) {
            $api_payload['customer']['company']['addresses'] = $company_addresses;
        }
    } else {
        // If no company name, ensure the company object is empty or null if required by API
         unset($api_payload['customer']['company']); // Or set to null/empty array based on API spec
    }


    // --- Loan Application Details ---
    // Map 'aceptacion-de-condiciones' to top-level 'terms' field with value 'accepted' if true
    $terms_accepted = $get_value('aceptacion-de-condiciones', false, null, 'mfla_to_bool');
    if ($terms_accepted === true) {
        $api_payload['terms'] = 'accepted';
    }
    // Add other loan application fields if they exist in the form and API spec
    // $api_payload['loanApplication']['amount'] = $get_value('loan-amount', null, null, 'mfla_to_numeric');
    // $api_payload['loanApplication']['purpose'] = $get_value('loan-purpose');


    // --- End of Mapping Logic ---

     // Recursively remove null values from the payload, as the API might not expect them.
     // Be cautious if the API explicitly requires null for certain fields.
     $array_filter_recursive = function (&$array) use (&$array_filter_recursive) {
         foreach ($array as $key => &$value) {
             if (is_array($value)) {
                 $value = $array_filter_recursive($value);
             }
             if ($value === null /* || $value === [] || $value === '' */) { // Adjust condition based on API needs
                 unset($array[$key]);
             }
         }
         return $array; // Return the modified array
     };

     $api_payload = $array_filter_recursive($api_payload);


    // Basic check: Ensure customer NID is present after filtering, as it's often crucial
    if ( empty( $api_payload['customer']['NID'] ) ) {
         mfla_log_message( '[WP-Cron] Error: Customer NID (cedula) is missing, empty, or invalid in the submission data after processing. Aborting.' );
         mfla_log_message( '[WP-Cron] Original NID value: ' . (isset($data['cedula']) ? $data['cedula'] : 'Not Set') );
         mfla_log_message( '[WP-Cron] Payload before aborting: ' . json_encode($api_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) );
         return; // Stop processing if essential data is missing
    }

    // Check if the payload is fundamentally empty after filtering (e.g., only contains empty nested structures)
    if ( empty( $api_payload['customer'] ) && empty( $api_payload['loanApplication'] ) ) {
        mfla_log_message( '[WP-Cron] Error: Payload is effectively empty after mapping and filtering. Aborting.' );
        mfla_log_message( '[WP-Cron] Original form data: ' . print_r($data, true) );
        return;
    }

    mfla_log_message( '[WP-Cron] Data mapped and transformed. Sending POST request to create endpoint.' );
    // Use JSON_PRETTY_PRINT for easier debugging in logs. Add JSON_UNESCAPED_UNICODE for names/addresses.
    mfla_log_message( '[WP-Cron] Payload: ' . json_encode($api_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) );

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
