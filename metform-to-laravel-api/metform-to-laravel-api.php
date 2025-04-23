<?php

/**
 * Plugin Name:       MetForm to Laravel API Integration
 * Plugin URI:        https://example.com/plugins/the-basics/
 * Description:       Sends MetForm submission data to a specified Laravel API endpoint using Action Scheduler.
 * Version:           1.1.0
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
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Load Action Scheduler library safely.
 *
 * Ensures the library is loaded once, preferably via the standard 'plugins_loaded' hook.
 */
function mfla_load_action_scheduler() {
    // Check if Action Scheduler is already loaded (e.g., by another plugin like WooCommerce)
    if ( function_exists('as_schedule_single_action') ) {
        mfla_log_message('Action Scheduler already loaded by another plugin.');
        return; // Already loaded, do nothing.
    }

    $action_scheduler_path = __DIR__ . '/lib/action-scheduler/action-scheduler.php';
    if ( file_exists( $action_scheduler_path ) ) {
        require_once $action_scheduler_path;
        mfla_log_message('Action Scheduler library loaded from plugin.');
    } else {
        // Add an admin notice if Action Scheduler is missing.
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('MetForm to Laravel API plugin requires the Action Scheduler library but it was not found in lib/action-scheduler. Please download and place it correctly.', 'metform-laravel-api');
            echo '</p></div>';
        });
        mfla_log_message('Error: Action Scheduler library not found at ' . $action_scheduler_path);
    }
}
// Hook the loader function to run early during WordPress initialization. Priority 5 is quite early.
add_action( 'init', 'mfla_load_action_scheduler', 10 );


/**
 * Hook into MetForm submission after data is stored.
 * Schedules the data sending via Action Scheduler.
 *
 * @param int   $form_id    The ID of the submitted form.
 * @param array $form_data  The raw form submission data.
 * @param array $entry_data Data prepared for entry storage (might be more structured).
 */
function mfla_handle_metform_submission($form_id, $form_data, $entry_data)
{
  // Check if Action Scheduler functions are available before trying to use them.
  if (!function_exists('as_schedule_single_action') || !function_exists('as_get_scheduled_actions')) {
      mfla_log_message('Error: Action Scheduler functions not available. Cannot schedule API submission for form ' . $form_id);
      // Optionally, you could fall back to WP-Cron here or just log the error.
      return;
  }

  // Check if this is the specific form we want to integrate.
  // Note: MetForm might pass the form *name* or *ID*. Adjust comparison as needed.
  // Safely check if form_name exists in $entry_data before comparing.
  $form_name = isset($entry_data['form_name']) ? $entry_data['form_name'] : null;
  // IMPORTANT: Define METFORM_TARGET_FORM_ID in wp-config.php or elsewhere appropriate.
  $target_form_id = defined('METFORM_TARGET_FORM_ID') ? METFORM_TARGET_FORM_ID : null;

  if ($target_form_id === null) {
      mfla_log_message('Error: METFORM_TARGET_FORM_ID constant is not defined. Cannot determine target form.');
      return;
  }

  if ($target_form_id != $form_id && $target_form_id != $form_name) {
    // If you are unsure if $form_id or $entry_data['form_name'] holds the value defined in METFORM_TARGET_FORM_ID,
    // you might need to log both values initially to see which one matches your target form identifier.
    // mfla_log_message( 'Skipping form. Target: ' . $target_form_id . ', Submitted ID: ' . $form_id . ', Submitted Name: ' . ($form_name ?? 'N/A') );
    return;
  }

  // Safely log form name
  mfla_log_message('Handling submission for form ID/Name: ' . $form_id . '/' . ($form_name ?? 'N/A'));

  // Schedule the API call to run asynchronously using Action Scheduler
  // Pass only the necessary data. Ensure the data is serializable.
  $action_args = array(
    // Pass the raw form data argument directly, as $entry_data might vary.
    'form_submission_data' => $form_data
  );

  // Schedule a single action to run as soon as possible.
  // Action Scheduler provides more reliable execution.
  // Check if an identical action is already pending.
  // Note: Action Scheduler compares serialized args, so this check is generally reliable.
  $pending_actions = as_get_scheduled_actions(array(
      'hook' => 'mfla_process_scheduled_submission_action', // Use the new action hook name
      'args' => $action_args,
      'status' => ActionScheduler_Store::STATUS_PENDING,
  ), 'ids');


  if (empty($pending_actions)) {
      // Schedule the action. The hook name is 'mfla_process_scheduled_submission_action'.
      $action_id = as_schedule_single_action(time(), 'mfla_process_scheduled_submission_action', $action_args);
      if ($action_id) {
          mfla_log_message('Scheduled Action Scheduler action mfla_process_scheduled_submission_action (ID: ' . $action_id . ') for form ' . $form_id);
      } else {
          mfla_log_message('Error: Failed to schedule Action Scheduler action mfla_process_scheduled_submission_action for form ' . $form_id);
      }
  } else {
      mfla_log_message('Action Scheduler action mfla_process_scheduled_submission_action already pending for similar args. Skipping duplicate scheduling for form ' . $form_id);
  }
}
// Potential Hooks: metform_after_store_form_data, metform_after_entries_table_data
// We'll use metform_after_store_form_data as it seems appropriate.
// The number '10' is the priority, '3' is the number of arguments the function accepts.
add_action('metform_after_store_form_data', 'mfla_handle_metform_submission', 10, 3);


/**
 * Helper function to convert common affirmative strings to boolean true.
 * Handles "Sí", "Si", "Yes", "Accepted", "1". Case-insensitive for the first three.
 *
 * @param mixed $value The input value from the form.
 * @return bool True if affirmative, false otherwise.
 */
function mfla_to_bool($value)
{
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
function mfla_format_date($date_string)
{
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
     mfla_log_message("[ActionScheduler] Warning: Exception while parsing date '$date_string'. Error: " . $e->getMessage());
     return null;
   }
   // Log if format was not 'd-m-Y' after trying
   mfla_log_message("[ActionScheduler] Warning: Invalid or non-'d-m-Y' date format received: '$date_string'. Could not parse.");
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
function mfla_to_numeric($value)
{
  if ($value === null || trim($value) === '') return null; // Handle null and empty strings
  // Remove common currency symbols ($, €, etc.), spaces, and thousands separators (commas)
  // Keep the decimal separator (dot) and negative sign
  $cleaned = preg_replace('/[^\d.-]/', '', trim($value));

   // Check if the cleaned string is a valid numeric representation
   if (!is_numeric($cleaned)) {
     // Log the original and cleaned values if conversion fails
     mfla_log_message("[ActionScheduler] Warning: Could not convert value to numeric after cleaning: Original='$value' -> Cleaned='$cleaned'");
     return null;
   }
   // Convert to float if it contains a decimal point, otherwise to int
  return strpos($cleaned, '.') !== false ? (float)$cleaned : (int)$cleaned;
}


 /**
  * Orchestrates sending the data to the Laravel API via Action Scheduler.
  * Action Scheduler hook callback.
  *
  * @param array $action_args Arguments passed by as_schedule_single_action. Expects ['form_submission_data' => [...]].
  */
 function mfla_process_action_scheduler_submission($action_args)
 {
  mfla_log_message('[ActionScheduler] Processing scheduled action...');

  // Check if the required functions exist before proceeding.
  if (!function_exists('get_transient') || !function_exists('set_transient') || !function_exists('delete_transient') || !function_exists('wp_remote_post')) {
      mfla_log_message('[ActionScheduler] Error: Essential WordPress functions are missing. Aborting.');
      // Throwing an exception here might cause issues if WP core functions are truly missing.
      // It's better to just return and log the severe error.
      return;
  }

  // IMPORTANT: Define API constants in wp-config.php or elsewhere appropriate.
  $api_base_url = defined('LARAVEL_API_BASE_URL') ? LARAVEL_API_BASE_URL : null;
  $api_create_endpoint = defined('LARAVEL_API_CREATE_ENDPOINT') ? LARAVEL_API_CREATE_ENDPOINT : null;

  if (!$api_base_url || !$api_create_endpoint) {
      mfla_log_message('[ActionScheduler] Error: LARAVEL_API_BASE_URL or LARAVEL_API_CREATE_ENDPOINT constants are not defined. Aborting.');
      return; // Cannot proceed without API URL details
  }

  // The form data is passed directly as the action arguments.
  // Check if the received arguments are empty or not an array.
  if (empty($action_args) || !is_array($action_args)) {
      mfla_log_message('[ActionScheduler] Error: Invalid or empty form data received in scheduled action arguments (expected an array).');
      mfla_log_message('[ActionScheduler] Received args: ' . print_r($action_args, true)); // Log received args for debugging
      return; // Stop processing if data is bad
  }
  // Use the action arguments directly as the form submission data.
  $form_submission_data = $action_args;

  $token = mfla_get_laravel_api_token();

  if (! $token) {
    mfla_log_message('[ActionScheduler] Error: Could not obtain Laravel API token. Aborting data send.');
    // Throw exception to let Action Scheduler handle retry for transient auth failures.
    throw new Exception('[ActionScheduler] Failed to obtain Laravel API token.');
  }

  mfla_log_message('[ActionScheduler] Obtained API Token. Preparing data...');

  // --- Data Mapping & Transformation ---
  // Map MetForm fields to the nested structure expected by the Laravel API
  // and apply necessary transformations (boolean, date, numeric).

  $api_payload = [
    'customer' => [
      'details' => [
        'phones' => [],
        'addresses' => [],
      ],
      'jobInfo' => [],
      'company' => [
        'phones' => [],
        'addresses' => [],
      ],
      'vehicles' => [],
      'references' => [], // Initialize references array here
    ],
      // Add loan application fields if needed
    'details' => [], // Placeholder for loan application details
 // Placeholder for terms acceptance
  ];

  $data = $form_submission_data; // Shorter alias for readability

  // --- Helper function to safely get and sanitize data ---
  // $key: MetForm field name
  // $default: Default value if key not set
  // $sanitize_callback: Sanitization function (e.g., 'sanitize_text_field', 'sanitize_email')
  // $transform_callback: Transformation function (e.g., 'mfla_to_bool', 'mfla_format_date', 'mfla_to_numeric')
  $get_value = function ($key, $default = null, $sanitize_callback = 'sanitize_text_field', $transform_callback = null) use ($data) {
    if (!isset($data[$key]) || trim((string)$data[$key]) === '') { // Ensure comparison against empty string works for various types
        return $default;
    }
    $value = $data[$key];
    // Apply transformation first if specified (e.g., bool, numeric, date need raw value)
    if ($transform_callback && is_callable($transform_callback)) {
        $value = call_user_func($transform_callback, $value);
        // If transformation results in null, return default early
        if ($value === null) return $default;
    }
    // Apply sanitization *after* transformation if needed (mostly for strings)
    if ($sanitize_callback && is_callable($sanitize_callback)) {
        // Only sanitize if it's still a string after potential transformation
        if (is_string($value)) {
            $value = call_user_func($sanitize_callback, $value);
        }
    }
    return $value;
  };

  // --- Customer Details ---
  $api_payload['customer']['details']['first_name'] = $get_value('mf-listing-fname');
  $api_payload['customer']['details']['last_name'] = $get_value('apellido');
  $api_payload['customer']['NID'] = $get_value('cedula', null, null, function ($val) {
    return preg_replace('/[^\d]/', '', (string)$val); // Keep only digits
  });
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
  $celular = $get_value('celular', null, null, function ($val) {
    return preg_replace('/[^\d]/', '', (string)$val);
  });
  $telefono_casa = $get_value('telefono-casa', null, null, function ($val) {
    return preg_replace('/[^\d]/', '', (string)$val);
  });
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
    $api_payload['customer']['vehicles'] = $vehicles;
  }

  // --- Customer References ---
  $references = [];

  // Reference 0 (Spouse/Conyugue)
  $spouse_name = $get_value('conyugue');
  $spouse_phone = $get_value('celular-conyugue', null, null, function ($val) {
      return preg_replace('/[^\d]/', '', (string)$val);
  });
  if ($spouse_name || $spouse_phone) { // Add if at least name or phone exists
    $references[] = [
      'name' => $spouse_name,
      'phone_number' => $spouse_phone,
      'relationship' => 'spouse', // Hardcoded assumption
      'occupation' => null, // Not provided in form
    ];
  }

  // Reference 1
  $ref1_name = $get_value('nombre-referencia-1');
  if ($ref1_name) {
    $references[] = [
      'name' => $ref1_name,
      'occupation' => $get_value('ocupacion-referencia-1'),
      'relationship' => $get_value('parentesco-referencia-1'),
      'phone_number' => null, // Phone not in example mapping for ref 1
    ];
  }
  // Reference 2
  $ref2_name = $get_value('nombre-referencia-2');
  if ($ref2_name) {
    $references[] = [
      'name' => $ref2_name,
      'occupation' => $get_value('ocupacion-referencia-2'),
      'relationship' => $get_value('parentesco-referencia-2'),
      'phone_number' => null, // Phone not in example mapping for ref 2
    ];
  }

  // Assign references directly under 'customer'
  if (!empty($references)) {
    $api_payload['customer']['references'] = $references;
  }

  // --- Job Info ---
  $api_payload['customer']['jobInfo']['is_self_employed'] = $get_value('mf-switch', null, null, 'mfla_to_bool'); // Assuming mf-switch maps here
  $api_payload['customer']['jobInfo']['role'] = $get_value('ocupacion');
  $api_payload['customer']['jobInfo']['start_date'] = $get_value('laborando-desde', null, null, 'mfla_format_date');
  $api_payload['customer']['jobInfo']['salary'] = $get_value('sueldo-mensual', null, null, 'mfla_to_numeric');
  $api_payload['customer']['jobInfo']['other_incomes'] = $get_value('otros-ingresos', null, null, 'mfla_to_numeric');
  $api_payload['customer']['jobInfo']['other_incomes_source'] = $get_value('descripcion-otros-ingresos');
  $api_payload['customer']['jobInfo']['supervisor_name'] = $get_value('supervisor');

  // --- Company Info ---
  $company_name = $get_value('nombre-empresa');
  if ($company_name) {
    $api_payload['customer']['company']['name'] = $company_name;
    // Company Phones
    $company_phones = [];
    $company_phone_num = $get_value('telefono-empresa', null, null, function ($val) {
      return preg_replace('/[^\d]/', '', (string)$val);
    });
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
    // If no company name, remove the company object entirely
    unset($api_payload['customer']['company']);
  }


  // --- Loan Application Details ---
  // Map 'aceptacion-de-condiciones' to top-level 'terms' field.
  // Always include the 'terms' field. Send boolean true if accepted, false otherwise.
  $terms_accepted_bool = $get_value('aceptacion-de-condiciones', false, null, 'mfla_to_bool');
  $api_payload['terms'] = $terms_accepted_bool; // Send boolean true or false

  // Add other loan application fields if they exist in the form and API spec
  // Note: These fields (amount, term, rate, frequency, purpose) are not present in the provided form data.
  // The API validation errors indicate they are required. Sending default values might still cause errors.
  // Ideally, the form should be updated to include these fields.
   $api_payload['details']['amount'] = 0; // Default value, replace with actual field if available
   $api_payload['details']['term'] = 0; // Default value, replace with actual field if available
   $api_payload['details']['rate'] = 0; // Default value, replace with actual field if available
   $api_payload['details']['frequency'] = 'monthly'; // Default value, replace with actual field if available
   $api_payload['details']['purpose'] = null; // Default value, replace with actual field if available


  // --- End of Mapping Logic ---

  // Recursively remove null values and empty arrays from the payload.
  // Be cautious if the API explicitly requires null or empty arrays for certain fields.
  $array_filter_recursive = function ($array) use (&$array_filter_recursive) {
      $filtered_array = [];
      foreach ($array as $key => $value) {
          if (is_array($value)) {
              $value = $array_filter_recursive($value);
          }
          // Keep the value if it's not null and (if it's an array) it's not empty.
          if ($value !== null && (!is_array($value) || !empty($value))) {
              $filtered_array[$key] = $value;
          }
      }
      return $filtered_array;
  };

  $api_payload = $array_filter_recursive($api_payload);


  // Basic check: Ensure customer NID is present after filtering, as it's often crucial
  if (empty($api_payload['customer']['NID'])) {
    mfla_log_message('[ActionScheduler] Error: Customer NID (cedula) is missing, empty, or invalid in the submission data after processing. Aborting.');
    mfla_log_message('[ActionScheduler] Original NID value: ' . (isset($data['cedula']) ? $data['cedula'] : 'Not Set'));
    mfla_log_message('[ActionScheduler] Payload before aborting: ' . json_encode($api_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    // Do not throw an exception here, this is a data validation issue, not a transient error.
    return;
  }

  // Check if the payload is fundamentally empty after filtering (e.g., only contains empty nested structures)
  if (empty($api_payload['customer']) && empty($api_payload['loanApplication']) && !isset($api_payload['terms'])) {
    mfla_log_message('[ActionScheduler] Error: Payload is effectively empty after mapping and filtering. Aborting.');
    mfla_log_message('[ActionScheduler] Original form data: ' . print_r($data, true));
    return;
  }

  mfla_log_message('[ActionScheduler] Data mapped and transformed. Sending POST request to create endpoint.');
  // Use JSON_PRETTY_PRINT for easier debugging in logs. Add JSON_UNESCAPED_UNICODE for names/addresses.
  mfla_log_message('[ActionScheduler] Payload: ' . json_encode($api_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

  // --- API Call ---
  $api_url = trailingslashit($api_base_url) . ltrim($api_create_endpoint, '/');
  $args = array(
    'method'  => 'POST',
    'headers' => array(
      'Authorization' => 'Bearer ' . $token,
      'Content-Type'  => 'application/json',
      'Accept'        => 'application/json',
    ),
    'body'    => json_encode($api_payload),
    'timeout' => 30, // Increase timeout if needed
  );

  $response = wp_remote_post($api_url, $args);

  // --- Handle Response ---
  if (is_wp_error($response)) {
    $error_message = $response->get_error_message();
    mfla_log_message('[ActionScheduler] WP Error during API call: ' . $error_message);
    // Action Scheduler handles retries automatically based on its configuration/defaults.
    // Throw an exception to signal failure to Action Scheduler, allowing it to retry.
    throw new Exception('[ActionScheduler] WP Error during API call: ' . $error_message);
  }

  $response_code = wp_remote_retrieve_response_code($response);
  $response_body = wp_remote_retrieve_body($response);

  mfla_log_message('[ActionScheduler] API Response Code: ' . $response_code);
  mfla_log_message('[ActionScheduler] API Response Body: ' . $response_body);

  if ($response_code >= 200 && $response_code < 300) {
    // Success (e.g., 200 OK, 201 Created)
    mfla_log_message('[ActionScheduler] Success: Data sent to Laravel API.');
    // Optionally parse $response_body if needed.
  } elseif ($response_code === 401) {
    // Unauthorized - Token likely expired or invalid.
    mfla_log_message('[ActionScheduler] Error: API returned 401 Unauthorized. Invalidating token.');

    // Invalidate the stored token
    delete_transient('_mfla_laravel_api_token');
    delete_transient('_mfla_laravel_api_token_expiry');

    // Throw an exception to signal failure and trigger Action Scheduler retry.
    // The next attempt will call mfla_get_laravel_api_token() which will trigger a new login.
    throw new Exception('[ActionScheduler] API returned 401 Unauthorized.');

  } elseif ($response_code === 422) {
    // Validation Error
    mfla_log_message('[ActionScheduler] Error: API returned 422 Unprocessable Entity (Validation Error). Details: ' . $response_body);
    // Log the specific validation errors from $response_body.
    // Do not throw an exception here, as validation errors shouldn't typically be retried automatically.
    // The action will complete, but logs indicate failure. Consider admin notification.
  } else {
    // Other API errors (4xx client errors, 5xx server errors)
    mfla_log_message('[ActionScheduler] Error: API returned HTTP status ' . $response_code . '. Body: ' . $response_body);
    // Throw an exception to signal failure and allow potential retry for server errors (5xx) or other transient issues.
    throw new Exception('[ActionScheduler] API call failed with HTTP status ' . $response_code);
  }
}


/**
 * Gets a valid Bearer token for the Laravel API.
 * Handles fetching a new token if the stored one is missing or expired.
 *
 * @return string|false The Bearer token on success, false on failure.
 */
function mfla_get_laravel_api_token()
{
  // Check if essential functions exist
  if (!function_exists('get_transient') || !function_exists('time')) {
      mfla_log_message('[ActionScheduler] Error: get_transient or time function missing in mfla_get_laravel_api_token.');
      return false;
  }

  $token = get_transient('_mfla_laravel_api_token');
  $expiry_timestamp = get_transient('_mfla_laravel_api_token_expiry');
  $current_timestamp = time();
  $buffer = 60; // 60 seconds buffer before expiry

  // Check if token exists and is not expired (with buffer)
  if ($token && $expiry_timestamp && ($expiry_timestamp > ($current_timestamp + $buffer))) {
    mfla_log_message('[ActionScheduler] Using existing valid API token from transient.');
    // Optional: Add a check against a status endpoint if available and needed frequently
    // if (defined('LARAVEL_API_STATUS_ENDPOINT') && !mfla_verify_token_with_api($token)) {
    //     mfla_log_message('[ActionScheduler] Existing token failed API verification. Fetching new token.');
    //     // Fall through to login logic
    // } else {
       return $token;
    // }
  }

  // If token is missing, expired, or failed verification, attempt login
  mfla_log_message('[ActionScheduler] No valid token found or token expired. Attempting API login.');
  return mfla_login_to_laravel_api();
}


/**
 * Logs into the Laravel API to retrieve a new Bearer token.
 * Stores the token and its expiry time in transients.
 *
 * @return string|false The new Bearer token on success, false on failure.
 */
function mfla_login_to_laravel_api()
{
  // Check if essential functions exist
  if (!function_exists('defined') || !function_exists('trailingslashit') || !function_exists('ltrim') || !function_exists('wp_remote_post') || !function_exists('set_transient') || !function_exists('delete_transient') || !function_exists('time')) {
      mfla_log_message('[ActionScheduler] Error: Essential WordPress/PHP functions missing in mfla_login_to_laravel_api.');
      return false;
  }

  // IMPORTANT: Define API constants in wp-config.php or elsewhere appropriate.
  $api_base_url = defined('LARAVEL_API_BASE_URL') ? LARAVEL_API_BASE_URL : null;
  $login_endpoint = defined('LARAVEL_API_LOGIN_ENDPOINT') ? LARAVEL_API_LOGIN_ENDPOINT : null;
  $username = defined('LARAVEL_API_USERNAME') ? LARAVEL_API_USERNAME : '';
  $password = defined('LARAVEL_API_PASSWORD') ? LARAVEL_API_PASSWORD : '';
  mfla_log_message('[ActionScheduler] Using username: ' . $username . ' and password: ' . $password);

  if (!$api_base_url || !$login_endpoint) {
      mfla_log_message('[ActionScheduler] Error: LARAVEL_API_BASE_URL or LARAVEL_API_LOGIN_ENDPOINT constants are not defined.');
      return false;
  }
  if (empty($username) || empty($password)) {
    mfla_log_message('[ActionScheduler] Error: API Username or Password not defined.');
    return false;
  }

  $login_url = trailingslashit($api_base_url) . ltrim($login_endpoint, '/');

  $args = array(
    'method'  => 'POST',
    'headers' => array(
      'Content-Type' => 'application/json', // Assuming Laravel expects JSON login
      'Accept'       => 'application/json',
    ),
    'body'    => json_encode(array(
      // Adjust field names if Laravel expects different ones (e.g., 'email')
      'email' => $username,
      'password' => $password,
    )),
    'timeout' => 20,
  );

  mfla_log_message('[ActionScheduler] Sending login request to: ' . $login_url);
  $response = wp_remote_post($login_url, $args);

  if (is_wp_error($response)) {
    $error_message = $response->get_error_message();
    mfla_log_message('[ActionScheduler] WP Error during login API call: ' . $error_message);
    return false;
  }

  $response_code = wp_remote_retrieve_response_code($response);
  $response_body = wp_remote_retrieve_body($response);
  $response_data = json_decode($response_body, true);

  mfla_log_message('[ActionScheduler] Login API Response Code: ' . $response_code);
  // Avoid logging sensitive data like the token itself in production logs if possible
  // mfla_log_message( '[ActionScheduler] Login API Response Body: ' . $response_body );

  if ($response_code === 200 && isset($response_data['token']) && isset($response_data['expires_in'])) {
    $token = $response_data['token'];
    $expires_in = (int) $response_data['expires_in']; // Duration in seconds
    // Ensure expires_in is reasonable (e.g., at least a few minutes, max a few days)
    $expires_in = max(60, min($expires_in, 3 * DAY_IN_SECONDS)); // Min 1 min, Max 3 days
    $expiry_timestamp = time() + $expires_in;

    // Store the token and expiry timestamp in transients
    // Use $expires_in as the transient expiration time for automatic cleanup
    set_transient('_mfla_laravel_api_token', $token, $expires_in);
    set_transient('_mfla_laravel_api_token_expiry', $expiry_timestamp, $expires_in);

    mfla_log_message('[ActionScheduler] Login successful. New token stored. Expires in: ' . $expires_in . ' seconds.');
    return $token;
  } else {
    mfla_log_message('[ActionScheduler] Error: Login failed. Code: ' . $response_code . '. Body: ' . $response_body);
    // Clear potentially invalid transients if login fails
    delete_transient('_mfla_laravel_api_token');
    delete_transient('_mfla_laravel_api_token_expiry');
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
function mfla_log_message($message)
{
  // Check if error_log function exists before using it
  if (function_exists('error_log') && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true) {
    error_log('[MetForm->Laravel API]: ' . $message);
  }
  // You could add file logging here (ensure directory is writable):
  // $log_file = WP_CONTENT_DIR . '/uploads/metform-laravel-api.log';
  // @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND | LOCK_EX);
}

// Optional: Function to verify token with API status endpoint
function mfla_verify_token_with_api($token)
{
  // IMPORTANT: Define API constants in wp-config.php or elsewhere appropriate.
  $api_base_url = defined('LARAVEL_API_BASE_URL') ? LARAVEL_API_BASE_URL : null;
  $status_endpoint = defined('LARAVEL_API_STATUS_ENDPOINT') ? LARAVEL_API_STATUS_ENDPOINT : null;

  if (!$api_base_url || !$status_endpoint) {
    // If status endpoint isn't configured, assume token is okay until it fails in a real request.
    return true;
  }

  // Check if essential functions exist
  if (!function_exists('wp_remote_get')) {
      mfla_log_message('[ActionScheduler] Error: wp_remote_get function missing in mfla_verify_token_with_api.');
      return false; // Cannot verify, assume bad state
  }

  $status_url = trailingslashit($api_base_url) . ltrim($status_endpoint, '/');
  $args = array(
    'method'  => 'GET', // Or POST, depending on the API endpoint
    'headers' => array(
      'Authorization' => 'Bearer ' . $token,
      'Accept'        => 'application/json',
    ),
    'timeout' => 15,
  );
  $response = wp_remote_get($status_url, $args); // or wp_remote_post
  if (is_wp_error($response)) {
    mfla_log_message('[ActionScheduler] WP Error during token status check: ' . $response->get_error_message());
    return false; // Treat error as potentially invalid token
  }
  $response_code = wp_remote_retrieve_response_code($response);
  mfla_log_message('[ActionScheduler] Token status check response code: ' . $response_code);
  return ($response_code >= 200 && $response_code < 300); // Assume 2xx means valid
}

/**
 * Hook the processing function to the Action Scheduler action.
 * Note: The number of arguments accepted by the callback (1) must match
 * the number of arguments passed in as_schedule_single_action's $args array.
 */
add_action('mfla_process_scheduled_submission_action', 'mfla_process_action_scheduler_submission', 10, 1);
