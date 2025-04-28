<?php

/**
 * Plugin Name:       MetForm to Laravel API Integration
 * Plugin URI:        https://example.com/plugins/the-basics/
 * Description:       Sends MetForm submission data to a specified Laravel API endpoint using Action Scheduler. Checks if customer exists before creating/updating.
 * Version:           1.2.0
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
        // mfla_log_message('Action Scheduler already loaded by another plugin.'); // Commented out to reduce log spam
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
// Hook the loader function to run early during WordPress initialization. Priority 10 is standard.
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
  $form_name = isset($entry_data['form_name']) ? $entry_data['form_name'] : null;
  // IMPORTANT: Define METFORM_TARGET_FORM_ID in wp-config.php or elsewhere appropriate.
  $target_form_id = defined('METFORM_TARGET_FORM_ID') ? METFORM_TARGET_FORM_ID : null;

  if ($target_form_id === null) {
      mfla_log_message('Error: METFORM_TARGET_FORM_ID constant is not defined. Cannot determine target form.');
      return;
  }

  if ($target_form_id != $form_id && $target_form_id != $form_name) {
    // mfla_log_message( 'Skipping form. Target: ' . $target_form_id . ', Submitted ID: ' . $form_id . ', Submitted Name: ' . ($form_name ?? 'N/A') );
    return;
  }

  // Safely log form ID
  mfla_log_message('Handling submission for form ID: ' . $form_id );

  // Schedule the API call to run asynchronously using Action Scheduler
  // Pass only the necessary data. Ensure the data is serializable.
  // Pass the raw form data argument directly.
  $action_args = $form_data; // Pass the whole form data array

  // Schedule a single action to run as soon as possible.
  // Check if an identical action is already pending.
  $pending_actions = as_get_scheduled_actions(array(
      'hook' => 'mfla_process_scheduled_submission_action',
      'args' => $action_args, // Compare against the full form data
      'status' => ActionScheduler_Store::STATUS_PENDING,
  ), 'ids');


  if (empty($pending_actions)) {
      // Schedule the action. The hook name is 'mfla_process_scheduled_submission_action'.
      $action_id = as_schedule_single_action(time(), 'mfla_process_scheduled_submission_action', array('form_submission_data' => $action_args)); // Wrap args in expected structure
      if ($action_id) {
          mfla_log_message('Scheduled Action Scheduler action mfla_process_scheduled_submission_action (ID: ' . $action_id . ') for form ' . $form_id);
      } else {
          mfla_log_message('Error: Failed to schedule Action Scheduler action mfla_process_scheduled_submission_action for form ' . $form_id);
      }
  } else {
      mfla_log_message('Action Scheduler action mfla_process_scheduled_submission_action already pending for identical form data. Skipping duplicate scheduling for form ' . $form_id);
  }
}
// Hook into MetForm submission.
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
    if ($date && $date->format('d-m-Y') === trim($date_string)) {
      return $date->format('Y-m-d');
    }
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
  $cleaned = preg_replace('/[^\d.-]/', '', trim($value));

   // Check if the cleaned string is a valid numeric representation
   if (!is_numeric($cleaned)) {
     mfla_log_message("[ActionScheduler] Warning: Could not convert value to numeric after cleaning: Original='$value' -> Cleaned='$cleaned'");
     return null;
   }
   // Convert to float if it contains a decimal point, otherwise to int
  return strpos($cleaned, '.') !== false ? (float)$cleaned : (int)$cleaned;
}


 /**
  * Orchestrates sending the data to the Laravel API via Action Scheduler.
  * Checks if customer exists by NID, then either Updates (PUT) or Creates (POST).
  * Action Scheduler hook callback.
  *
  * @param array $args Arguments passed by as_schedule_single_action. Expects ['form_submission_data' => [...]].
  */
 function mfla_process_action_scheduler_submission($form_submission_data) // Renamed param for clarity, now directly receives the data
 {
  mfla_log_message('[ActionScheduler] Processing scheduled action...');

  // Check if essential functions exist
  if (!function_exists('get_transient') || !function_exists('set_transient') || !function_exists('delete_transient') || !function_exists('wp_remote_post') || !function_exists('wp_remote_request')) {
      mfla_log_message('[ActionScheduler] Error: Essential WordPress functions are missing. Aborting.');
      return;
  }

  // IMPORTANT: Define API constants in wp-config.php or elsewhere appropriate.
  $api_base_url = defined('LARAVEL_API_BASE_URL') ? LARAVEL_API_BASE_URL : null;
  $api_create_endpoint = defined('LARAVEL_API_CREATE_CUSTOMER_ENDPOINT') ? LARAVEL_API_CREATE_CUSTOMER_ENDPOINT : null;
  $api_check_nid_endpoint = defined('LARAVEL_API_CHECK_CUSTOMER_NID_EXISTS') ? LARAVEL_API_CHECK_CUSTOMER_NID_EXISTS : null;
  $api_update_endpoint = defined('LARAVEL_API_UPDATE_CUSTOMER_ENDPOINT') ? LARAVEL_API_UPDATE_CUSTOMER_ENDPOINT : null; // Relative path like 'customers'

  if (!$api_base_url || !$api_create_endpoint || !$api_check_nid_endpoint || !$api_update_endpoint) {
      mfla_log_message('[ActionScheduler] Error: One or more required API constants (LARAVEL_API_BASE_URL, LARAVEL_API_CREATE_CUSTOMER_ENDPOINT, LARAVEL_API_CHECK_CUSTOMER_NID_EXISTS, LARAVEL_API_UPDATE_CUSTOMER_ENDPOINT) are not defined. Aborting.');
      return; // Cannot proceed without API URL details
  }

  // Validate the received form data argument.
  if (empty($form_submission_data) || !is_array($form_submission_data)) {
      mfla_log_message('[ActionScheduler] Error: Invalid or empty form data received directly in scheduled action argument.');
      mfla_log_message('[ActionScheduler] Received argument type: ' . gettype($form_submission_data));
      mfla_log_message('[ActionScheduler] Received argument value: ' . print_r($form_submission_data, true));
      return; // Stop processing if data is bad
  }
  // $form_submission_data already holds the data directly.
  $data = $form_submission_data; // Shorter alias for readability

  // --- Get API Token ---
  $token = mfla_get_laravel_api_token();
  if (! $token) {
    mfla_log_message('[ActionScheduler] Error: Could not obtain Laravel API token. Aborting data send.');
    // Throw exception to let Action Scheduler handle retry for transient auth failures.
    throw new Exception('[ActionScheduler] Failed to obtain Laravel API token.');
  }
  mfla_log_message('[ActionScheduler] Obtained API Token. Proceeding with NID check...');


  // --- Helper function to safely get and sanitize data ---
  // Moved definition here to be available before first use.
  // $key: MetForm field name
  // $default: Default value if key not set
  // $sanitize_callback: Sanitization function (e.g., 'sanitize_text_field', 'sanitize_email')
  // $transform_callback: Transformation function (e.g., 'mfla_to_bool', 'mfla_format_date', 'mfla_to_numeric')
  $get_value = function ($key, $default = null, $sanitize_callback = 'sanitize_text_field', $transform_callback = null) use ($data) {
    if (!isset($data[$key]) || trim((string)$data[$key]) === '') { // Ensure comparison against empty string works for various types
        // mfla_log_message("[DEBUG] Key '$key' not set or empty."); // Debug log
        return $default;
    }
    $value = $data[$key];
    // mfla_log_message("[DEBUG] Key '$key' raw value: " . print_r($value, true)); // Debug log
    // Apply transformation first if specified (e.g., bool, numeric, date need raw value)
    if ($transform_callback && is_callable($transform_callback)) {
        $transformed_value = call_user_func($transform_callback, $value);
        // mfla_log_message("[DEBUG] Key '$key' transformed value: " . print_r($transformed_value, true)); // Debug log
        // If transformation results in null explicitly, return default early
        // Allow transformations that might return 0 or false (like mfla_to_bool)
        if ($transformed_value === null && $value !== null) { // Check if transformation explicitly returned null
             // mfla_log_message("[DEBUG] Key '$key' transformation resulted in null, returning default."); // Debug log
             return $default;
        }
        $value = $transformed_value; // Use the transformed value
    }
    // Apply sanitization *after* transformation if needed (mostly for strings)
    if ($sanitize_callback && is_callable($sanitize_callback)) {
        // Only sanitize if it's still a string after potential transformation
        if (is_string($value)) {
            $value = call_user_func($sanitize_callback, $value);
            // mfla_log_message("[DEBUG] Key '$key' sanitized value: " . print_r($value, true)); // Debug log
        }
    }
    return $value;
  };


  // --- Extract NID (cedula) ---
  // Use direct access and cleaning as $get_value might return default if field is present but empty after cleaning
  $customer_nid = isset($data['cedula']) ? preg_replace('/[^\d]/', '', (string)$data['cedula']) : null;

  if (empty($customer_nid)) {
      mfla_log_message('[ActionScheduler] Error: Customer NID (cedula) is missing or empty in the submission data. Aborting.');
      mfla_log_message('[ActionScheduler] Original NID value: ' . (isset($data['cedula']) ? $data['cedula'] : 'Not Set'));
      return; // Stop processing if NID is missing
  }
  mfla_log_message('[ActionScheduler] Extracted NID: ' . $customer_nid . '. Checking if customer exists...');


  // --- API Call to Check NID ---
  $check_nid_url = trailingslashit($api_base_url) . ltrim($api_check_nid_endpoint, '/');
  $check_args = array(
      'method'  => 'POST',
      'headers' => array(
          'Authorization' => 'Bearer ' . $token,
          'Content-Type'  => 'application/json',
          'Accept'        => 'application/json',
      ),
      'body'    => json_encode(['NID' => $customer_nid]),
      'timeout' => 20,
  );

  $check_response = wp_remote_post($check_nid_url, $check_args);
  $customer_exists = false;
  $customer_id = null; // Initialize customer ID

  // --- Handle Check NID Response ---
  if (is_wp_error($check_response)) {
      $error_message = $check_response->get_error_message();
      mfla_log_message('[ActionScheduler] WP Error during NID check API call: ' . $error_message);
      throw new Exception('[ActionScheduler] WP Error during NID check API call: ' . $error_message);
  }

  $check_response_code = wp_remote_retrieve_response_code($check_response);
  $check_response_body = wp_remote_retrieve_body($check_response);
  mfla_log_message('[ActionScheduler] NID Check API Response Code: ' . $check_response_code);
  // mfla_log_message('[ActionScheduler] NID Check API Response Body: ' . $check_response_body); // Avoid logging potentially sensitive data

  if ($check_response_code >= 200 && $check_response_code < 300) {
      $check_response_data = json_decode($check_response_body, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
          mfla_log_message('[ActionScheduler] Error: Failed to decode JSON response from NID check API. Body: ' . $check_response_body);
          throw new Exception('[ActionScheduler] Failed to decode JSON response from NID check API.');
      }

      if (isset($check_response_data['exists']) && $check_response_data['exists'] === true) {
          $customer_exists = true;
          if (isset($check_response_data['customer']['id'])) {
              $customer_id = $check_response_data['customer']['id'];
              mfla_log_message('[ActionScheduler] Customer NID exists. Customer ID: ' . $customer_id);
          } else {
              mfla_log_message('[ActionScheduler] Error: Customer exists but API response did not include customer ID. Body: ' . $check_response_body);
              // Fail the action if ID is missing but customer exists
              throw new Exception('[ActionScheduler] Customer exists but API response missing customer ID.');
          }
      } else {
          // Customer does not exist or response format unexpected
          mfla_log_message('[ActionScheduler] Customer NID does not exist or API response format unexpected. Proceeding with creation.');
          $customer_exists = false;
      }
  } elseif ($check_response_code === 401) {
      mfla_log_message('[ActionScheduler] Error: NID Check API returned 401 Unauthorized. Invalidating token.');
      delete_transient('_mfla_laravel_api_token');
      delete_transient('_mfla_laravel_api_token_expiry');
      throw new Exception('[ActionScheduler] NID Check API returned 401 Unauthorized.');
  } else {
      // Other errors during NID check (e.g., 404 if endpoint is wrong, 500 server error)
      mfla_log_message('[ActionScheduler] Error: NID Check API returned HTTP status ' . $check_response_code . '. Body: ' . $check_response_body);
      // Throw exception to allow retry for server errors
      throw new Exception('[ActionScheduler] NID Check API call failed with HTTP status ' . $check_response_code);
  }


  // --- Data Mapping & Transformation (Common for both Create and Update) ---
  mfla_log_message('[ActionScheduler] Preparing API payload from form data...');
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
      'vehicles' => [], // Changed from 'vehicle' based on example payload structure
      'references' => [], // Initialize references array here
    ],
    'details' => [], // Placeholder for loan application details (if applicable at top level)
    'terms' => false, // Initialize terms acceptance
  ];

  // --- Customer Details ---
  $api_payload['customer']['details']['first_name'] = $get_value('mf-listing-fname');
  $api_payload['customer']['details']['last_name'] = $get_value('apellido');
  $api_payload['customer']['NID'] = $customer_nid; // Use the already cleaned NID
  $api_payload['customer']['lead_channel'] = get_site_url(null, '', 'http'); // Or a specific identifier
  $api_payload['customer']['details']['birthday'] = $get_value('fecha-nacimiento', null, null, 'mfla_format_date');
  $api_payload['customer']['details']['email'] = $get_value('mf-email', null, 'sanitize_email');

  $marital_status_raw = $get_value('estado-civil', null, null, 'strtolower');
  $marital_status_map = [
    'soltero(a)' => 'single', 'casado(a)' => 'married', 'divorciado(a)' => 'divorced', 'viudo(a)' => 'widowed',
  ];
  $api_payload['customer']['details']['marital_status'] = $marital_status_map[$marital_status_raw] ?? 'other';

  $api_payload['customer']['details']['nationality'] = $get_value('nacionalidad');

  $housing_type_raw = $get_value('tipo-vivienda', null, null, 'strtolower');
  $housing_type_map = [
    'propia' => 'owned', 'alquilada' => 'rented', 'hipotecada' => 'mortgaged', 'familiar' => 'other',
  ];
  $api_payload['customer']['details']['housing_type'] = $housing_type_map[$housing_type_raw] ?? 'other';

  $api_payload['customer']['details']['move_in_date'] = $get_value('fecha-de-mudanza', null, null, 'mfla_format_date'); // Check form field name

  // --- Customer Phones ---
  $phones = [];
  $celular = $get_value('celular', null, null, function ($val) { return preg_replace('/[^\d]/', '', (string)$val); });
  $telefono_casa = $get_value('telefono-casa', null, null, function ($val) { return preg_replace('/[^\d]/', '', (string)$val); });
  if ($celular) $phones[] = ['number' => $celular, 'type' => 'mobile'];
  if ($telefono_casa) $phones[] = ['number' => $telefono_casa, 'type' => 'home'];
  if (!empty($phones)) $api_payload['customer']['details']['phones'] = $phones;

  // --- Customer Addresses ---
  $addresses = [];
  $street = $get_value('direccion');
  if ($street) {
    $addresses[] = [ 'street' => $street, 'type' => 'home', /* Add city, state etc. if available */ ];
  }
  if (!empty($addresses)) $api_payload['customer']['details']['addresses'] = $addresses;

  // --- Customer Vehicles --- (Adjusted based on example payload)
  $vehicles = [];
  $vehicle_data = []; // Temporary array for vehicle fields
  $vehicle_data['is_owned'] = $get_value('vehiculo-propio', null, null, 'mfla_to_bool'); // Check form field name
  $vehicle_data['is_financed'] = $get_value('vehiculo-financiado', null, null, 'mfla_to_bool'); // Check form field name
  $vehicle_data['brand'] = $get_value('vehiculo-marca'); // Check form field name
  $vehicle_data['year'] = $get_value('vehiculo-anno', null, null, 'mfla_to_numeric'); // Check form field name
  // Add other vehicle fields like model, type if available in form and needed by API
  // $vehicle_data['model'] = $get_value('vehiculo-modelo');
  // $vehicle_data['type'] = $get_value('vehiculo-tipo'); // e.g., 'financed', 'owned'

  // Add vehicle entry only if at least one relevant field is present
  if ($vehicle_data['brand'] !== null || $vehicle_data['year'] !== null || $vehicle_data['is_owned'] !== null || $vehicle_data['is_financed'] !== null) {
      // Filter out null values from the specific vehicle entry before adding
      $vehicles[] = array_filter($vehicle_data, function($value) { return $value !== null; });
  }
  if (!empty($vehicles)) $api_payload['customer']['vehicles'] = $vehicles;


  // --- Customer References ---
  $references = [];
  // Reference 0 (household_member/Conviviente)
  $household_member_name = $get_value('conviviente');
  $household_member_phone = $get_value('celular-conviviente', null, null, function ($val) { return preg_replace('/[^\d]/', '', (string)$val); });
  if ($household_member_name || $household_member_phone) {
    $ref_phones = $household_member_phone ? [['number' => $household_member_phone, 'type' => 'mobile']] : [];
    $references[] = ['name' => $household_member_name, 'relationship' => 'household member', 'phones' => $ref_phones];
  }
  // Reference 1
  $ref1_name = $get_value('nombre-referencia-1');
  $ref1_phone = $get_value('celular-referencia-1', null, null, function ($val) { return preg_replace('/[^\d]/', '', (string)$val); });
  if ($ref1_name || $ref1_phone) {
    $ref1_phones = $ref1_phone ? [['number' => $ref1_phone, 'type' => 'mobile']] : [];
    $references[] = [
        'name' => $ref1_name,
        'occupation' => $get_value('ocupacion-referencia-1'),
        'relationship' => $get_value('relacion-referencia-1'),
        'phones' => $ref1_phones
    ];
  }
  // Add more references if form has them (e.g., Reference 2)
  if (!empty($references)) $api_payload['customer']['references'] = $references;


  // --- Job Info ---
  $api_payload['customer']['jobInfo']['is_self_employed'] = $get_value('mf-switch', false, null, 'mfla_to_bool'); // Check form field name
  $api_payload['customer']['jobInfo']['role'] = $get_value('ocupacion');
  $api_payload['customer']['jobInfo']['start_date'] = $get_value('laborando-desde', null, null, 'mfla_format_date');
  $api_payload['customer']['jobInfo']['salary'] = $get_value('sueldo-mensual', null, null, 'mfla_to_numeric');
  $api_payload['customer']['jobInfo']['other_incomes'] = $get_value('otros-ingresos', null, null, 'mfla_to_numeric');
  $api_payload['customer']['jobInfo']['other_incomes_source'] = $get_value('descripcion-otros-ingresos');
  $api_payload['customer']['jobInfo']['supervisor_name'] = $get_value('supervisor');
  // Add other jobInfo fields from example if available in form: payment_type, frequency, bank, schedule, level


  // --- Company Info ---
  $company_name = $get_value('nombre-empresa');
  if ($company_name) {
    $api_payload['customer']['company']['name'] = $company_name;
    // Company Phones
    $company_phones = [];
    $company_phone_num = $get_value('telefono-empresa', null, null, function ($val) { return preg_replace('/[^\d]/', '', (string)$val); });
    if ($company_phone_num) $company_phones[] = ['number' => $company_phone_num, 'type' => 'work'];
    if (!empty($company_phones)) $api_payload['customer']['company']['phones'] = $company_phones;
    // Company Addresses
    $company_addresses = [];
    $company_street = $get_value('direccion-empresa');
    if ($company_street) $company_addresses[] = ['street' => $company_street, 'type' => 'work'];
    if (!empty($company_addresses)) $api_payload['customer']['company']['addresses'] = $company_addresses;
    // Add company email if available
    // $api_payload['customer']['company']['email'] = $get_value('email-empresa', null, 'sanitize_email');
  } else {
    // If no company name, remove the company object entirely if API allows/prefers
     unset($api_payload['customer']['company']);
  }


  // --- Loan Application Details & Terms ---
  // Map 'aceptacion-de-condiciones' to top-level 'terms' field.
  $terms_accepted_bool = $get_value('aceptacion-de-condiciones', false, null, 'mfla_to_bool');
  $api_payload['terms'] = $terms_accepted_bool; // Send boolean true or false

  // Add other loan application fields if they exist in the form and API spec
  // These might belong under a different top-level key like 'loanApplication' or directly under 'details'
  // Based on original code, they were under 'details'. Adjust if needed.
  // Sending defaults might still cause validation errors if API requires actual values.
   $api_payload['details']['amount'] = $get_value('loan_amount', 0, null, 'mfla_to_numeric'); // Example field name
   $api_payload['details']['term'] = $get_value('loan_term', 0, null, 'mfla_to_numeric'); // Example field name
   $api_payload['details']['rate'] = $get_value('loan_rate', 0, null, 'mfla_to_numeric'); // Example field name
   $api_payload['details']['frequency'] = $get_value('loan_frequency', 'monthly'); // Example field name
   $api_payload['details']['purpose'] = $get_value('loan_purpose', null); // Example field name


  // --- End of Mapping Logic ---

  // Recursively remove null values and empty arrays/objects from the payload.
  // Be cautious if the API explicitly requires null or empty structures.
  $array_filter_recursive = function ($array) use (&$array_filter_recursive) {
      $filtered_array = [];
      foreach ($array as $key => $value) {
          if (is_array($value)) {
              $value = $array_filter_recursive($value);
          }
          // Keep the value if it's not null AND (if it's an array/object) it's not empty.
          // Also keep boolean false and numeric 0.
          if ($value !== null && (!is_array($value) || !empty($value))) {
              $filtered_array[$key] = $value;
          } elseif (is_bool($value) || is_numeric($value)) { // Explicitly keep booleans and numbers (like 0 or false)
              $filtered_array[$key] = $value;
          }
      }
      // Ensure the filtered result is an object if the original was, for JSON consistency if needed
      // return is_object($array) ? (object)$filtered_array : $filtered_array;
      // Let's keep it as an array for simplicity with json_encode
      return $filtered_array;
  };

  $api_payload = $array_filter_recursive($api_payload);


  // --- Payload Validation ---
  // Basic check: Ensure customer NID is still present after filtering
  if (empty($api_payload['customer']['NID'])) {
    mfla_log_message('[ActionScheduler] Error: Customer NID (cedula) is missing or invalid in the final payload after processing. Aborting.');
    mfla_log_message('[ActionScheduler] Payload before aborting: ' . json_encode($api_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return;
  }

  // Check if the payload is fundamentally empty after filtering
  if (empty($api_payload['customer']) && empty($api_payload['details']) && !isset($api_payload['terms'])) {
    mfla_log_message('[ActionScheduler] Error: Payload is effectively empty after mapping and filtering. Aborting.');
    mfla_log_message('[ActionScheduler] Original form data: ' . print_r($data, true));
    return;
  }


  // --- Conditional API Call: Update (PUT) or Create (POST) ---

  if ($customer_exists && $customer_id) {
      // --- UPDATE Logic ---
      mfla_log_message('[ActionScheduler] Customer exists (ID: ' . $customer_id . '). Preparing UPDATE request.');
      // Use JSON_PRETTY_PRINT for easier debugging in logs. Add JSON_UNESCAPED_UNICODE.
      mfla_log_message('[ActionScheduler] Update Payload: ' . json_encode($api_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

      // Construct Update URL: base + endpoint + / + id
      $update_url = trailingslashit($api_base_url) . trim($api_update_endpoint, '/') . '/' . $customer_id;
      mfla_log_message('[ActionScheduler] Sending PUT request to update endpoint: ' . $update_url);

      $update_args = array(
          'method'  => 'PUT', // Use PUT as specified
          'headers' => array(
              'Authorization' => 'Bearer ' . $token,
              'Content-Type'  => 'application/json',
              'Accept'        => 'application/json',
          ),
          'body'    => json_encode($api_payload), // Send the mapped payload
          'timeout' => 30,
      );

      // Use wp_remote_request for PUT method
      $update_response = wp_remote_request($update_url, $update_args);

      // --- Handle Update Response ---
      if (is_wp_error($update_response)) {
          $error_message = $update_response->get_error_message();
          mfla_log_message('[ActionScheduler] WP Error during UPDATE API call: ' . $error_message);
          // Throw exception to signal failure and allow retry
          throw new Exception('[ActionScheduler] WP Error during UPDATE API call: ' . $error_message);
      }

      $update_response_code = wp_remote_retrieve_response_code($update_response);
      $update_response_body = wp_remote_retrieve_body($update_response);

      mfla_log_message('[ActionScheduler] UPDATE API Response Code: ' . $update_response_code);
      mfla_log_message('[ActionScheduler] UPDATE API Response Body: ' . $update_response_body);

      if ($update_response_code >= 200 && $update_response_code < 300) {
          // Success (e.g., 200 OK, 204 No Content)
          mfla_log_message('[ActionScheduler] Success: Customer data updated via API (ID: ' . $customer_id . ').');
      } elseif ($update_response_code === 401) {
          // Unauthorized
          mfla_log_message('[ActionScheduler] Error: UPDATE API returned 401 Unauthorized. Invalidating token.');
          delete_transient('_mfla_laravel_api_token');
          delete_transient('_mfla_laravel_api_token_expiry');
          throw new Exception('[ActionScheduler] UPDATE API returned 401 Unauthorized.');
      } elseif ($update_response_code === 404) {
           // Not Found - Customer ID might be wrong or deleted between check and update
           mfla_log_message('[ActionScheduler] Error: UPDATE API returned 404 Not Found (Customer ID: ' . $customer_id . '). Body: ' . $update_response_body);
           // Do not retry 404 usually, log as error.
      } elseif ($update_response_code === 422) {
          // Validation Error
          mfla_log_message('[ActionScheduler] Error: UPDATE API returned 422 Unprocessable Entity (Validation Error). Details: ' . $update_response_body);
          // Do not retry validation errors. Log details.
      } else {
          // Other API errors
          mfla_log_message('[ActionScheduler] Error: UPDATE API returned HTTP status ' . $update_response_code . '. Body: ' . $update_response_body);
          // Throw exception to signal failure and allow potential retry for server errors (5xx)
          throw new Exception('[ActionScheduler] UPDATE API call failed with HTTP status ' . $update_response_code);
      }

  } else {
      // --- CREATE Logic ---
      mfla_log_message('[ActionScheduler] Customer does not exist or check failed. Preparing CREATE request.');
      // Use JSON_PRETTY_PRINT for easier debugging in logs. Add JSON_UNESCAPED_UNICODE.
      mfla_log_message('[ActionScheduler] Create Payload: ' . json_encode($api_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

      // --- API Call (Create) ---
      $create_url = trailingslashit($api_base_url) . ltrim($api_create_endpoint, '/');
      mfla_log_message('[ActionScheduler] Sending POST request to create endpoint: ' . $create_url);
      $create_args = array(
          'method'  => 'POST',
          'headers' => array(
              'Authorization' => 'Bearer ' . $token,
              'Content-Type'  => 'application/json',
              'Accept'        => 'application/json',
          ),
          'body'    => json_encode($api_payload),
          'timeout' => 30,
      );

      $create_response = wp_remote_post($create_url, $create_args);

      // --- Handle Create Response ---
      if (is_wp_error($create_response)) {
          $error_message = $create_response->get_error_message();
          mfla_log_message('[ActionScheduler] WP Error during CREATE API call: ' . $error_message);
          throw new Exception('[ActionScheduler] WP Error during CREATE API call: ' . $error_message);
      }

      $create_response_code = wp_remote_retrieve_response_code($create_response);
      $create_response_body = wp_remote_retrieve_body($create_response);

      mfla_log_message('[ActionScheduler] CREATE API Response Code: ' . $create_response_code);
      mfla_log_message('[ActionScheduler] CREATE API Response Body: ' . $create_response_body);

      if ($create_response_code >= 200 && $create_response_code < 300) {
          // Success (e.g., 201 Created)
          mfla_log_message('[ActionScheduler] Success: Data sent to Laravel API (Create).');
      } elseif ($create_response_code === 401) {
          // Unauthorized
          mfla_log_message('[ActionScheduler] Error: CREATE API returned 401 Unauthorized. Invalidating token.');
          delete_transient('_mfla_laravel_api_token');
          delete_transient('_mfla_laravel_api_token_expiry');
          throw new Exception('[ActionScheduler] CREATE API returned 401 Unauthorized.');
      } elseif ($create_response_code === 422) {
          // Validation Error
          mfla_log_message('[ActionScheduler] Error: CREATE API returned 422 Unprocessable Entity (Validation Error). Details: ' . $create_response_body);
          // Do not retry validation errors. Log details.
      } else {
          // Other API errors
          mfla_log_message('[ActionScheduler] Error: CREATE API returned HTTP status ' . $create_response_code . '. Body: ' . $create_response_body);
          // Throw exception to signal failure and allow potential retry for server errors (5xx)
          throw new Exception('[ActionScheduler] CREATE API call failed with HTTP status ' . $create_response_code);
      }
  } // End Create/Update conditional block

} // End mfla_process_action_scheduler_submission function


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
    // Optional: Add verification call if needed frequently
    // if (defined('LARAVEL_API_TOKEN_STATUS_ENDPOINT') && !mfla_verify_token_with_api($token)) { ... }
    return $token;
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
  // mfla_log_message('[ActionScheduler] Using username: ' . $username); // Avoid logging credentials

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
      'Content-Type' => 'application/json',
      'Accept'       => 'application/json',
    ),
    'body'    => json_encode(array(
      'email' => $username, // Adjust field name if needed (e.g., 'username')
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
  // Avoid logging sensitive data like the token itself in production logs
  // mfla_log_message( '[ActionScheduler] Login API Response Body: ' . $response_body );

  if ($response_code === 200 && isset($response_data['token']) && isset($response_data['expires_in'])) {
    $token = $response_data['token'];
    $expires_in = (int) $response_data['expires_in']; // Duration in seconds
    $expires_in = max(60, min($expires_in, 3 * DAY_IN_SECONDS)); // Sanity check expiry
    $expiry_timestamp = time() + $expires_in;

    // Store the token and expiry timestamp in transients
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
 *
 * @param string $message The message to log.
 */
function mfla_log_message($message)
{
  // Check if error_log function exists before using it
  if (function_exists('error_log') && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true) {
    error_log('[MetForm->Laravel API v1.2.0]: ' . $message); // Added version to log prefix
  }
  // Optional: Add file logging here
  // $log_file = WP_CONTENT_DIR . '/uploads/metform-laravel-api.log';
  // @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND | LOCK_EX);
}

// Optional: Function to verify token with API status endpoint
function mfla_verify_token_with_api($token)
{
  // IMPORTANT: Define API constants in wp-config.php or elsewhere appropriate.
  $api_base_url = defined('LARAVEL_API_BASE_URL') ? LARAVEL_API_BASE_URL : null;
  $status_endpoint = defined('LARAVEL_API_TOKEN_STATUS_ENDPOINT') ? LARAVEL_API_TOKEN_STATUS_ENDPOINT : null;

  if (!$api_base_url || !$status_endpoint) {
    return true; // Assume okay if endpoint not configured
  }

  if (!function_exists('wp_remote_get')) {
      mfla_log_message('[ActionScheduler] Error: wp_remote_get function missing in mfla_verify_token_with_api.');
      return false; // Cannot verify
  }

  $status_url = trailingslashit($api_base_url) . ltrim($status_endpoint, '/');
  $args = array(
    'method'  => 'GET',
    'headers' => array(
      'Authorization' => 'Bearer ' . $token,
      'Accept'        => 'application/json',
    ),
    'timeout' => 15,
  );
  $response = wp_remote_get($status_url, $args);
  if (is_wp_error($response)) {
    mfla_log_message('[ActionScheduler] WP Error during token status check: ' . $response->get_error_message());
    return false;
  }
  $response_code = wp_remote_retrieve_response_code($response);
  mfla_log_message('[ActionScheduler] Token status check response code: ' . $response_code);
  return ($response_code >= 200 && $response_code < 300);
}

/**
 * Hook the processing function to the Action Scheduler action.
 * The callback accepts 1 argument: the $args array passed during scheduling.
 */
add_action('mfla_process_scheduled_submission_action', 'mfla_process_action_scheduler_submission', 10, 1);
