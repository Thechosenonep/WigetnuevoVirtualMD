<?php
namespace VirtualMD\HeroBooking;

/**
 * PayPal Checkout – Crear y capturar órdenes
 *
 * Endpoints AJAX que usa la PayPal Orders API v2 para
 * crear y capturar pagos desde el widget de booking.
 *
 * Requiere en wp-config.php:
 *   define('PAYPAL_CLIENT_ID',     'tu-client-id');
 *   define('PAYPAL_CLIENT_SECRET', 'tu-client-secret');
 *   define('PAYPAL_MODE',          'sandbox'); // o 'live'
 *
 * @package VirtualMD
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Obtener un access token de PayPal (OAuth2 client_credentials).
 */
function vm_paypal_get_access_token() {
    if ( ! defined( 'PAYPAL_CLIENT_ID' ) || ! PAYPAL_CLIENT_ID ||
         ! defined( 'PAYPAL_CLIENT_SECRET' ) || ! PAYPAL_CLIENT_SECRET ) {
        return new \WP_Error( 'paypal_auth', 'Credenciales de PayPal no configuradas en wp-config.php' );
    }

    $mode    = defined( 'PAYPAL_MODE' ) ? PAYPAL_MODE : 'sandbox';
    $base    = ( $mode === 'live' )
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';

    $response = wp_remote_post( $base . '/v1/oauth2/token', [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode( PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET ),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body'    => 'grant_type=client_credentials',
        'timeout' => 15,
    ] );

    if ( is_wp_error( $response ) ) {
        return new \WP_Error( 'paypal_auth', $response->get_error_message() );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $body['access_token'] ) ) {
        return new \WP_Error( 'paypal_auth', 'No se obtuvo access_token de PayPal' );
    }

    return $body['access_token'];
}

/**
 * Helper: URL base de la API de PayPal.
 */
function vm_paypal_api_base() {
    $mode = defined( 'PAYPAL_MODE' ) ? PAYPAL_MODE : 'sandbox';
    return ( $mode === 'live' )
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

/**
 * Registrar contexto técnico sin datos personales del paciente.
 */
function vm_paypal_log( $message, $context = [] ) {
    if ( ! empty( $context ) ) {
        $message .= ' ' . wp_json_encode( $context );
    }

    error_log( '[VM PayPal] ' . $message );
}

/**
 * Resumen del payload para logs, evitando PII.
 */
function vm_paypal_booking_summary( $booking_data ) {
    return [
        'bookingStart' => isset( $booking_data['bookingStart'] ) ? $booking_data['bookingStart'] : '',
        'serviceId'    => isset( $booking_data['serviceId'] ) ? (int) $booking_data['serviceId'] : 0,
        'providerId'   => isset( $booking_data['providerId'] ) ? (int) $booking_data['providerId'] : 0,
        'locationId'   => isset( $booking_data['locationId'] ) ? (int) $booking_data['locationId'] : 0,
    ];
}

function vm_amelia_get_submitted_customer_from_booking( $booking_data ) {
    if ( empty( $booking_data['bookings'] ) || ! is_array( $booking_data['bookings'] ) ) {
        return [];
    }

    foreach ( $booking_data['bookings'] as $booking ) {
        if ( empty( $booking['customer'] ) || ! is_array( $booking['customer'] ) ) {
            continue;
        }

        $customer = $booking['customer'];
        $first_name = isset( $customer['firstName'] ) ? trim( sanitize_text_field( $customer['firstName'] ) ) : '';
        $last_name  = isset( $customer['lastName'] ) ? trim( sanitize_text_field( $customer['lastName'] ) ) : '';
        $email      = isset( $customer['email'] ) ? sanitize_email( $customer['email'] ) : '';
        $phone      = isset( $customer['phone'] ) ? sanitize_text_field( $customer['phone'] ) : '';
        $country_phone_iso = isset( $customer['countryPhoneIso'] ) ? strtolower( sanitize_text_field( $customer['countryPhoneIso'] ) ) : '';

        if ( $first_name === '' && $last_name === '' && $email === '' && $phone === '' ) {
            continue;
        }

        return [
            'firstName' => $first_name,
            'lastName'  => $last_name,
            'fullName'  => trim( $first_name . ' ' . $last_name ),
            'email'     => $email,
            'phone'     => $phone,
            'countryPhoneIso' => $country_phone_iso,
        ];
    }

    return [];
}

function vm_amelia_store_form_context_for_booking( $booking_data ) {
    $customer = vm_amelia_get_submitted_customer_from_booking( $booking_data );

    if ( empty( $customer ) ) {
        return '';
    }

    $token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'vm_amelia_form_', true ) );

    set_transient(
        'vm_amelia_form_context_' . $token,
        [
            'customer' => $customer,
            'booking'  => vm_paypal_booking_summary( $booking_data ),
        ],
        2 * HOUR_IN_SECONDS
    );

    return $token;
}

function vm_amelia_get_form_context_for_token( $token ) {
    if ( ! $token ) {
        return null;
    }

    $stored = get_transient( 'vm_amelia_form_context_' . $token );

    return is_array( $stored ) ? $stored : null;
}

function vm_amelia_get_technical_customer_markers( $technical_customer ) {
    if ( empty( $technical_customer ) || ! is_array( $technical_customer ) ) {
        return [];
    }

    $haystack = implode( ' ', array_filter( [
        isset( $technical_customer['email'] ) ? (string) $technical_customer['email'] : '',
        isset( $technical_customer['lastName'] ) ? (string) $technical_customer['lastName'] : '',
        isset( $technical_customer['fullName'] ) ? (string) $technical_customer['fullName'] : '',
    ] ) );

    $markers = [];

    if ( preg_match_all( '/\+vm([a-f0-9]{8,12})@/i', $haystack, $matches ) ) {
        $markers = array_merge( $markers, $matches[1] );
    }

    if ( preg_match_all( '/VM-([a-f0-9]{8,12})/i', $haystack, $matches ) ) {
        $markers = array_merge( $markers, $matches[1] );
    }

    return array_values( array_unique( array_map( 'strtolower', $markers ) ) );
}

function vm_amelia_register_form_context_lookup( $token, $technical_customer ) {
    if ( ! $token ) {
        return;
    }

    foreach ( vm_amelia_get_technical_customer_markers( $technical_customer ) as $marker ) {
        set_transient(
            'vm_amelia_form_context_marker_' . $marker,
            $token,
            2 * HOUR_IN_SECONDS
        );
    }
}

function vm_amelia_update_form_context_technical_customer( $token, $booking_data ) {
    if ( ! $token ) {
        return;
    }

    $technical_customer = vm_amelia_get_submitted_customer_from_booking( $booking_data );
    if ( empty( $technical_customer ) ) {
        return;
    }

    $transient_key = 'vm_amelia_form_context_' . $token;
    $stored = get_transient( $transient_key );

    if ( ! is_array( $stored ) ) {
        $stored = [];
    }

    $stored['technicalCustomer'] = $technical_customer;

    set_transient( $transient_key, $stored, 2 * HOUR_IN_SECONDS );
    vm_amelia_register_form_context_lookup( $token, $technical_customer );
}

function vm_amelia_append_form_customer_to_internal_notes( $booking_data ) {
    $customer = vm_amelia_get_submitted_customer_from_booking( $booking_data );

    if ( empty( $customer ) ) {
        return $booking_data;
    }

    $lines = [ 'Datos capturados en formulario:' ];

    if ( ! empty( $customer['fullName'] ) ) {
        $lines[] = 'Paciente: ' . $customer['fullName'];
    }

    if ( ! empty( $customer['email'] ) ) {
        $lines[] = 'Correo: ' . $customer['email'];
    }

    if ( ! empty( $customer['phone'] ) ) {
        $lines[] = 'Telefono: ' . $customer['phone'];
    }

    $note = implode( "\n", $lines );

    if ( ! empty( $booking_data['internalNotes'] ) ) {
        $booking_data['internalNotes'] .= "\n" . $note;
    } else {
        $booking_data['internalNotes'] = $note;
    }

    return $booking_data;
}

function vm_paypal_normalize_phone( $phone ) {
    return preg_replace( '/\D+/', '', (string) $phone );
}

function vm_paypal_normalize_name( $first_name, $last_name = '' ) {
    $name = trim( (string) $first_name . ' ' . (string) $last_name );
    $name = remove_accents( $name );
    $name = strtolower( $name );
    $name = preg_replace( '/\s+/', ' ', $name );

    return trim( $name );
}

function vm_amelia_unique_customer_for_booking( $customer, $context_token = '' ) {
    $first_name = isset( $customer['firstName'] ) ? trim( sanitize_text_field( $customer['firstName'] ) ) : '';
    $last_name  = isset( $customer['lastName'] ) ? trim( sanitize_text_field( $customer['lastName'] ) ) : '';

    $token_seed = $context_token ? $context_token . '|' . microtime( true ) . '|' . wp_rand() : uniqid( 'vm_amelia_booking_', true );
    $token = substr( md5( $token_seed ), 0, 10 );
    $technical_email = 'amelia-placeholder+vm' . $token . '@virtualmd.mx';
    $technical_phone = '999' . substr( str_pad( (string) hexdec( substr( $token, 0, 7 ) ), 7, '0', STR_PAD_LEFT ), -7 );

    $unique_customer = $customer;
    $unique_customer['firstName'] = $first_name !== '' ? $first_name : 'Paciente';
    $unique_customer['lastName']  = trim( ( $last_name !== '' ? $last_name . ' ' : '' ) . 'VM-' . strtoupper( substr( $token, 0, 8 ) ) );
    $unique_customer['email']     = $technical_email;
    $unique_customer['phone']     = $technical_phone;
    $unique_customer['id']        = null;
    $unique_customer['externalId'] = null;
    $unique_customer['countryPhoneIso'] = '';

    return $unique_customer;
}

function vm_amelia_extract_success_payload_from_booking_response( $decoded ) {
    $data = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : [];
    $type = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : 'appointment';

    if ( $type !== 'appointment' || empty( $data['appointment']['bookings'][0] ) || ! is_array( $data['appointment']['bookings'][0] ) ) {
        return [
            'success' => false,
            'message' => 'Respuesta de Amelia sin booking de appointment para acciones post-booking',
        ];
    }

    $booking = $data['appointment']['bookings'][0];
    $payment = ! empty( $booking['payments'][0] ) && is_array( $booking['payments'][0] ) ? $booking['payments'][0] : [];

    $booking_id = isset( $booking['id'] ) ? (int) $booking['id'] : 0;
    $customer_id = isset( $booking['customerId'] ) ? (int) $booking['customerId'] : 0;
    $payment_id = isset( $payment['id'] ) ? (int) $payment['id'] : 0;
    $package_customer_id = isset( $booking['packageCustomerId'] ) ? $booking['packageCustomerId'] : null;

    if ( ! $booking_id || ! $customer_id ) {
        return [
            'success' => false,
            'message' => 'Respuesta de Amelia sin bookingId/customerId para acciones post-booking',
        ];
    }

    return [
        'success'    => true,
        'booking_id' => $booking_id,
        'payload'    => [
            'type'                     => 'appointment',
            'appointmentStatusChanged' => false,
            'recurring'                => [],
            'packageId'                => null,
            'customerId'               => $customer_id,
            'paymentId'                => $payment_id ?: null,
            'packageCustomerId'        => $package_customer_id,
        ],
    ];
}

function vm_amelia_run_booking_success_actions( $booking_id, $payload, $form_context_token = '' ) {
    if ( ! defined( 'AMELIA_API_KEY' ) || ! AMELIA_API_KEY ) {
        return [ 'success' => false, 'message' => 'AMELIA_API_KEY no definida' ];
    }

    $url = add_query_arg( [
        'action' => 'wpamelia_api',
        'call'   => '/api/v1/bookings/success/' . (int) $booking_id,
    ], admin_url( 'admin-ajax.php' ) );

    $headers = [
        'Content-Type' => 'application/json',
        'Amelia'       => AMELIA_API_KEY,
    ];

    if ( $form_context_token ) {
        $headers['X-VM-Amelia-Form-Token'] = $form_context_token;
    }

    $response = wp_remote_request( $url, [
        'method'    => 'POST',
        'timeout'   => 20,
        'headers'   => $headers,
        'body'      => wp_json_encode( $payload ),
        'sslverify' => false,
        'cookies'   => $_COOKIE,
    ] );

    if ( is_wp_error( $response ) ) {
        return [
            'success' => false,
            'message' => 'Error ejecutando acciones post-booking: ' . $response->get_error_message(),
        ];
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body_res    = wp_remote_retrieve_body( $response );
    $decoded     = json_decode( $body_res, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return [
            'success' => false,
            'message' => 'Respuesta post-booking no es JSON válido. HTTP ' . $status_code,
            'raw'     => substr( $body_res, 0, 300 ),
        ];
    }

    if ( $status_code >= 200 && $status_code < 300 ) {
        return [ 'success' => true, 'data' => $decoded ];
    }

    return [
        'success' => false,
        'message' => isset( $decoded['message'] ) ? $decoded['message'] : 'Error al ejecutar acciones post-booking',
        'status'  => $status_code,
        'amelia'  => $decoded,
    ];
}

function vm_amelia_api_request( $method, $path, $body = null, $extra_headers = [] ) {
    if ( ! defined( 'AMELIA_API_KEY' ) || ! AMELIA_API_KEY ) {
        return [ 'success' => false, 'message' => 'AMELIA_API_KEY no definida' ];
    }

    $url = add_query_arg( [
        'action' => 'wpamelia_api',
        'call'   => '/api/v1' . $path,
    ], admin_url( 'admin-ajax.php' ) );

    $headers = array_merge( [
        'Content-Type' => 'application/json',
        'Amelia'       => AMELIA_API_KEY,
    ], $extra_headers );

    $args = [
        'method'    => $method,
        'timeout'   => 20,
        'headers'   => $headers,
        'sslverify' => false,
        'cookies'   => $_COOKIE,
    ];

    if ( $body !== null ) {
        $args['body'] = wp_json_encode( $body );
    }

    $response = wp_remote_request( $url, $args );

    if ( is_wp_error( $response ) ) {
        return [
            'success' => false,
            'message' => $response->get_error_message(),
        ];
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body_res    = wp_remote_retrieve_body( $response );
    $decoded     = json_decode( $body_res, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return [
            'success' => false,
            'message' => 'Respuesta de Amelia no es JSON válido. HTTP ' . $status_code,
            'raw'     => substr( $body_res, 0, 300 ),
        ];
    }

    if ( $status_code >= 200 && $status_code < 300 ) {
        return [ 'success' => true, 'data' => $decoded ];
    }

    return [
        'success' => false,
        'message' => isset( $decoded['message'] ) ? $decoded['message'] : 'Error en API Amelia',
        'status'  => $status_code,
        'amelia'  => $decoded,
    ];
}

function vm_get_defined_constant_value( $names, $default = '' ) {
    foreach ( (array) $names as $name ) {
        if ( defined( $name ) && constant( $name ) !== '' ) {
            return constant( $name );
        }
    }

    return $default;
}

function vm_amelia_get_settings_array() {
    $settings = get_option( 'amelia_settings' );

    if ( is_string( $settings ) ) {
        $decoded = json_decode( $settings, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            return $decoded;
        }
    }

    return is_array( $settings ) ? $settings : [];
}

function vm_amelia_flatten_settings( $value, $path = '' ) {
    $flat = [];

    if ( is_array( $value ) ) {
        foreach ( $value as $key => $item ) {
            $child_path = $path === '' ? (string) $key : $path . '.' . (string) $key;
            $flat = array_merge( $flat, vm_amelia_flatten_settings( $item, $child_path ) );
        }

        return $flat;
    }

    if ( is_scalar( $value ) && (string) $value !== '' ) {
        $flat[ $path ] = (string) $value;
    }

    return $flat;
}

function vm_amelia_find_setting_value( $required_tokens, $excluded_tokens = [] ) {
    $flat = vm_amelia_flatten_settings( vm_amelia_get_settings_array() );

    foreach ( $flat as $path => $value ) {
        $normalized_path = preg_replace( '/[^a-z0-9]+/', '', strtolower( $path ) );
        $matches = true;

        foreach ( (array) $required_tokens as $token ) {
            $token = preg_replace( '/[^a-z0-9]+/', '', strtolower( $token ) );
            if ( $token === '' || strpos( $normalized_path, $token ) === false ) {
                $matches = false;
                break;
            }
        }

        if ( ! $matches ) {
            continue;
        }

        foreach ( (array) $excluded_tokens as $token ) {
            $token = preg_replace( '/[^a-z0-9]+/', '', strtolower( $token ) );
            if ( $token !== '' && strpos( $normalized_path, $token ) !== false ) {
                $matches = false;
                break;
            }
        }

        if ( $matches ) {
            return $value;
        }
    }

    return '';
}

function vm_amelia_get_whatsapp_credentials() {
    $access_token = vm_get_defined_constant_value( [
        'VM_WHATSAPP_ACCESS_TOKEN',
        'VM_META_WHATSAPP_ACCESS_TOKEN',
        'AMELIA_WHATSAPP_ACCESS_TOKEN',
    ] );

    $phone_number_id = vm_get_defined_constant_value( [
        'VM_WHATSAPP_PHONE_NUMBER_ID',
        'VM_META_WHATSAPP_PHONE_NUMBER_ID',
        'AMELIA_WHATSAPP_PHONE_NUMBER_ID',
    ] );

    if ( ! $access_token ) {
        $access_token = vm_amelia_find_setting_value( [ 'whatsapp', 'token' ], [ 'verify', 'webhook' ] );
    }

    if ( ! $phone_number_id ) {
        $phone_number_id = vm_amelia_find_setting_value( [ 'whatsapp', 'phonenumberid' ] );
    }

    if ( ! $phone_number_id ) {
        $phone_number_id = vm_amelia_find_setting_value( [ 'whatsapp', 'phone', 'id' ] );
    }

    return [
        'access_token'    => $access_token,
        'phone_number_id' => $phone_number_id,
        'graph_version'   => vm_get_defined_constant_value( [ 'VM_WHATSAPP_GRAPH_VERSION', 'VM_META_GRAPH_VERSION' ], 'v22.0' ),
    ];
}

function vm_amelia_get_notification_property( $notification, $keys, $default = '' ) {
    foreach ( (array) $keys as $key ) {
        if ( is_array( $notification ) && isset( $notification[ $key ] ) && $notification[ $key ] !== '' ) {
            return $notification[ $key ];
        }
    }

    return $default;
}

function vm_amelia_get_whatsapp_notification_config( $booking_data ) {
    $template_name = vm_get_defined_constant_value( [
        'VM_WHATSAPP_TEMPLATE_NAME',
        'VM_AMELIA_WHATSAPP_TEMPLATE_NAME',
        'AMELIA_WHATSAPP_TEMPLATE_NAME',
    ] );

    $language = vm_get_defined_constant_value( [
        'VM_WHATSAPP_TEMPLATE_LANGUAGE',
        'VM_AMELIA_WHATSAPP_TEMPLATE_LANGUAGE',
        'AMELIA_WHATSAPP_TEMPLATE_LANGUAGE',
    ] );

    $subject = vm_get_defined_constant_value( [
        'VM_WHATSAPP_TEMPLATE_HEADER_PLACEHOLDERS',
        'VM_AMELIA_WHATSAPP_TEMPLATE_HEADER_PLACEHOLDERS',
    ] );

    $content = vm_get_defined_constant_value( [
        'VM_WHATSAPP_TEMPLATE_BODY_PLACEHOLDERS',
        'VM_AMELIA_WHATSAPP_TEMPLATE_BODY_PLACEHOLDERS',
    ] );

    $service_id = isset( $booking_data['serviceId'] ) ? (int) $booking_data['serviceId'] : 0;
    $notifications = vm_amelia_api_request( 'GET', '/notifications' );
    $selected = null;
    $templates = [];

    if ( ! empty( $notifications['success'] ) && ! empty( $notifications['data']['data'] ) && is_array( $notifications['data']['data'] ) ) {
        $data = $notifications['data']['data'];
        $templates = ! empty( $data['whatsAppTemplates'] ) && is_array( $data['whatsAppTemplates'] ) ? $data['whatsAppTemplates'] : [];

        if ( ! empty( $data['notifications'] ) && is_array( $data['notifications'] ) ) {
            foreach ( $data['notifications'] as $notification ) {
                if ( ! is_array( $notification ) ) {
                    continue;
                }

                $type = strtolower( (string) vm_amelia_get_notification_property( $notification, [ 'type' ] ) );
                $send_to = strtolower( (string) vm_amelia_get_notification_property( $notification, [ 'sendTo', 'send_to' ] ) );
                $entity = strtolower( (string) vm_amelia_get_notification_property( $notification, [ 'entity' ] ) );
                $status = strtolower( (string) vm_amelia_get_notification_property( $notification, [ 'status' ], 'enabled' ) );
                $name = (string) vm_amelia_get_notification_property( $notification, [ 'name' ] );

                if ( $type !== 'whatsapp' || $send_to !== 'customer' || $entity !== 'appointment' || $status === 'disabled' ) {
                    continue;
                }

                $entity_ids = vm_amelia_get_notification_property( $notification, [ 'entityIds', 'entity_ids' ], [] );
                if ( $service_id && is_array( $entity_ids ) && ! empty( $entity_ids ) && ! in_array( $service_id, array_map( 'intval', $entity_ids ), true ) ) {
                    continue;
                }

                if ( $name === 'customer_appointment_approved' ) {
                    $selected = $notification;
                    break;
                }

                if ( $selected === null ) {
                    $selected = $notification;
                }
            }
        }
    }

    if ( $selected ) {
        $template_name = $template_name ?: vm_amelia_get_notification_property( $selected, [
            'whatsAppTemplate',
            'whatsappTemplate',
            'whats_app_template',
            'template',
        ] );
        $subject = $subject ?: vm_amelia_get_notification_property( $selected, [ 'subject' ] );
        $content = $content ?: vm_amelia_get_notification_property( $selected, [ 'content' ] );
    }

    if ( $template_name && $templates ) {
        foreach ( $templates as $template ) {
            if ( ! is_array( $template ) || empty( $template['name'] ) || $template['name'] !== $template_name ) {
                continue;
            }

            if ( ! empty( $template['language'] ) ) {
                $language = $language ?: $template['language'];
            }
            break;
        }
    }

    if ( ! $language ) {
        $language = vm_amelia_find_setting_value( [ 'whatsapp', 'language' ] );
    }

    return [
        'success'       => (bool) $template_name,
        'template_name' => $template_name,
        'language'      => $language ?: 'es_MX',
        'subject'       => $subject,
        'content'       => $content,
        'source'        => $selected ? 'amelia_notification' : 'constants',
    ];
}

function vm_amelia_get_service_name_for_booking( $service_id ) {
    global $wpdb;

    $service_id = (int) $service_id;
    if ( ! $service_id ) {
        return 'Consulta VirtualMD';
    }

    $table = $wpdb->prefix . 'amelia_services';
    $name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $table WHERE id = %d LIMIT 1", $service_id ) );

    return $name ? sanitize_text_field( $name ) : 'Consulta VirtualMD';
}

function vm_amelia_get_provider_name_for_booking( $provider_id ) {
    global $wpdb;

    $provider_id = (int) $provider_id;
    if ( ! $provider_id ) {
        return '';
    }

    $table = $wpdb->prefix . 'amelia_users';
    $provider = $wpdb->get_row( $wpdb->prepare( "SELECT firstName, lastName FROM $table WHERE id = %d LIMIT 1", $provider_id ), ARRAY_A );

    if ( ! $provider ) {
        return '';
    }

    return trim( sanitize_text_field( $provider['firstName'] ?? '' ) . ' ' . sanitize_text_field( $provider['lastName'] ?? '' ) );
}

function vm_amelia_get_company_name() {
    $settings = vm_amelia_get_settings_array();

    if ( ! empty( $settings['company']['name'] ) ) {
        return sanitize_text_field( $settings['company']['name'] );
    }

    return get_bloginfo( 'name' );
}

function vm_amelia_format_booking_datetime_values( $booking_start ) {
    $timestamp = strtotime( (string) $booking_start );

    if ( ! $timestamp ) {
        return [
            'date'      => '',
            'time'      => '',
            'date_time' => (string) $booking_start,
        ];
    }

    return [
        'date'      => date_i18n( get_option( 'date_format' ), $timestamp ),
        'time'      => date_i18n( get_option( 'time_format' ), $timestamp ),
        'date_time' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ),
    ];
}

function vm_amelia_build_whatsapp_placeholder_values( $booking_data, $form_context_token ) {
    $context = vm_amelia_get_form_context_for_token( $form_context_token );
    $customer = ! empty( $context['customer'] ) && is_array( $context['customer'] ) ? $context['customer'] : vm_amelia_get_submitted_customer_from_booking( $booking_data );
    $first_name = isset( $customer['firstName'] ) ? trim( sanitize_text_field( $customer['firstName'] ) ) : '';
    $last_name  = isset( $customer['lastName'] ) ? trim( sanitize_text_field( $customer['lastName'] ) ) : '';
    $full_name  = trim( $first_name . ' ' . $last_name );
    $phone      = isset( $customer['phone'] ) ? sanitize_text_field( $customer['phone'] ) : '';
    $date_values = vm_amelia_format_booking_datetime_values( $booking_data['bookingStart'] ?? '' );
    $provider_name = vm_amelia_get_provider_name_for_booking( $booking_data['providerId'] ?? 0 );

    return [
        '%customer_first_name%'     => $first_name,
        '%customer_last_name%'      => $last_name,
        '%customer_full_name%'      => $full_name,
        '%customer_name%'           => $full_name,
        '%customer_email%'          => isset( $customer['email'] ) ? sanitize_email( $customer['email'] ) : '',
        '%customer_email_address%'  => isset( $customer['email'] ) ? sanitize_email( $customer['email'] ) : '',
        '%customer_phone%'          => $phone,
        '%customer_phone_number%'   => $phone,
        '%customer_phone_num%'      => $phone,
        '%service_name%'            => vm_amelia_get_service_name_for_booking( $booking_data['serviceId'] ?? 0 ),
        '%employee_full_name%'      => $provider_name,
        '%provider_full_name%'      => $provider_name,
        '%appointment_date%'        => $date_values['date'],
        '%appointment_time%'        => $date_values['time'],
        '%appointment_date_time%'   => $date_values['date_time'],
        '%appointment_start_date%'  => $date_values['date'],
        '%appointment_start_time%'  => $date_values['time'],
        '%company_name%'            => vm_amelia_get_company_name(),
    ];
}

function vm_amelia_render_whatsapp_param_text( $text, $placeholder_values ) {
    $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
    $text = strtr( $text, array_filter( $placeholder_values, static function( $value ) {
        return $value !== '';
    } ) );
    $text = preg_replace( '/\s+/', ' ', $text );

    return trim( $text );
}

function vm_amelia_parse_whatsapp_template_params( $definition, $placeholder_values ) {
    $definition = trim( (string) $definition );
    if ( $definition === '' ) {
        return [];
    }

    $parts = array_map( 'trim', explode( ',', $definition ) );
    $params = [];

    foreach ( $parts as $part ) {
        if ( $part === '' ) {
            continue;
        }

        $text = vm_amelia_render_whatsapp_param_text( $part, $placeholder_values );
        if ( $text !== '' ) {
            $params[] = [
                'type' => 'text',
                'text' => $text,
            ];
        }
    }

    return $params;
}

function vm_amelia_build_manual_whatsapp_components( $notification_config, $placeholder_values ) {
    $components = [];
    $header_params = vm_amelia_parse_whatsapp_template_params( $notification_config['subject'] ?? '', $placeholder_values );
    $body_params = vm_amelia_parse_whatsapp_template_params( $notification_config['content'] ?? '', $placeholder_values );

    if ( $header_params ) {
        $components[] = [
            'type'       => 'header',
            'parameters' => $header_params,
        ];
    }

    if ( $body_params ) {
        $components[] = [
            'type'       => 'body',
            'parameters' => $body_params,
        ];
    }

    return $components;
}

function vm_amelia_send_manual_whatsapp_confirmation( $booking_data, $success_payload, $form_context_token = '' ) {
    $context = vm_amelia_get_form_context_for_token( $form_context_token );
    $customer = ! empty( $context['customer'] ) && is_array( $context['customer'] ) ? $context['customer'] : vm_amelia_get_submitted_customer_from_booking( $booking_data );
    $phone = isset( $customer['phone'] ) ? sanitize_text_field( $customer['phone'] ) : '';
    $phone = vm_amelia_normalize_phone_for_delivery( $phone );
    $to = preg_replace( '/\D+/', '', $phone );

    if ( ! $to ) {
        return [ 'success' => false, 'message' => 'Sin teléfono del formulario para WhatsApp manual' ];
    }

    $credentials = vm_amelia_get_whatsapp_credentials();
    if ( empty( $credentials['access_token'] ) || empty( $credentials['phone_number_id'] ) ) {
        return [
            'success' => false,
            'message' => 'Faltan credenciales WhatsApp Cloud API. Define VM_WHATSAPP_ACCESS_TOKEN y VM_WHATSAPP_PHONE_NUMBER_ID o configura WhatsApp en Amelia.',
        ];
    }

    $notification_config = vm_amelia_get_whatsapp_notification_config( $booking_data );
    if ( empty( $notification_config['success'] ) ) {
        return [
            'success' => false,
            'message' => 'No se encontró plantilla WhatsApp aprobada/configurada para customer_appointment_approved.',
        ];
    }

    $placeholder_values = vm_amelia_build_whatsapp_placeholder_values( $booking_data, $form_context_token );
    $components = vm_amelia_build_manual_whatsapp_components( $notification_config, $placeholder_values );

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
        'type'              => 'template',
        'template'          => [
            'name'     => $notification_config['template_name'],
            'language' => [
                'code' => $notification_config['language'],
            ],
        ],
    ];

    if ( $components ) {
        $payload['template']['components'] = $components;
    }

    $url = sprintf(
        'https://graph.facebook.com/%s/%s/messages',
        rawurlencode( $credentials['graph_version'] ),
        rawurlencode( $credentials['phone_number_id'] )
    );

    $response = wp_remote_request( $url, [
        'method'    => 'POST',
        'timeout'   => 20,
        'headers'   => [
            'Authorization' => 'Bearer ' . $credentials['access_token'],
            'Content-Type'  => 'application/json',
        ],
        'body'      => wp_json_encode( $payload ),
        'sslverify' => true,
    ] );

    if ( is_wp_error( $response ) ) {
        return [
            'success' => false,
            'message' => 'Error enviando WhatsApp manual: ' . $response->get_error_message(),
        ];
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body_res = wp_remote_retrieve_body( $response );
    $decoded = json_decode( $body_res, true );

    if ( $status_code >= 200 && $status_code < 300 && is_array( $decoded ) && empty( $decoded['error'] ) ) {
        return [
            'success' => true,
            'data'    => $decoded,
            'template' => $notification_config['template_name'],
            'language' => $notification_config['language'],
        ];
    }

    return [
        'success' => false,
        'message' => ! empty( $decoded['error']['message'] ) ? $decoded['error']['message'] : 'Meta no aceptó el envío manual de WhatsApp',
        'status'  => $status_code,
        'meta'    => $decoded,
    ];
}

function vm_paypal_async_booking_key( $order_id ) {
    return 'vm_paypal_async_booking_' . sanitize_key( $order_id );
}

function vm_paypal_queue_amelia_booking( $order_id, $booking_data, $paypal_details = [], $doctor_mode = '' ) {
    if ( empty( $order_id ) || empty( $booking_data ) || ! is_array( $booking_data ) ) {
        return [
            'success' => false,
            'message' => 'Datos insuficientes para encolar booking Amelia',
        ];
    }

    $token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'vm_paypal_async_', true ) );
    $job = [
        'status'        => 'queued',
        'token'         => $token,
        'orderId'       => sanitize_text_field( $order_id ),
        'bookingData'   => $booking_data,
        'paypalDetails' => $paypal_details,
        'doctorMode'    => $doctor_mode === 'auto' ? 'auto' : 'manual',
        'attempts'      => 0,
        'createdAt'     => time(),
        'updatedAt'     => time(),
    ];

    set_transient( vm_paypal_async_booking_key( $order_id ), $job, 6 * HOUR_IN_SECONDS );

    if ( ! wp_next_scheduled( 'vmhb_paypal_process_async_booking_event', [ $order_id, $token ] ) ) {
        wp_schedule_single_event( time() + 10, 'vmhb_paypal_process_async_booking_event', [ $order_id, $token ] );
    }

    $async_url = add_query_arg( [
        'action'  => 'vmhb_paypal_process_async_booking',
        'orderID' => $order_id,
        'token'   => $token,
    ], admin_url( 'admin-ajax.php' ) );

    wp_remote_post( $async_url, [
        'timeout'   => 0.01,
        'blocking'  => false,
        'sslverify' => false,
    ] );

    vm_paypal_log( 'Booking Amelia PayPal encolado para procesamiento en fondo.', [
        'orderId' => $order_id,
        'booking' => vm_paypal_booking_summary( $booking_data ),
    ] );

    return [
        'success' => true,
        'async'   => true,
        'status'  => 'queued',
    ];
}

function vm_paypal_process_async_amelia_booking( $order_id, $token = '' ) {
    $order_id = sanitize_text_field( $order_id );
    $token    = sanitize_text_field( $token );

    if ( ! $order_id || ! $token ) {
        return [
            'success' => false,
            'message' => 'orderID/token requeridos para procesar booking async',
        ];
    }

    $transient_key = vm_paypal_async_booking_key( $order_id );
    $job = get_transient( $transient_key );

    if ( ! is_array( $job ) || empty( $job['token'] ) || ! hash_equals( (string) $job['token'], $token ) ) {
        return [
            'success' => false,
            'message' => 'Job async PayPal no encontrado o token inválido',
        ];
    }

    if ( ! empty( $job['status'] ) && $job['status'] === 'completed' ) {
        return [
            'success' => true,
            'status'  => 'completed',
            'result'  => $job['result'] ?? null,
        ];
    }

    if ( ! empty( $job['status'] ) && $job['status'] === 'processing' && ! empty( $job['updatedAt'] ) && ( time() - (int) $job['updatedAt'] ) < 10 * MINUTE_IN_SECONDS ) {
        return [
            'success' => true,
            'status'  => 'processing',
        ];
    }

    $job['status']    = 'processing';
    $job['attempts']  = isset( $job['attempts'] ) ? (int) $job['attempts'] + 1 : 1;
    $job['updatedAt'] = time();
    set_transient( $transient_key, $job, 6 * HOUR_IN_SECONDS );

    $booking_result = vm_paypal_create_amelia_booking(
        $job['bookingData'] ?? [],
        $order_id,
        $job['paypalDetails'] ?? [],
        $job['doctorMode'] ?? 'manual'
    );

    $job['result']    = $booking_result;
    $job['updatedAt'] = time();

    if ( ! empty( $booking_result['success'] ) ) {
        $job['status'] = 'completed';
        set_transient( $transient_key, $job, 6 * HOUR_IN_SECONDS );
        delete_transient( 'vm_paypal_booking_' . $order_id );

        vm_paypal_log( 'Booking Amelia PayPal completado en fondo.', [
            'orderId' => $order_id,
            'booking' => vm_paypal_booking_summary( $job['bookingData'] ?? [] ),
        ] );

        return [
            'success' => true,
            'status'  => 'completed',
            'result'  => $booking_result,
        ];
    }

    $job['status'] = 'failed';
    set_transient( $transient_key, $job, 6 * HOUR_IN_SECONDS );

    vm_paypal_log( 'Booking Amelia PayPal falló en fondo.', [
        'orderId'       => $order_id,
        'booking'       => vm_paypal_booking_summary( $job['bookingData'] ?? [] ),
        'bookingResult' => $booking_result,
    ] );

    return [
        'success' => false,
        'status'  => 'failed',
        'result'  => $booking_result,
    ];
}

function vm_paypal_process_async_booking_handler() {
    $order_id = isset( $_REQUEST['orderID'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderID'] ) ) : '';
    $token    = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
    $result   = vm_paypal_process_async_amelia_booking( $order_id, $token );

    if ( ! empty( $result['success'] ) ) {
        wp_send_json_success( $result );
    }

    wp_send_json_error( $result );
}

function vm_paypal_process_async_booking_event_handler( $order_id, $token ) {
    vm_paypal_process_async_amelia_booking( $order_id, $token );
}

function vm_amelia_is_email_available_for_customer( $email, $customer_id ) {
    global $wpdb;

    $email = sanitize_email( $email );
    if ( ! $email || ! $customer_id ) {
        return false;
    }

    $table = $wpdb->prefix . 'amelia_users';
    $existing_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE email = %s AND id <> %d LIMIT 1",
        $email,
        (int) $customer_id
    ) );

    return ! $existing_id;
}

function vm_amelia_send_customer_update_request( $customer_id, $body ) {
    $url = add_query_arg( [
        'action' => 'wpamelia_api',
        'call'   => '/api/v1/users/customers/' . (int) $customer_id,
    ], admin_url( 'admin-ajax.php' ) );

    $response = wp_remote_request( $url, [
        'method'    => 'POST',
        'timeout'   => 15,
        'headers'   => [
            'Content-Type' => 'application/json',
            'Amelia'       => AMELIA_API_KEY,
        ],
        'body'      => wp_json_encode( $body ),
        'sslverify' => false,
        'cookies'   => $_COOKIE,
    ] );

    if ( is_wp_error( $response ) ) {
        return [
            'success' => false,
            'message' => 'Error actualizando customer creado: ' . $response->get_error_message(),
        ];
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body_res    = wp_remote_retrieve_body( $response );
    $decoded     = json_decode( $body_res, true );

    if ( $status_code >= 200 && $status_code < 300 ) {
        return [
            'success' => true,
            'data'    => $decoded,
        ];
    }

    return [
        'success' => false,
        'message' => is_array( $decoded ) && isset( $decoded['message'] ) ? $decoded['message'] : 'No se pudo actualizar el customer creado',
        'status'  => $status_code,
        'amelia'  => $decoded,
    ];
}

function vm_amelia_update_created_customer_with_form_data( $customer_id, $form_context_token = '' ) {
    if ( ! defined( 'AMELIA_API_KEY' ) || ! AMELIA_API_KEY || ! $customer_id || ! $form_context_token ) {
        return [ 'success' => false, 'message' => 'No hay customer/contexto para actualizar' ];
    }

    $context = vm_amelia_get_form_context_for_token( $form_context_token );
    if ( empty( $context['customer'] ) || ! is_array( $context['customer'] ) ) {
        return [ 'success' => false, 'message' => 'Contexto del formulario no disponible' ];
    }

    $customer = $context['customer'];
    $body = [];

    if ( ! empty( $customer['firstName'] ) ) {
        $body['firstName'] = sanitize_text_field( $customer['firstName'] );
    }

    if ( ! empty( $customer['lastName'] ) ) {
        $body['lastName'] = sanitize_text_field( $customer['lastName'] );
    }

    if ( ! empty( $customer['phone'] ) ) {
        $phone = sanitize_text_field( $customer['phone'] );
        $body['phone'] = vm_amelia_normalize_phone_for_delivery( $phone );

        if ( ! empty( $customer['countryPhoneIso'] ) ) {
            $body['countryPhoneIso'] = strtolower( sanitize_text_field( $customer['countryPhoneIso'] ) );
        }

        $country_phone_iso = vm_amelia_country_iso_for_phone( $body['phone'] );
        if ( $country_phone_iso && empty( $body['countryPhoneIso'] ) ) {
            $body['countryPhoneIso'] = $country_phone_iso;
        }
    }

    if ( ! empty( $customer['email'] ) && vm_amelia_is_email_available_for_customer( $customer['email'], $customer_id ) ) {
        $body['email'] = sanitize_email( $customer['email'] );
    }

    if ( empty( $body ) ) {
        return [ 'success' => false, 'message' => 'No hay datos del formulario para actualizar' ];
    }

    $result = vm_amelia_send_customer_update_request( $customer_id, $body );

    if ( ! empty( $result['success'] ) ) {
        $result['emailUpdated'] = ! empty( $body['email'] );
        return $result;
    }

    if ( ! empty( $body['email'] ) ) {
        $body_without_email = $body;
        unset( $body_without_email['email'] );

        if ( ! empty( $body_without_email ) ) {
            $retry_result = vm_amelia_send_customer_update_request( $customer_id, $body_without_email );
            $retry_result['emailUpdated'] = false;
            $retry_result['emailSkippedAfterFailure'] = true;

            return $retry_result;
        }
    }

    $result['emailUpdated'] = false;

    return $result;
}

/**
 * Forzar que Amelia no reutilice customers existentes. Los datos reales del
 * formulario viajan en contexto de notificación y notas internas.
 */
function vm_paypal_prepare_customer_for_booking( $booking_data, $form_context_token = '' ) {
    if ( empty( $booking_data['bookings'] ) || ! is_array( $booking_data['bookings'] ) ) {
        return [ 'success' => true, 'booking_data' => $booking_data ];
    }

    foreach ( $booking_data['bookings'] as $index => $booking ) {
        if ( empty( $booking['customer'] ) || ! is_array( $booking['customer'] ) ) {
            continue;
        }

        $booking_data['bookings'][ $index ]['customerId'] = null;
        $booking_data['bookings'][ $index ]['customer'] = vm_amelia_unique_customer_for_booking(
            $booking['customer'],
            $form_context_token
        );
    }

    return [ 'success' => true, 'booking_data' => $booking_data ];
}

/**
 * Si el frontend llegó sin providerId en modo automático, resolverlo con disponibilidad actual.
 */
function vm_paypal_ensure_provider_for_booking( $booking_data, $doctor_mode = '' ) {
    if ( ! function_exists( __NAMESPACE__ . '\\vm_amelia_validate_booking_slot' ) ) {
        return [
            'success' => false,
            'message' => 'No se pudo validar disponibilidad: helper no disponible',
        ];
    }

    $result = vm_amelia_validate_booking_slot( $booking_data, $doctor_mode === 'auto' ? 'auto' : 'manual' );

    if ( ! empty( $result['success'] ) ) {
        $next_booking_data = $result['booking_data'];
        $old_provider_id   = isset( $booking_data['providerId'] ) ? (int) $booking_data['providerId'] : 0;
        $new_provider_id   = isset( $next_booking_data['providerId'] ) ? (int) $next_booking_data['providerId'] : 0;

        if ( $doctor_mode === 'auto' && $new_provider_id && $new_provider_id !== $old_provider_id ) {
            $parts = function_exists( __NAMESPACE__ . '\\vm_amelia_get_booking_slot_parts' )
                ? vm_amelia_get_booking_slot_parts( $next_booking_data )
                : [];

            vm_paypal_log( 'Provider asignado automáticamente antes de crear booking en Amelia.', [
                'serviceId'  => isset( $parts['serviceId'] ) ? (int) $parts['serviceId'] : 0,
                'date'       => isset( $parts['date'] ) ? $parts['date'] : '',
                'time'       => isset( $parts['time'] ) ? $parts['time'] : '',
                'providerId' => $new_provider_id,
            ] );
        }
    }

    return $result;
}

/**
 * AJAX: Crear una orden de PayPal.
 *
 * Recibe (POST JSON):
 *   - serviceName:  string  – Nombre del servicio
 *   - servicePrice: float   – Precio en MXN
 *   - bookingData:  object  – Payload de Amelia
 *
 * Retorna:
 *   - orderID: string – ID de la orden creada
 */
function vm_paypal_create_order_handler() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Método no permitido' ] );
        return;
    }

    if ( ! defined( 'PAYPAL_CLIENT_ID' ) || ! PAYPAL_CLIENT_ID ||
         ! defined( 'PAYPAL_CLIENT_SECRET' ) || ! PAYPAL_CLIENT_SECRET ) {
        wp_send_json_error( [ 'message' => 'Credenciales de PayPal no configuradas en wp-config.php' ] );
        return;
    }

    $raw  = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );

    if ( empty( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos' ] );
        return;
    }

    $service_name  = isset( $data['serviceName'] )  ? sanitize_text_field( $data['serviceName'] )  : '';
    $service_price = isset( $data['servicePrice'] ) ? floatval( $data['servicePrice'] )            : 0;
    $booking_data  = isset( $data['bookingData'] ) && is_array( $data['bookingData'] ) ? $data['bookingData'] : [];
    $doctor_mode   = isset( $data['doctorMode'] ) && $data['doctorMode'] === 'auto' ? 'auto' : 'manual';

    if ( empty( $service_name ) || $service_price <= 0 ) {
        wp_send_json_error( [ 'message' => 'Servicio o precio inválido' ] );
        return;
    }

    if ( empty( $booking_data ) ) {
        wp_send_json_error( [ 'message' => 'Datos de booking incompletos. No se creó la orden PayPal.' ] );
        return;
    }

    $availability_result = vm_paypal_ensure_provider_for_booking( $booking_data, $doctor_mode );
    if ( empty( $availability_result['success'] ) ) {
        wp_send_json_error( [
            'message' => isset( $availability_result['message'] ) ? $availability_result['message'] : 'El horario seleccionado ya no está disponible',
        ] );
        return;
    }
    $booking_data = $availability_result['booking_data'];

    // Obtener access token
    $token = vm_paypal_get_access_token();
    if ( is_wp_error( $token ) ) {
        wp_send_json_error( [ 'message' => 'Error de autenticación PayPal: ' . $token->get_error_message() ] );
        return;
    }

    // Crear orden
    $order_payload = [
        'intent'         => 'CAPTURE',
        'purchase_units' => [ [
            'description' => $service_name . ' - VirtualMD',
            'amount'      => [
                'currency_code' => 'MXN',
                'value'         => number_format( $service_price, 2, '.', '' ),
            ],
        ] ],
    ];

    $response = wp_remote_post( vm_paypal_api_base() . '/v2/checkout/orders', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( $order_payload ),
        'timeout' => 15,
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [ 'message' => 'Error al crear orden PayPal: ' . $response->get_error_message() ] );
        return;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $body['id'] ) ) {
        wp_send_json_error( [
            'message' => 'PayPal no devolvió un ID de orden',
            'detail'  => $body,
        ] );
        return;
    }

    // Guardar booking data en transient para captura posterior
    set_transient(
        'vm_paypal_booking_' . $body['id'],
        [
            'bookingData' => $booking_data,
            'doctorMode'  => $doctor_mode,
        ],
        2 * HOUR_IN_SECONDS
    );

    wp_send_json_success( [ 'orderID' => $body['id'] ] );
}

/**
 * AJAX: Capturar una orden de PayPal (después de que el usuario aprueba).
 *
 * Recibe (POST JSON):
 *   - orderID: string
 *   - bookingData: object – respaldo del payload de Amelia si el transient expiró
 *
 * Retorna:
 *   - status:  string – COMPLETED / etc.
 *   - details: object – Respuesta completa de PayPal
 */
function vm_paypal_capture_order_handler() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Método no permitido' ] );
        return;
    }

    if ( ! defined( 'PAYPAL_CLIENT_ID' ) || ! PAYPAL_CLIENT_ID ||
         ! defined( 'PAYPAL_CLIENT_SECRET' ) || ! PAYPAL_CLIENT_SECRET ) {
        wp_send_json_error( [ 'message' => 'Credenciales de PayPal no configuradas en wp-config.php' ] );
        return;
    }

    $raw  = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );

    if ( empty( $data ) || ! is_array( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos' ] );
        return;
    }

    $order_id       = isset( $data['orderID'] ) ? sanitize_text_field( $data['orderID'] ) : '';
    $fallback_data  = isset( $data['bookingData'] ) && is_array( $data['bookingData'] ) ? $data['bookingData'] : [];
    $doctor_mode    = isset( $data['doctorMode'] ) && $data['doctorMode'] === 'auto' ? 'auto' : 'manual';

    if ( empty( $order_id ) ) {
        wp_send_json_error( [ 'message' => 'orderID requerido' ] );
        return;
    }

    $token = vm_paypal_get_access_token();
    if ( is_wp_error( $token ) ) {
        wp_send_json_error( [ 'message' => 'Error de autenticación PayPal: ' . $token->get_error_message() ] );
        return;
    }

    // Capturar la orden
    $response = wp_remote_post( vm_paypal_api_base() . '/v2/checkout/orders/' . $order_id . '/capture', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => '{}',
        'timeout' => 15,
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [ 'message' => 'Error al capturar pago: ' . $response->get_error_message() ] );
        return;
    }

    $body   = json_decode( wp_remote_retrieve_body( $response ), true );
    $status = isset( $body['status'] ) ? $body['status'] : 'UNKNOWN';

    if ( $status === 'COMPLETED' ) {
        // Recuperar booking data y crear cita en Amelia
        $transient_key = 'vm_paypal_booking_' . $order_id;
        $stored_data   = get_transient( $transient_key );
        $booking_data  = [];

        if ( is_array( $stored_data ) && isset( $stored_data['bookingData'] ) && is_array( $stored_data['bookingData'] ) ) {
            $booking_data = $stored_data['bookingData'];
            $doctor_mode  = isset( $stored_data['doctorMode'] ) && $stored_data['doctorMode'] === 'auto' ? 'auto' : $doctor_mode;
        } elseif ( is_array( $stored_data ) ) {
            // Compatibilidad con órdenes creadas antes de guardar metadata.
            $booking_data = $stored_data;
        }

        if ( empty( $booking_data ) && ! empty( $fallback_data ) ) {
            $booking_data = $fallback_data;
        }

        if ( ! empty( $booking_data ) && is_array( $booking_data ) ) {
            $queue_result = vm_paypal_queue_amelia_booking( $order_id, $booking_data, $body, $doctor_mode );

            if ( empty( $queue_result['success'] ) ) {
                vm_paypal_log( 'No se pudo encolar booking Amelia después de pago PayPal completado.', [
                    'orderId' => $order_id,
                    'booking' => vm_paypal_booking_summary( $booking_data ),
                    'result'  => $queue_result,
                ] );

                wp_send_json_error( [
                    'message'           => isset( $queue_result['message'] ) ? $queue_result['message'] : 'El pago se completó, pero no se pudo encolar la consulta.',
                    'status'            => 'COMPLETED',
                    'payment_completed' => true,
                    'details'           => $body,
                    'booking_result'    => $queue_result,
                ] );
            }

            wp_send_json_success( [
                'status'              => 'COMPLETED',
                'details'             => $body,
                'booking_async'       => true,
                'booking_result'      => $queue_result,
                'booking_status_note' => 'La consulta se está registrando en segundo plano.',
            ] );
        } else {
            vm_paypal_log( 'Pago PayPal completado sin payload de booking disponible.', [
                'orderId' => $order_id,
            ] );

            wp_send_json_error( [
                'message'           => 'El pago se completó, pero no se encontraron los datos de booking para registrar la consulta.',
                'status'            => 'COMPLETED',
                'payment_completed' => true,
                'details'           => $body,
            ] );
        }
    } else {
        wp_send_json_error( [
            'message' => 'El pago no se completó. Estado: ' . $status,
            'details' => $body,
        ] );
    }
}

/**
 * Crear la cita en Amelia después de un pago exitoso con PayPal.
 */
function vm_paypal_create_amelia_booking( $booking_data, $order_id, $paypal_details = [], $doctor_mode = '' ) {
    if ( ! defined( 'AMELIA_API_KEY' ) || ! AMELIA_API_KEY ) {
        return [ 'success' => false, 'message' => 'AMELIA_API_KEY no definida' ];
    }

    if ( empty( $booking_data ) || ! is_array( $booking_data ) ) {
        return [ 'success' => false, 'message' => 'Payload de Amelia inválido' ];
    }

    $form_context_token = vm_amelia_store_form_context_for_booking( $booking_data );

    $provider_result = vm_paypal_ensure_provider_for_booking( $booking_data, $doctor_mode );
    if ( empty( $provider_result['success'] ) ) {
        return [
            'success' => false,
            'message' => $provider_result['message'],
            'booking' => vm_paypal_booking_summary( $booking_data ),
        ];
    }

    $booking_data = $provider_result['booking_data'];
    $booking_data = vm_amelia_append_form_customer_to_internal_notes( $booking_data );

    $customer_result = vm_paypal_prepare_customer_for_booking( $booking_data, $form_context_token );
    if ( empty( $customer_result['success'] ) ) {
        return [
            'success' => false,
            'message' => $customer_result['message'],
            'booking' => vm_paypal_booking_summary( $booking_data ),
        ];
    }

    $booking_data = $customer_result['booking_data'];
    vm_amelia_update_form_context_technical_customer( $form_context_token, $booking_data );

    $capture = $paypal_details['purchase_units'][0]['payments']['captures'][0] ?? [];
    $amount  = $capture['amount'] ?? [];
    $paypal_note = 'Pago PayPal: ' . $order_id;

    if ( ! empty( $capture['id'] ) ) {
        $paypal_note .= ' | Captura: ' . $capture['id'];
    }

    if ( ! empty( $amount['value'] ) && ! empty( $amount['currency_code'] ) ) {
        $paypal_note .= ' | Monto: $' . $amount['value'] . ' ' . strtoupper( $amount['currency_code'] );
    }

    if ( ! empty( $booking_data['internalNotes'] ) ) {
        $booking_data['internalNotes'] .= "\n" . $paypal_note;
    } else {
        $booking_data['internalNotes'] = $paypal_note;
    }

    if ( empty( $booking_data['payment'] ) || ! is_array( $booking_data['payment'] ) ) {
        $booking_data['payment'] = [];
    }

    $booking_data['payment']['gateway']  = 'onSite';
    $booking_data['payment']['currency'] = $booking_data['payment']['currency'] ?? 'MXN';
    $booking_data['payment']['data']     = $booking_data['payment']['data'] ?? [];
    $booking_data['notifyParticipants']  = 1;
    $booking_data['runInstantPostBookingActions'] = false;

    $url = add_query_arg( [
        'action' => 'wpamelia_api',
        'call'   => '/api/v1/bookings',
    ], admin_url( 'admin-ajax.php' ) );

    $headers = [
        'Content-Type' => 'application/json',
        'Amelia'       => AMELIA_API_KEY,
    ];

    if ( $form_context_token ) {
        $headers['X-VM-Amelia-Form-Token'] = $form_context_token;
    }

    $response = wp_remote_request( $url, [
        'method'    => 'POST',
        'timeout'   => 15,
        'headers'   => $headers,
        'body'      => wp_json_encode( $booking_data ),
        'sslverify' => false,
        'cookies'   => $_COOKIE,
    ] );

    if ( is_wp_error( $response ) ) {
        return [
            'success' => false,
            'message' => 'Error de conexión interna: ' . $response->get_error_message(),
        ];
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body_res    = wp_remote_retrieve_body( $response );

    if ( trim( $body_res ) === '0' || trim( $body_res ) === '-1' ) {
        return [
            'success' => false,
            'message' => 'La acción wpamelia_api no está registrada. Verifica que Amelia esté activo con licencia Elite.',
            'status'  => $status_code,
        ];
    }

    $decoded = json_decode( $body_res, true );

    // --- LOGICA DE REINTENTO DE EMERGENCIA ---
    // Si Amelia lanza el error de colisión de email/teléfono (ej. el correo pertenece a un usuario WP y falló la validación)
    // intentamos ofuscar la identidad como último recurso para no perder la cita ya pagada.
    if ( isset( $decoded['data']['message'] ) && strpos( $decoded['data']['message'], 'different email or phone number' ) !== false ) {
        $token = substr( md5( uniqid( 'vm_retry_', true ) ), 0, 10 );

        foreach ( $booking_data['bookings'] as $index => $booking ) {
            $booking_data['bookings'][ $index ]['customerId'] = null;
            if ( isset( $booking_data['bookings'][ $index ]['customer'] ) ) {
                $booking_data['bookings'][ $index ]['customer']['id'] = null;
                $booking_data['bookings'][ $index ]['customer']['email'] = 'amelia-placeholder+vm' . $token . '@virtualmd.mx';
                // Generar un número de 10 dígitos aleatorio usando el token
                $booking_data['bookings'][ $index ]['customer']['phone'] = '999' . substr( str_pad( (string) hexdec( substr( $token, 0, 7 ) ), 7, '0', STR_PAD_LEFT ), -7 );
                $last_name = $booking_data['bookings'][ $index ]['customer']['lastName'] ?? '';
                $booking_data['bookings'][ $index ]['customer']['lastName'] = trim( $last_name . ' VM-' . strtoupper( substr( $token, 0, 8 ) ) );
            }
        }

        vm_amelia_update_form_context_technical_customer( $form_context_token, $booking_data );

        vm_paypal_log( 'Amelia rechazó por colisión de email. Reintentando con identidad ofuscada.', [
            'newPayload' => $booking_data
        ] );

        $response = wp_remote_request( $url, [
            'method'    => 'POST',
            'timeout'   => 15,
            'headers'   => $headers,
            'body'      => wp_json_encode( $booking_data ),
            'sslverify' => false,
            'cookies'   => $_COOKIE,
        ] );

        if ( ! is_wp_error( $response ) ) {
            $status_code = wp_remote_retrieve_response_code( $response );
            $body_res    = wp_remote_retrieve_body( $response );
            $decoded     = json_decode( $body_res, true );
        }
    }
    // --- FIN LOGICA DE REINTENTO ---

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return [
            'success' => false,
            'message' => 'Respuesta de Amelia no es JSON válido. HTTP ' . $status_code,
            'raw'     => substr( $body_res, 0, 300 ),
        ];
    }

    if ( isset( $decoded['message'] ) && $status_code >= 200 && $status_code < 300 ) {
        $success_payload = vm_amelia_extract_success_payload_from_booking_response( $decoded );
        $post_booking_actions = null;
        $customer_update = null;
        $manual_whatsapp = null;

        if ( ! empty( $success_payload['success'] ) ) {
            $customer_update = vm_amelia_update_created_customer_with_form_data(
                $success_payload['payload']['customerId'],
                $form_context_token
            );

            if ( empty( $customer_update['success'] ) ) {
                vm_paypal_log( 'Booking creado, pero no se pudo actualizar el customer tecnico antes de notificar.', [
                    'bookingId' => $success_payload['booking_id'],
                    'result'    => $customer_update,
                ] );
            }

            $post_booking_actions = vm_amelia_run_booking_success_actions(
                $success_payload['booking_id'],
                $success_payload['payload'],
                $form_context_token
            );

            if ( empty( $post_booking_actions['success'] ) ) {
                vm_paypal_log( 'Booking creado, pero fallaron acciones post-booking de Amelia.', [
                    'bookingId' => $success_payload['booking_id'],
                    'result'    => $post_booking_actions,
                ] );
            }

            $manual_whatsapp = vm_amelia_send_manual_whatsapp_confirmation(
                $booking_data,
                $success_payload,
                $form_context_token
            );

            if ( empty( $manual_whatsapp['success'] ) ) {
                vm_paypal_log( 'Booking creado, pero no se pudo enviar WhatsApp manual.', [
                    'bookingId' => $success_payload['booking_id'],
                    'result'    => $manual_whatsapp,
                ] );
            } else {
                vm_paypal_log( 'WhatsApp manual enviado para booking Amelia.', [
                    'bookingId' => $success_payload['booking_id'],
                    'template'  => $manual_whatsapp['template'] ?? '',
                ] );
            }
        } else {
            vm_paypal_log( 'Booking creado, pero no se pudo construir payload post-booking.', [
                'result' => $success_payload,
            ] );
        }

        return [
            'success'              => true,
            'data'                 => $decoded,
            'customer_update'      => $customer_update,
            'manual_whatsapp'      => $manual_whatsapp,
            'post_booking_actions' => $post_booking_actions,
        ];
    }

    // Invalidar caché de disponibilidad incluso si no fue 100% exitoso
    // pero la cita fue creada (status 2xx)
    if ( $status_code >= 200 && $status_code < 300 ) {
        vm_amelia_invalidate_availability_cache();
    }

    return [
        'success' => false,
        'message' => isset( $decoded['message'] ) ? $decoded['message'] : 'Error al crear la cita',
        'status'  => $status_code,
        'amelia'  => $decoded,
    ];
}
