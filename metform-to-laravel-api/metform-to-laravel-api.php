<?php

/**
 * Plugin Name:       MetForm to Laravel API Integration
 * Plugin URI:        https://example.com/plugins/the-basics/
 * Description:       Sends MetForm submission data to specified Laravel API endpoints using Action Scheduler. Handles different logic based on form ID.
 * Version:           1.3.0
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
 */
function mfla_load_action_scheduler() {
    if ( function_exists('as_schedule_single_action') ) {
        return; // Already loaded
    }
    $action_scheduler_path = __DIR__ . '/lib/action-scheduler/action-scheduler.php';
    if ( file_exists( $action_scheduler_path ) ) {
        require_once $action_scheduler_path;
        mfla_log_message('Action Scheduler library loaded from plugin.');
    } else {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('MetForm to Laravel API plugin requires the Action Scheduler library but it was not found in lib/action-scheduler. Please download and place it correctly.', 'metform-laravel-api');
            echo '</p></div>';
        });
        mfla_log_message('Error: Action Scheduler library not found at ' . $action_scheduler_path);
    }
}
add_action( 'init', 'mfla_load_action_scheduler', 10 );


/**
 * Hook into MetForm submission after data is stored.
 * Schedules the data sending via Action Scheduler for specific forms.
 *
 * @param int   $form_id    The ID of the submitted form.
 * @param array $form_data  The raw form submission data.
 * @param array $entry_data Data prepared for entry storage.
 */
function mfla_handle_metform_submission($form_id, $form_data, $entry_data)
{
  // Check if Action Scheduler functions are available.
  if (!function_exists('as_schedule_single_action') || !function_exists('as_get_scheduled_actions')) {
      mfla_log_message('Error: Action Scheduler functions not available. Cannot schedule API submission for form ' . $form_id);
      return;
  }

  // Define target form IDs from constants (ensure they are defined in wp-config.php)
  $target_form_id_full = defined('METFORM_TARGET_FORM_ID') ? (int) METFORM_TARGET_FORM_ID : null; // e.g., 646
  $target_form_id_loan = defined('METFORM_LOAN_TARGET_FORM_ID') ? (int) METFORM_LOAN_TARGET_FORM_ID : null; // e.g., 896

  // Check if the submitted form ID matches one of our targets.
  if ($form_id != $target_form_id_full && $form_id != $target_form_id_loan) {
    // mfla_log_message( 'Skipping form ID: ' . $form_id . '. Not one of the target forms (' . ($target_form_id_full ?? 'N/A') . ', ' . ($target_form_id_loan ?? 'N/A') . ').' );
    return;
  }

  // Check if target constants are defined
  if ($target_form_id_full === null && $target_form_id_loan === null) {
      mfla_log_message('Error: Neither METFORM_TARGET_FORM_ID nor METFORM_LOAN_TARGET_FORM_ID constants are defined. Cannot determine target form.');
      return;
  }

  mfla_log_message('Handling submission for target form ID: ' . $form_id );

  // Prepare arguments for the scheduled action, wrapping in 'payload' and JSON encoding.
  $payload_to_encode = array(
      'form_id' => $form_id,
      'form_submission_data' => $form_data // Pass the raw form data
  );
  $action_args = array(
      'payload' => json_encode($payload_to_encode) // Encode the actual data
  );

  // Check if an identical action (same hook, same args) is already pending.
  // Note: Comparing JSON strings for args check. This might be less efficient
  // but necessary if AS argument comparison has issues. Consider if duplicate
  // prevention is critical or if occasional duplicates are acceptable.
  // For now, we keep the check as is, comparing the encoded payload.
  $pending_actions = as_get_scheduled_actions(array(
      'hook' => 'mfla_process_scheduled_submission_action',
      'args' => $action_args, // Compare against the encoded payload argument
      'status' => ActionScheduler_Store::STATUS_PENDING,
  ), 'ids');

  if (empty($pending_actions)) {
      // Schedule the action.
      $action_id = as_schedule_single_action(time(), 'mfla_process_scheduled_submission_action', $action_args); // Pass the combined args
      if ($action_id) {
          mfla_log_message('Scheduled Action Scheduler action mfla_process_scheduled_submission_action (ID: ' . $action_id . ') for form ' . $form_id);
      } else {
          mfla_log_message('Error: Failed to schedule Action Scheduler action mfla_process_scheduled_submission_action for form ' . $form_id);
      }
  } else {
      mfla_log_message('Action Scheduler action mfla_process_scheduled_submission_action already pending for identical form data (Form ID: ' . $form_id . '). Skipping duplicate scheduling.');
  }
}
add_action('metform_after_store_form_data', 'mfla_handle_metform_submission', 10, 3);


/**
 * Helper function to convert common affirmative strings to boolean true.
 */
function mfla_to_bool($value)
{
  if (is_bool($value)) return $value;
  if (is_numeric($value)) return (int)$value === 1;
  if (is_string($value)) {
    $cleaned_value = strtolower(trim(str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $value)));
    $affirmative = ['si', 'yes', 'accepted'];
    return in_array($cleaned_value, $affirmative, true);
  }
  return false;
}

/**
 * Helper function to format a date string from 'd-m-Y' to 'Y-m-d'.
 */
function mfla_format_date($date_string)
{
  if (empty($date_string) || !is_string($date_string)) return null;
  try {
    $date = DateTime::createFromFormat('d-m-Y', trim($date_string));
    if ($date && $date->format('d-m-Y') === trim($date_string)) {
      return $date->format('Y-m-d');
    }
   } catch (Exception $e) {
     mfla_log_message("[ActionScheduler] Warning: Exception while parsing date '$date_string'. Error: " . $e->getMessage());
     return null;
   }
   mfla_log_message("[ActionScheduler] Warning: Invalid or non-'d-m-Y' date format received: '$date_string'. Could not parse.");
   return null;
 }

/**
 * Helper function to convert a string value to a numeric type (int or float).
 */
function mfla_to_numeric($value)
{
  if ($value === null || trim($value) === '') return null;
  $cleaned = preg_replace('/[^\d.-]/', '', trim($value));
   if (!is_numeric($cleaned)) {
     mfla_log_message("[ActionScheduler] Warning: Could not convert value to numeric after cleaning: Original='$value' -> Cleaned='$cleaned'");
     return null;
   }
  return strpos($cleaned, '.') !== false ? (float)$cleaned : (int)$cleaned;
}

/**
 * Helper function to clean NID/Cedula (remove non-digits).
 */
function mfla_clean_nid($nid_string) {
    if ($nid_string === null || !is_string($nid_string)) return null;
    return preg_replace('/[^\d]/', '', $nid_string);
}

/**
 * Helper function to clean phone numbers (remove non-digits).
 */
function mfla_clean_phone($phone_string) {
    if ($phone_string === null || !is_string($phone_string)) return null;
    return preg_replace('/[^\d]/', '', $phone_string);
}


 /**
  * Main processing function called by Action Scheduler.
  * Routes the submission to the appropriate handler based on form ID.
  *
  * @param string $payload_json The JSON string containing the form ID and submission data,
  *                             passed directly by Action Scheduler because the hook accepts only one argument.
  */
 function mfla_process_action_scheduler_submission($payload_json) // Renamed $args to $payload_json for clarity
 {
    mfla_log_message('[ActionScheduler] Processing scheduled action...');

    // Validate arguments structure (expecting a non-empty JSON string)
    if (!is_string($payload_json) || empty($payload_json)) {
        mfla_log_message('[ActionScheduler] Error: Invalid argument received. Expected a non-empty JSON string. Received: ' . print_r($payload_json, true));
        return; // Stop processing if structure is wrong
    }

    // Decode the payload
    $decoded_payload = json_decode($payload_json, true); // Decode directly from the received string

    // Validate the decoded payload
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded_payload) || !isset($decoded_payload['form_id']) || !isset($decoded_payload['form_submission_data']) || !is_array($decoded_payload['form_submission_data'])) {
        mfla_log_message('[ActionScheduler] Error: Failed to decode payload or invalid decoded structure. Original JSON: ' . $payload_json . ' Decoded: ' . print_r($decoded_payload, true));
        return; // Stop processing if decoded data is bad
    }

    // Extract data from the decoded payload
    $form_id = (int) $decoded_payload['form_id'];
    $form_submission_data = $decoded_payload['form_submission_data'];

    mfla_log_message('[ActionScheduler] Routing action for Form ID: ' . $form_id . ' (from decoded payload)');

    // Define target form IDs from constants
    $target_form_id_full = defined('METFORM_TARGET_FORM_ID') ? (int) METFORM_TARGET_FORM_ID : null; // e.g., 646
    $target_form_id_loan = defined('METFORM_LOAN_TARGET_FORM_ID') ? (int) METFORM_LOAN_TARGET_FORM_ID : null; // e.g., 896

    try {
        if ($form_id === $target_form_id_full) {
            mfla_log_message('[ActionScheduler] Routing to Full Customer Submission handler (Form ID: ' . $form_id . ')');
            _mfla_process_full_customer_submission($form_submission_data);
        } elseif ($form_id === $target_form_id_loan) {
            mfla_log_message('[ActionScheduler] Routing to Simple Loan Submission handler (Form ID: ' . $form_id . ')');
            _mfla_process_simple_loan_submission($form_submission_data);
        } else {
            mfla_log_message('[ActionScheduler] Error: Received action for unexpected Form ID: ' . $form_id . '. No handler defined.');
        }
    } catch (Exception $e) {
        mfla_log_message('[ActionScheduler] Error processing submission for Form ID ' . $form_id . ': ' . $e->getMessage());
        // Re-throw the exception so Action Scheduler can handle retries/failures appropriately
        throw $e;
    }
 }
 add_action('mfla_process_scheduled_submission_action', 'mfla_process_action_scheduler_submission', 10, 1);


/**
 * Handles the submission logic for the "Full Customer" form (e.g., ID 646).
 * Checks if customer exists by NID, then either Updates (PUT) or Creates (POST) the full customer record.
 * (This function contains the original logic from mfla_process_action_scheduler_submission)
 *
 * @param array $form_submission_data The raw form data.
 * @throws Exception If a retryable error occurs (e.g., token issue, network error).
 */
function _mfla_process_full_customer_submission($form_submission_data)
{
    mfla_log_message('[ActionScheduler][Full Customer] Processing...');

    // Check if essential functions exist
    if (!function_exists('get_transient') || !function_exists('set_transient') || !function_exists('delete_transient') || !function_exists('wp_remote_post') || !function_exists('wp_remote_request')) {
        mfla_log_message('[ActionScheduler][Full Customer] Error: Essential WordPress functions are missing. Aborting.');
        throw new Exception('[ActionScheduler][Full Customer] Essential WordPress functions missing.');
    }

    // Define required API constants for this flow
    $api_base_url = defined('LARAVEL_API_BASE_URL') ? LARAVEL_API_BASE_URL : null;
    $api_create_endpoint = defined('LARAVEL_API_CREATE_CUSTOMER_ENDPOINT') ? LARAVEL_API_CREATE_CUSTOMER_ENDPOINT : null;
    $api_check_nid_endpoint = defined('LARAVEL_API_CHECK_CUSTOMER_NID_EXISTS') ? LARAVEL_API_CHECK_CUSTOMER_NID_EXISTS : null;
    $api_update_endpoint = defined('LARAVEL_API_UPDATE_CUSTOMER_ENDPOINT') ? LARAVEL_API_UPDATE_CUSTOMER_ENDPOINT : null;

    if (!$api_base_url || !$api_create_endpoint || !$api_check_nid_endpoint || !$api_update_endpoint) {
        mfla_log_message('[ActionScheduler][Full Customer] Error: One or more required API constants for full customer flow are not defined. Aborting.');
        throw new Exception('[ActionScheduler][Full Customer] Required API constants missing.');
    }

    // Validate the received form data.
    if (empty($form_submission_data) || !is_array($form_submission_data)) {
        mfla_log_message('[ActionScheduler][Full Customer] Error: Invalid or empty form data received.');
        return; // Non-retryable error
    }
    $data = $form_submission_data;

    // --- Get API Token ---
    $token = mfla_get_laravel_api_token();
    if (!$token) {
        mfla_log_message('[ActionScheduler][Full Customer] Error: Could not obtain Laravel API token.');
        throw new Exception('[ActionScheduler][Full Customer] Failed to obtain Laravel API token.');
    }
    mfla_log_message('[ActionScheduler][Full Customer] Obtained API Token. Proceeding with NID check...');

    // --- Helper function to safely get and sanitize data ---
    $get_value = function ($key, $default = null, $sanitize_callback = 'sanitize_text_field', $transform_callback = null) use ($data) {
        if (!isset($data[$key]) || trim((string)$data[$key]) === '') return $default;
        $value = $data[$key];
        if ($transform_callback && is_callable($transform_callback)) {
            $transformed_value = call_user_func($transform_callback, $value);
            if ($transformed_value === null && $value !== null) return $default;
            $value = $transformed_value;
        }
        if ($sanitize_callback && is_callable($sanitize_callback) && is_string($value)) {
            $value = call_user_func($sanitize_callback, $value);
        }
        return $value;
    };

    // --- Extract NID (cedula) ---
    $customer_nid = $get_value('cedula', null, null, 'mfla_clean_nid');
    if (empty($customer_nid)) {
        mfla_log_message('[ActionScheduler][Full Customer] Error: Customer NID (cedula) is missing or empty. Aborting.');
        return; // Non-retryable error
    }
    mfla_log_message('[ActionScheduler][Full Customer] Extracted NID: ' . $customer_nid . '. Checking if customer exists...');

    // --- API Call to Check NID ---
    $check_nid_url = trailingslashit($api_base_url) . ltrim($api_check_nid_endpoint, '/');
    $check_args = array(
        'method'  => 'POST',
        'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'),
        'body'    => json_encode(['NID' => $customer_nid]),
        'timeout' => 20,
    );
    $check_response = wp_remote_post($check_nid_url, $check_args);
    $customer_exists = false;
    $customer_id = null;

    // --- Handle Check NID Response ---
    if (is_wp_error($check_response)) {
        throw new Exception('[ActionScheduler][Full Customer] WP Error during NID check: ' . $check_response->get_error_message());
    }
    $check_response_code = wp_remote_retrieve_response_code($check_response);
    $check_response_body = wp_remote_retrieve_body($check_response);
    mfla_log_message('[ActionScheduler][Full Customer] NID Check API Response Code: ' . $check_response_code);

    if ($check_response_code >= 200 && $check_response_code < 300) {
        $check_response_data = json_decode($check_response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('[ActionScheduler][Full Customer] Failed to decode JSON from NID check API.');
        }
        if (isset($check_response_data['exists']) && $check_response_data['exists'] === true) {
            $customer_exists = true;
            if (isset($check_response_data['customer']['id'])) {
                $customer_id = $check_response_data['customer']['id'];
                mfla_log_message('[ActionScheduler][Full Customer] Customer NID exists. Customer ID: ' . $customer_id);
            } else {
                mfla_log_message('[ActionScheduler][Full Customer] Error: Customer exists but API response missing customer ID.');
                throw new Exception('[ActionScheduler][Full Customer] Customer exists but API response missing customer ID.');
            }
        } else {
            mfla_log_message('[ActionScheduler][Full Customer] Customer NID does not exist. Proceeding with creation.');
            $customer_exists = false;
        }
    } elseif ($check_response_code === 401) {
        mfla_log_message('[ActionScheduler][Full Customer] Error: NID Check API returned 401. Invalidating token.');
        delete_transient('_mfla_laravel_api_token'); delete_transient('_mfla_laravel_api_token_expiry');
        throw new Exception('[ActionScheduler][Full Customer] NID Check API returned 401.');
    } else {
        throw new Exception('[ActionScheduler][Full Customer] NID Check API failed with HTTP status ' . $check_response_code);
    }

    // --- Data Mapping & Transformation (Full Customer Payload) ---
    mfla_log_message('[ActionScheduler][Full Customer] Preparing API payload...');
    $api_payload = [ /* ... (Keep the extensive mapping logic from the original function here) ... */
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
            'references' => [],
        ],
        'details' => [],
        'terms' => false,
    ];

    // Customer Details
    $api_payload['customer']['details']['first_name'] = $get_value('mf-listing-fname');
    $api_payload['customer']['details']['last_name'] = $get_value('apellido');
    $api_payload['customer']['NID'] = $customer_nid;
    $api_payload['customer']['lead_channel'] = get_site_url(null, '', 'http');
    $api_payload['customer']['details']['birthday'] = $get_value('fecha-nacimiento', null, null, 'mfla_format_date');
    $api_payload['customer']['details']['email'] = $get_value('mf-email', null, 'sanitize_email');
    $marital_status_raw = $get_value('estado-civil', null, null, 'strtolower');
    $marital_status_map = ['soltero(a)' => 'single', 'casado(a)' => 'married', 'divorciado(a)' => 'divorced', 'viudo(a)' => 'widowed'];
    $api_payload['customer']['details']['marital_status'] = $marital_status_map[$marital_status_raw] ?? 'other';
    $api_payload['customer']['details']['nationality'] = $get_value('nacionalidad');
    $housing_type_raw = $get_value('tipo-vivienda', null, null, 'strtolower');
    $housing_type_map = ['propia' => 'owned', 'alquilada' => 'rented', 'hipotecada' => 'mortgaged', 'familiar' => 'other'];
    $api_payload['customer']['details']['housing_type'] = $housing_type_map[$housing_type_raw] ?? 'other';
    $api_payload['customer']['details']['move_in_date'] = $get_value('fecha-de-mudanza', null, null, 'mfla_format_date');

    // Customer Phones
    $phones = [];
    $celular = $get_value('celular', null, null, 'mfla_clean_phone');
    $telefono_casa = $get_value('telefono-casa', null, null, 'mfla_clean_phone');
    if ($celular) $phones[] = ['number' => $celular, 'type' => 'mobile'];
    if ($telefono_casa) $phones[] = ['number' => $telefono_casa, 'type' => 'home'];
    if (!empty($phones)) $api_payload['customer']['details']['phones'] = $phones;

    // Customer Addresses
    $addresses = [];
    $street = $get_value('direccion');
    if ($street) $addresses[] = [ 'street' => $street, 'type' => 'home' ];
    if (!empty($addresses)) $api_payload['customer']['details']['addresses'] = $addresses;

    // Customer Vehicles
    $vehicles = [];
    $vehicle_data = [];
    $vehicle_data['is_owned'] = $get_value('vehiculo-propio', null, null, 'mfla_to_bool');
    $vehicle_data['is_financed'] = $get_value('vehiculo-financiado', null, null, 'mfla_to_bool');
    $vehicle_data['brand'] = $get_value('vehiculo-marca');
    $vehicle_data['year'] = $get_value('vehiculo-anno', null, null, 'mfla_to_numeric');
    if ($vehicle_data['brand'] !== null || $vehicle_data['year'] !== null || $vehicle_data['is_owned'] !== null || $vehicle_data['is_financed'] !== null) {
        $vehicles[] = array_filter($vehicle_data, function($value) { return $value !== null; });
    }
    if (!empty($vehicles)) $api_payload['customer']['vehicles'] = $vehicles;

    // Customer References
    $references = [];
    $household_member_name = $get_value('conviviente');
    $household_member_phone = $get_value('celular-conviviente', null, null, 'mfla_clean_phone');
    if ($household_member_name || $household_member_phone) {
        $ref_phones = $household_member_phone ? [['number' => $household_member_phone, 'type' => 'mobile']] : [];
        $references[] = ['name' => $household_member_name, 'relationship' => 'household member', 'phones' => $ref_phones];
    }
    $ref1_name = $get_value('nombre-referencia-1');
    $ref1_phone = $get_value('celular-referencia-1', null, null, 'mfla_clean_phone');
    if ($ref1_name || $ref1_phone) {
        $ref1_phones = $ref1_phone ? [['number' => $ref1_phone, 'type' => 'mobile']] : [];
        $references[] = ['name' => $ref1_name, 'occupation' => $get_value('ocupacion-referencia-1'), 'relationship' => $get_value('relacion-referencia-1'), 'phones' => $ref1_phones];
    }
    if (!empty($references)) $api_payload['customer']['references'] = $references;

    // Job Info
    $api_payload['customer']['jobInfo']['is_self_employed'] = $get_value('mf-switch', false, null, 'mfla_to_bool');
    $api_payload['customer']['jobInfo']['role'] = $get_value('ocupacion');
    $api_payload['customer']['jobInfo']['start_date'] = $get_value('laborando-desde', null, null, 'mfla_format_date');
    $api_payload['customer']['jobInfo']['salary'] = $get_value('sueldo-mensual', null, null, 'mfla_to_numeric');
    $api_payload['customer']['jobInfo']['other_incomes'] = $get_value('otros-ingresos', null, null, 'mfla_to_numeric');
    $api_payload['customer']['jobInfo']['other_incomes_source'] = $get_value('descripcion-otros-ingresos');
    $api_payload['customer']['jobInfo']['supervisor_name'] = $get_value('supervisor');

    // Company Info
    $company_name = $get_value('nombre-empresa');
    if ($company_name) {
        $api_payload['customer']['company']['name'] = $company_name;
        $company_phones = [];
        $company_phone_num = $get_value('telefono-empresa', null, null, 'mfla_clean_phone');
        if ($company_phone_num) $company_phones[] = ['number' => $company_phone_num, 'type' => 'work'];
        if (!empty($company_phones)) $api_payload['customer']['company']['phones'] = $company_phones;
        $company_addresses = [];
        $company_street = $get_value('direccion-empresa');
        if ($company_street) $company_addresses[] = ['street' => $company_street, 'type' => 'work'];
        if (!empty($company_addresses)) $api_payload['customer']['company']['addresses'] = $company_addresses;
    } else {
        unset($api_payload['customer']['company']);
    }

    // Loan Application Details & Terms (Part of the full customer payload)
    $api_payload['terms'] = $get_value('aceptacion-de-condiciones', false, null, 'mfla_to_bool');
    $api_payload['details']['amount'] = $get_value('loan_amount', 0, null, 'mfla_to_numeric');
    $api_payload['details']['term'] = $get_value('loan_term', 0, null, 'mfla_to_numeric');
    $api_payload['details']['rate'] = $get_value('loan_rate', 0, null, 'mfla_to_numeric');
    $api_payload['details']['frequency'] = $get_value('loan_frequency', 'monthly');
    $api_payload['details']['purpose'] = $get_value('loan_purpose', null);

    // Recursively remove null values and empty arrays/objects
    $array_filter_recursive = function ($array) use (&$array_filter_recursive) {
        $filtered_array = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) $value = $array_filter_recursive($value);
            if ($value !== null && (!is_array($value) || !empty($value))) {
                $filtered_array[$key] = $value;
            } elseif (is_bool($value) || is_numeric($value)) {
                $filtered_array[$key] = $value;
            }
        }
        return $filtered_array;
    };
    $api_payload = $array_filter_recursive($api_payload);

    // Payload Validation
    if (empty($api_payload['customer']['NID'])) {
        mfla_log_message('[ActionScheduler][Full Customer] Error: NID missing in final payload. Aborting.');
        return; // Non-retryable
    }
    if (empty($api_payload['customer']) && empty($api_payload['details']) && !isset($api_payload['terms'])) {
        mfla_log_message('[ActionScheduler][Full Customer] Error: Payload empty after filtering. Aborting.');
        return; // Non-retryable
    }

    // --- Conditional API Call: Update (PUT) or Create (POST) ---
    if ($customer_exists && $customer_id) {
        // UPDATE Logic
        mfla_log_message('[ActionScheduler][Full Customer] Customer exists (ID: ' . $customer_id . '). Preparing UPDATE.');
        mfla_log_message('[ActionScheduler][Full Customer] Update Payload: ' . json_encode($api_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $update_url = trailingslashit($api_base_url) . trim($api_update_endpoint, '/') . '/' . $customer_id;
        $update_args = array(
            'method'  => 'PUT',
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body'    => json_encode($api_payload),
            'timeout' => 30,
        );
        $update_response = wp_remote_request($update_url, $update_args);

        if (is_wp_error($update_response)) {
            throw new Exception('[ActionScheduler][Full Customer] WP Error during UPDATE: ' . $update_response->get_error_message());
        }
        $update_response_code = wp_remote_retrieve_response_code($update_response);
        $update_response_body = wp_remote_retrieve_body($update_response);
        mfla_log_message('[ActionScheduler][Full Customer] UPDATE API Response Code: ' . $update_response_code);

        if ($update_response_code >= 200 && $update_response_code < 300) {
            mfla_log_message('[ActionScheduler][Full Customer] Success: Customer updated (ID: ' . $customer_id . ').');
        } elseif ($update_response_code === 401) {
            mfla_log_message('[ActionScheduler][Full Customer] Error: UPDATE API returned 401. Invalidating token.');
            delete_transient('_mfla_laravel_api_token'); delete_transient('_mfla_laravel_api_token_expiry');
            throw new Exception('[ActionScheduler][Full Customer] UPDATE API returned 401.');
        } elseif ($update_response_code === 404) {
             mfla_log_message('[ActionScheduler][Full Customer] Error: UPDATE API returned 404 (Customer ID: ' . $customer_id . '). Body: ' . $update_response_body);
             // Non-retryable
        } elseif ($update_response_code === 422) {
            mfla_log_message('[ActionScheduler][Full Customer] Error: UPDATE API returned 422 (Validation Error). Details: ' . $update_response_body);
            // Non-retryable
        } else {
            throw new Exception('[ActionScheduler][Full Customer] UPDATE API failed with HTTP status ' . $update_response_code);
        }
    } else {
        // CREATE Logic
        mfla_log_message('[ActionScheduler][Full Customer] Customer does not exist. Preparing CREATE.');
        mfla_log_message('[ActionScheduler][Full Customer] Create Payload: ' . json_encode($api_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $create_url = trailingslashit($api_base_url) . ltrim($api_create_endpoint, '/');
        $create_args = array(
            'method'  => 'POST',
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body'    => json_encode($api_payload),
            'timeout' => 30,
        );
        $create_response = wp_remote_post($create_url, $create_args);

        if (is_wp_error($create_response)) {
            throw new Exception('[ActionScheduler][Full Customer] WP Error during CREATE: ' . $create_response->get_error_message());
        }
        $create_response_code = wp_remote_retrieve_response_code($create_response);
        $create_response_body = wp_remote_retrieve_body($create_response);
        mfla_log_message('[ActionScheduler][Full Customer] CREATE API Response Code: ' . $create_response_code);

        if ($create_response_code >= 200 && $create_response_code < 300) {
            mfla_log_message('[ActionScheduler][Full Customer] Success: Customer created.');
        } elseif ($create_response_code === 401) {
            mfla_log_message('[ActionScheduler][Full Customer] Error: CREATE API returned 401. Invalidating token.');
            delete_transient('_mfla_laravel_api_token'); delete_transient('_mfla_laravel_api_token_expiry');
            throw new Exception('[ActionScheduler][Full Customer] CREATE API returned 401.');
        } elseif ($create_response_code === 422) {
            mfla_log_message('[ActionScheduler][Full Customer] Error: CREATE API returned 422 (Validation Error). Details: ' . $create_response_body);
            // Non-retryable
        } else {
            throw new Exception('[ActionScheduler][Full Customer] CREATE API failed with HTTP status ' . $create_response_code);
        }
    }
}


/**
 * Handles the submission logic for the "Simple Loan Application" form (e.g., ID 896).
 * Checks NID, Creates Simple Customer (if needed, including guarantor ref), Creates Simple Loan App.
 *
 * @param array $form_submission_data The raw form data.
 * @throws Exception If a retryable error occurs (e.g., token issue, network error).
 */
function _mfla_process_simple_loan_submission($form_submission_data)
{
    mfla_log_message('[ActionScheduler][Simple Loan] Processing...');

    // Check essential functions
    if (!function_exists('get_transient') || !function_exists('set_transient') || !function_exists('delete_transient') || !function_exists('wp_remote_post')) {
        mfla_log_message('[ActionScheduler][Simple Loan] Error: Essential WordPress functions missing.');
        throw new Exception('[ActionScheduler][Simple Loan] Essential WordPress functions missing.');
    }

    // Define required API constants for this flow
    $api_base_url = defined('LARAVEL_API_BASE_URL') ? LARAVEL_API_BASE_URL : null;
    $api_check_nid_endpoint = defined('LARAVEL_API_CHECK_CUSTOMER_NID_EXISTS') ? LARAVEL_API_CHECK_CUSTOMER_NID_EXISTS : null;
    $api_create_simple_customer_endpoint = defined('LARAVEL_API_CREATE_SIMPLE_CUSTOMER_ENDPOINT') ? LARAVEL_API_CREATE_SIMPLE_CUSTOMER_ENDPOINT : null;
    $api_create_simple_loan_app_endpoint = defined('LARAVEL_API_CREATE_SIMPLE_LOAN_APPLICATION_ENDPOINT') ? LARAVEL_API_CREATE_SIMPLE_LOAN_APPLICATION_ENDPOINT : null;

    if (!$api_base_url || !$api_check_nid_endpoint || !$api_create_simple_customer_endpoint || !$api_create_simple_loan_app_endpoint) {
        mfla_log_message('[ActionScheduler][Simple Loan] Error: One or more required API constants for simple loan flow are not defined. Aborting.');
        throw new Exception('[ActionScheduler][Simple Loan] Required API constants missing.');
    }

    // Validate form data
    if (empty($form_submission_data) || !is_array($form_submission_data)) {
        mfla_log_message('[ActionScheduler][Simple Loan] Error: Invalid or empty form data received.');
        return; // Non-retryable
    }
    $data = $form_submission_data;

    // --- Get API Token ---
    $token = mfla_get_laravel_api_token();
    if (!$token) {
        mfla_log_message('[ActionScheduler][Simple Loan] Error: Could not obtain Laravel API token.');
        throw new Exception('[ActionScheduler][Simple Loan] Failed to obtain Laravel API token.');
    }
    mfla_log_message('[ActionScheduler][Simple Loan] Obtained API Token.');

    // --- Helper function to safely get and sanitize data ---
    $get_value = function ($key, $default = null, $sanitize_callback = 'sanitize_text_field', $transform_callback = null) use ($data) {
        if (!isset($data[$key]) || trim((string)$data[$key]) === '') return $default;
        $value = $data[$key];
        if ($transform_callback && is_callable($transform_callback)) {
            $transformed_value = call_user_func($transform_callback, $value);
            if ($transformed_value === null && $value !== null) return $default;
            $value = $transformed_value;
        }
        if ($sanitize_callback && is_callable($sanitize_callback) && is_string($value)) {
            $value = call_user_func($sanitize_callback, $value);
        }
        return $value;
    };

    // --- Extract Applicant NID (cedula) ---
    $applicant_nid = $get_value('cedula', null, null, 'mfla_clean_nid');
    if (empty($applicant_nid)) {
        mfla_log_message('[ActionScheduler][Simple Loan] Error: Applicant NID (cedula) is missing or empty. Aborting.');
        return; // Non-retryable
    }
    mfla_log_message('[ActionScheduler][Simple Loan] Applicant NID: ' . $applicant_nid . '. Checking existence...');

    // --- API Call to Check Applicant NID ---
    $check_nid_url = trailingslashit($api_base_url) . ltrim($api_check_nid_endpoint, '/');
    $check_args = array(
        'method'  => 'POST',
        'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'),
        'body'    => json_encode(['NID' => $applicant_nid]),
        'timeout' => 20,
    );
    $check_response = wp_remote_post($check_nid_url, $check_args);
    $customer_id = null; // Initialize customer ID

    // --- Handle Check NID Response ---
    if (is_wp_error($check_response)) {
        throw new Exception('[ActionScheduler][Simple Loan] WP Error during NID check: ' . $check_response->get_error_message());
    }
    $check_response_code = wp_remote_retrieve_response_code($check_response);
    $check_response_body = wp_remote_retrieve_body($check_response);
    mfla_log_message('[ActionScheduler][Simple Loan] NID Check API Response Code: ' . $check_response_code);

    if ($check_response_code >= 200 && $check_response_code < 300) {
        $check_response_data = json_decode($check_response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('[ActionScheduler][Simple Loan] Failed to decode JSON from NID check API.');
        }
        if (isset($check_response_data['exists']) && $check_response_data['exists'] === true) {
            // Customer Exists
            if (isset($check_response_data['customer']['id'])) {
                $customer_id = $check_response_data['customer']['id'];
                mfla_log_message('[ActionScheduler][Simple Loan] Applicant NID exists. Customer ID: ' . $customer_id);
            } else {
                mfla_log_message('[ActionScheduler][Simple Loan] Error: Applicant exists but API response missing customer ID.');
                throw new Exception('[ActionScheduler][Simple Loan] Applicant exists but API response missing customer ID.');
            }
        } else {
            // Customer Does Not Exist - Proceed to Create Simple Customer
            mfla_log_message('[ActionScheduler][Simple Loan] Applicant NID does not exist. Proceeding with Simple Customer creation.');

            // --- Prepare Simple Customer Payload ---
            $simple_customer_payload = [
                'customer' => [
                    'NID' => $applicant_nid,
                    'details' => [
                        'first_name' => $get_value('mf-listing-fname'),
                        // 'last_name' => null, // API example shows null, adjust if needed
                        'email' => $get_value('mf-email', null, 'sanitize_email'),
                        'phones' => [],
                    ],
                    'references' => [],
                ]
            ];
            // Add applicant phone
            $applicant_phone = $get_value('celular', null, null, 'mfla_clean_phone');
            if ($applicant_phone) {
                $simple_customer_payload['customer']['details']['phones'][] = ['number' => $applicant_phone, 'type' => 'mobile'];
            }

            // Add guarantor/reference data if present
            $guarantor_name = $get_value('nombre-fiador');
            $guarantor_nid = $get_value('cedula-fiador', null, null, 'mfla_clean_nid');
            $guarantor_phone = $get_value('celular-fiador', null, null, 'mfla_clean_phone');
            $guarantor_occupation = $get_value('ocupacion-fiador');
            $guarantor_relationship = $get_value('parentesco-fiador');
            $guarantor_type = 'guarantor'; // Default to 'guarantor', adjust if needed

            if ($guarantor_name || $guarantor_nid || $guarantor_phone) {
                 $reference_entry = [
                     'name' => $guarantor_name,
                     'nid' => $guarantor_nid, // Added NID field
                     'relationship' => $guarantor_relationship,
                     'occupation' => $guarantor_occupation,
                     'type' => $guarantor_type, // Default to 'guarantor', adjust if needed
                     'phones' => [],
                 ];
                 if ($guarantor_phone) {
                     $reference_entry['phones'][] = ['number' => $guarantor_phone, 'type' => 'mobile'];
                 }
                 // Only add reference if it has some data
                 if (!empty(array_filter($reference_entry))) {
                    $simple_customer_payload['customer']['references'][] = array_filter($reference_entry, function($value) { return $value !== null; });
                 }
            }
            // Clean up empty arrays
             if (empty($simple_customer_payload['customer']['details']['phones'])) unset($simple_customer_payload['customer']['details']['phones']);
             if (empty($simple_customer_payload['customer']['references'])) unset($simple_customer_payload['customer']['references']);


            mfla_log_message('[ActionScheduler][Simple Loan] Simple Customer Create Payload: ' . json_encode($simple_customer_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // --- API Call to Create Simple Customer ---
            $create_simple_customer_url = trailingslashit($api_base_url) . ltrim($api_create_simple_customer_endpoint, '/');
            $create_args = array(
                'method'  => 'POST',
                'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'),
                'body'    => json_encode($simple_customer_payload),
                'timeout' => 30,
            );
            $create_response = wp_remote_post($create_simple_customer_url, $create_args);

            // --- Handle Create Simple Customer Response ---
            if (is_wp_error($create_response)) {
                throw new Exception('[ActionScheduler][Simple Loan] WP Error during Simple Customer CREATE: ' . $create_response->get_error_message());
            }
            $create_response_code = wp_remote_retrieve_response_code($create_response);
            $create_response_body = wp_remote_retrieve_body($create_response);
            mfla_log_message('[ActionScheduler][Simple Loan] Simple Customer CREATE API Response Code: ' . $create_response_code);

            if ($create_response_code >= 200 && $create_response_code < 300) {
                $create_response_data = json_decode($create_response_body, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($create_response_data['data']['id'])) {
                    $customer_id = $create_response_data['data']['id'];
                    mfla_log_message('[ActionScheduler][Simple Loan] Success: Simple Customer created. Customer ID: ' . $customer_id);
                } else {
                    mfla_log_message('[ActionScheduler][Simple Loan] Error: Simple Customer created, but failed to decode response or get customer ID. Body: ' . $create_response_body);
                    throw new Exception('[ActionScheduler][Simple Loan] Simple Customer created, but failed to get customer ID from response.');
                }
            } elseif ($create_response_code === 401) {
                mfla_log_message('[ActionScheduler][Simple Loan] Error: Simple Customer CREATE API returned 401. Invalidating token.');
                delete_transient('_mfla_laravel_api_token'); delete_transient('_mfla_laravel_api_token_expiry');
                throw new Exception('[ActionScheduler][Simple Loan] Simple Customer CREATE API returned 401.');
            } elseif ($create_response_code === 422) {
                mfla_log_message('[ActionScheduler][Simple Loan] Error: Simple Customer CREATE API returned 422 (Validation Error). Details: ' . $create_response_body);
                return; // Non-retryable validation error
            } else {
                throw new Exception('[ActionScheduler][Simple Loan] Simple Customer CREATE API failed with HTTP status ' . $create_response_code);
            }
        } // End customer creation block
    } elseif ($check_response_code === 401) {
        mfla_log_message('[ActionScheduler][Simple Loan] Error: NID Check API returned 401. Invalidating token.');
        delete_transient('_mfla_laravel_api_token'); delete_transient('_mfla_laravel_api_token_expiry');
        throw new Exception('[ActionScheduler][Simple Loan] NID Check API returned 401.');
    } else {
        throw new Exception('[ActionScheduler][Simple Loan] NID Check API failed with HTTP status ' . $check_response_code);
    }

    // --- Proceed to Create Simple Loan Application if we have a Customer ID ---
    if (empty($customer_id)) {
        mfla_log_message('[ActionScheduler][Simple Loan] Error: Could not obtain Customer ID (either from check or create). Cannot create loan application.');
        // If creation failed due to validation (422), we already returned.
        // If it failed for other reasons, an exception was thrown.
        // If check failed to return ID, an exception was thrown.
        // This case might occur if logic flow has an issue.
        return; // Stop processing this action.
    }

    mfla_log_message('[ActionScheduler][Simple Loan] Preparing Simple Loan Application for Customer ID: ' . $customer_id);

    // --- Prepare Simple Loan Application Payload ---
    $loan_app_payload = [
        'customer_id' => (int) $customer_id, // Ensure it's an integer
        'terms' => $get_value('aceptacion-de-condiciones', false, null, 'mfla_to_bool'),
        'details' => [
            'amount' => $get_value('monto-prestamo', 0, null, 'mfla_to_numeric'),
            'term' => $get_value('plazo-prestamo', 0, null, 'mfla_to_numeric'),
            'rate' => $get_value('tasa-prestamo', 0, null, 'mfla_to_numeric'),
            'frequency' => $get_value('frecuencia-prestamo', 'monthly'), // Assuming string like 'quincenal'
            'quota' => $get_value('cuota-prestamo', 0, null, 'mfla_to_numeric'),
            'purpose' => $get_value('motivo-prestamo', null),
            // 'rate' => null, // Not in form data provided
            // 'customer_comment' => null, // Not in form data provided
        ],
    ];

    // Remove null values from details
    $loan_app_payload['details'] = array_filter($loan_app_payload['details'], function($value) { return $value !== null; });

    // Basic validation for required loan fields
    if (empty($loan_app_payload['details']['amount']) || empty($loan_app_payload['details']['term'])) {
         mfla_log_message('[ActionScheduler][Simple Loan] Error: Missing required loan details (amount or term) in form data. Payload: ' . json_encode($loan_app_payload));
         return; // Non-retryable
    }

    mfla_log_message('[ActionScheduler][Simple Loan] Simple Loan App Create Payload: ' . json_encode($loan_app_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // --- API Call to Create Simple Loan Application ---
    $create_loan_app_url = trailingslashit($api_base_url) . ltrim($api_create_simple_loan_app_endpoint, '/');
    $loan_app_args = array(
        'method'  => 'POST',
        'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'),
        'body'    => json_encode($loan_app_payload),
        'timeout' => 30,
    );
    $loan_app_response = wp_remote_post($create_loan_app_url, $loan_app_args);

    // --- Handle Create Simple Loan Application Response ---
    if (is_wp_error($loan_app_response)) {
        throw new Exception('[ActionScheduler][Simple Loan] WP Error during Simple Loan App CREATE: ' . $loan_app_response->get_error_message());
    }
    $loan_app_response_code = wp_remote_retrieve_response_code($loan_app_response);
    $loan_app_response_body = wp_remote_retrieve_body($loan_app_response);
    mfla_log_message('[ActionScheduler][Simple Loan] Simple Loan App CREATE API Response Code: ' . $loan_app_response_code);

    if ($loan_app_response_code >= 200 && $loan_app_response_code < 300) {
        mfla_log_message('[ActionScheduler][Simple Loan] Success: Simple Loan Application created for Customer ID: ' . $customer_id);
    } elseif ($loan_app_response_code === 401) {
        mfla_log_message('[ActionScheduler][Simple Loan] Error: Simple Loan App CREATE API returned 401. Invalidating token.');
        delete_transient('_mfla_laravel_api_token'); delete_transient('_mfla_laravel_api_token_expiry');
        throw new Exception('[ActionScheduler][Simple Loan] Simple Loan App CREATE API returned 401.');
    } elseif ($loan_app_response_code === 422) {
        mfla_log_message('[ActionScheduler][Simple Loan] Error: Simple Loan App CREATE API returned 422 (Validation Error). Details: ' . $loan_app_response_body);
        // Non-retryable validation error
    } elseif ($loan_app_response_code === 404) {
         mfla_log_message('[ActionScheduler][Simple Loan] Error: Simple Loan App CREATE API returned 404 (Likely invalid Customer ID: ' . $customer_id . '). Body: ' . $loan_app_response_body);
         // Non-retryable
    } else {
        throw new Exception('[ActionScheduler][Simple Loan] Simple Loan App CREATE API failed with HTTP status ' . $loan_app_response_code);
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
  if (!function_exists('get_transient') || !function_exists('time')) {
      mfla_log_message('[ActionScheduler][Auth] Error: get_transient or time function missing.');
      return false;
  }
  $token = get_transient('_mfla_laravel_api_token');
  $expiry_timestamp = get_transient('_mfla_laravel_api_token_expiry');
  $current_timestamp = time();
  $buffer = 60; // 60 seconds buffer

  if ($token && $expiry_timestamp && ($expiry_timestamp > ($current_timestamp + $buffer))) {
    // mfla_log_message('[ActionScheduler][Auth] Using existing valid API token.'); // Reduce log noise
    // Optional: Verify token status if needed
    // if (defined('LARAVEL_API_TOKEN_STATUS_ENDPOINT') && !mfla_verify_token_with_api($token)) { ... }
    return $token;
  }

  mfla_log_message('[ActionScheduler][Auth] No valid token found or token expired. Attempting API login.');
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
  if (!function_exists('defined') || !function_exists('trailingslashit') || !function_exists('ltrim') || !function_exists('wp_remote_post') || !function_exists('set_transient') || !function_exists('delete_transient') || !function_exists('time')) {
      mfla_log_message('[ActionScheduler][Auth] Error: Essential functions missing in mfla_login_to_laravel_api.');
      return false;
  }

  $api_base_url = defined('LARAVEL_API_BASE_URL') ? LARAVEL_API_BASE_URL : null;
  $login_endpoint = defined('LARAVEL_API_LOGIN_ENDPOINT') ? LARAVEL_API_LOGIN_ENDPOINT : null;
  $username = defined('LARAVEL_API_USERNAME') ? LARAVEL_API_USERNAME : '';
  $password = defined('LARAVEL_API_PASSWORD') ? LARAVEL_API_PASSWORD : '';

  if (!$api_base_url || !$login_endpoint) {
      mfla_log_message('[ActionScheduler][Auth] Error: LARAVEL_API_BASE_URL or LARAVEL_API_LOGIN_ENDPOINT constants not defined.');
      return false;
  }
  if (empty($username) || empty($password)) {
    mfla_log_message('[ActionScheduler][Auth] Error: API Username or Password not defined.');
    return false;
  }

  $login_url = trailingslashit($api_base_url) . ltrim($login_endpoint, '/');
  $args = array(
    'method'  => 'POST',
    'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
    'body'    => json_encode(array('email' => $username, 'password' => $password)),
    'timeout' => 20,
  );

  mfla_log_message('[ActionScheduler][Auth] Sending login request to: ' . $login_url);
  $response = wp_remote_post($login_url, $args);

  if (is_wp_error($response)) {
    mfla_log_message('[ActionScheduler][Auth] WP Error during login API call: ' . $response->get_error_message());
    return false;
  }

  $response_code = wp_remote_retrieve_response_code($response);
  $response_body = wp_remote_retrieve_body($response);
  $response_data = json_decode($response_body, true);
  mfla_log_message('[ActionScheduler][Auth] Login API Response Code: ' . $response_code);

  if ($response_code === 200 && isset($response_data['token']) && isset($response_data['expires_in'])) {
    $token = $response_data['token'];
    $expires_in = max(60, min((int) $response_data['expires_in'], 3 * DAY_IN_SECONDS));
    $expiry_timestamp = time() + $expires_in;

    set_transient('_mfla_laravel_api_token', $token, $expires_in);
    set_transient('_mfla_laravel_api_token_expiry', $expiry_timestamp, $expires_in);

    mfla_log_message('[ActionScheduler][Auth] Login successful. New token stored. Expires in: ' . $expires_in . ' seconds.');
    return $token;
  } else {
    mfla_log_message('[ActionScheduler][Auth] Error: Login failed. Code: ' . $response_code . '. Body: ' . $response_body);
    delete_transient('_mfla_laravel_api_token');
    delete_transient('_mfla_laravel_api_token_expiry');
    return false;
  }
}

/**
 * Simple logging function.
 */
function mfla_log_message($message)
{
  if (function_exists('error_log') && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true) {
    error_log('[MetForm->Laravel API v1.3.0]: ' . $message); // Updated version
  }
}

/**
 * Optional: Function to verify token with API status endpoint
 */
function mfla_verify_token_with_api($token)
{
  $api_base_url = defined('LARAVEL_API_BASE_URL') ? LARAVEL_API_BASE_URL : null;
  $status_endpoint = defined('LARAVEL_API_TOKEN_STATUS_ENDPOINT') ? LARAVEL_API_TOKEN_STATUS_ENDPOINT : null;
  if (!$api_base_url || !$status_endpoint || !function_exists('wp_remote_get')) return true;

  $status_url = trailingslashit($api_base_url) . ltrim($status_endpoint, '/');
  $args = array(
    'method'  => 'GET',
    'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
    'timeout' => 15,
  );
  $response = wp_remote_get($status_url, $args);
  if (is_wp_error($response)) {
    mfla_log_message('[ActionScheduler][Auth] WP Error during token status check: ' . $response->get_error_message());
    return false;
  }
  $response_code = wp_remote_retrieve_response_code($response);
  // mfla_log_message('[ActionScheduler][Auth] Token status check response code: ' . $response_code); // Reduce log noise
  return ($response_code >= 200 && $response_code < 300);
}

?>
