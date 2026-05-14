<?php
namespace VirtualMD\HeroBooking;

/**
 * Package catalog and purchase flow for the client hero widget.
 *
 * The widget reads Amelia package data from the local Amelia tables for fast
 * catalog display, then creates the purchased package through Amelia's API
 * after Stripe or PayPal confirms the payment.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function vm_package_log( $message, $context = [] ) {
    if ( ! empty( $context ) ) {
        $message .= ' ' . wp_json_encode( $context );
    }

    error_log( '[VM Packages] ' . $message );
}

function vm_package_table_exists( $table ) {
    global $wpdb;

    return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

function vm_package_price_from_row( $row ) {
    $price = isset( $row['price'] ) ? (float) $row['price'] : 0.0;

    if ( $price <= 0 && isset( $row['calculatedPrice'] ) ) {
        $price = (float) $row['calculatedPrice'];
    }

    return max( 0, $price );
}

function vm_package_format_validity_label( $package ) {
    $end_date = isset( $package['endDate'] ) ? trim( (string) $package['endDate'] ) : '';
    if ( $end_date !== '' && strtolower( $end_date ) !== 'null' ) {
        $timestamp = strtotime( $end_date );
        if ( $timestamp ) {
            return 'Vigente hasta ' . date_i18n( get_option( 'date_format' ), $timestamp );
        }
    }

    $duration_type  = isset( $package['durationType'] ) ? sanitize_text_field( $package['durationType'] ) : '';
    $duration_count = isset( $package['durationCount'] ) ? (int) $package['durationCount'] : 0;

    if ( $duration_type && $duration_count > 0 ) {
        $labels = [
            'day'   => $duration_count === 1 ? 'día' : 'días',
            'days'  => $duration_count === 1 ? 'día' : 'días',
            'week'  => $duration_count === 1 ? 'semana' : 'semanas',
            'weeks' => $duration_count === 1 ? 'semana' : 'semanas',
            'month' => $duration_count === 1 ? 'mes' : 'meses',
            'months'=> $duration_count === 1 ? 'mes' : 'meses',
            'year'  => $duration_count === 1 ? 'año' : 'años',
            'years' => $duration_count === 1 ? 'año' : 'años',
        ];

        return 'Vigencia: ' . $duration_count . ' ' . ( $labels[ $duration_type ] ?? $duration_type );
    }

    return 'Vigencia según configuración de Amelia';
}

function vm_package_public_package_from_row( $package, $services = [] ) {
    $price = vm_package_price_from_row( $package );
    $included = [];

    foreach ( $services as $service ) {
        $quantity = isset( $service['quantity'] ) ? max( 1, (int) $service['quantity'] ) : 1;
        $name     = isset( $service['name'] ) ? sanitize_text_field( $service['name'] ) : 'Servicio';
        $included[] = $quantity . ' x ' . $name;
    }

    return [
        'id'            => isset( $package['id'] ) ? (int) $package['id'] : 0,
        'name'          => isset( $package['name'] ) ? sanitize_text_field( $package['name'] ) : '',
        'description'   => isset( $package['description'] ) ? wp_strip_all_tags( (string) $package['description'] ) : '',
        'color'         => isset( $package['color'] ) ? sanitize_hex_color( $package['color'] ) : '',
        'price'         => $price,
        'displayPrice'  => '$' . number_format_i18n( $price, 2 ) . ' MXN',
        'image'         => isset( $package['pictureFullPath'] ) ? esc_url_raw( $package['pictureFullPath'] ) : '',
        'thumb'         => isset( $package['pictureThumbPath'] ) ? esc_url_raw( $package['pictureThumbPath'] ) : '',
        'validityLabel' => vm_package_format_validity_label( $package ),
        'services'      => array_values( $services ),
        'includedLabel' => implode( ' · ', $included ),
    ];
}

function vm_package_get_catalog( $package_id = 0 ) {
    global $wpdb;

    $packages_table = $wpdb->prefix . 'amelia_packages';
    $pts_table      = $wpdb->prefix . 'amelia_packages_to_services';
    $services_table = $wpdb->prefix . 'amelia_services';
    $cats_table     = $wpdb->prefix . 'amelia_categories';
    $providers_table = $wpdb->prefix . 'amelia_packages_services_to_providers';

    foreach ( [ $packages_table, $pts_table, $services_table, $cats_table, $providers_table ] as $table ) {
        if ( ! vm_package_table_exists( $table ) ) {
            return [];
        }
    }

    $where = "WHERE p.status = 'visible'";
    $params = [];

    if ( $package_id ) {
        $where .= ' AND p.id = %d';
        $params[] = (int) $package_id;
    }

    $sql = "
        SELECT
            p.id,
            p.name,
            p.description,
            p.color,
            p.price,
            p.calculatedPrice,
            p.pictureFullPath,
            p.pictureThumbPath,
            p.position,
            p.endDate,
            p.durationType,
            p.durationCount
        FROM $packages_table p
        $where
        ORDER BY p.position ASC, p.name ASC
    ";

    $packages = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

    if ( ! $packages ) {
        return [];
    }

    $packages = array_values( array_filter( $packages, static function( $package ) {
        return vm_package_price_from_row( $package ) > 0;
    } ) );

    if ( ! $packages ) {
        return [];
    }

    $package_ids = array_map( 'intval', wp_list_pluck( $packages, 'id' ) );
    $placeholders = implode( ',', array_fill( 0, count( $package_ids ), '%d' ) );

    $service_rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT
                pts.id AS packageServiceId,
                pts.packageId,
                pts.serviceId,
                pts.quantity,
                pts.minimumScheduled,
                pts.maximumScheduled,
                pts.allowProviderSelection,
                pts.position,
                s.name AS serviceName,
                s.duration,
                c.name AS categoryName
            FROM $pts_table pts
            INNER JOIN $services_table s ON s.id = pts.serviceId
            LEFT JOIN $cats_table c ON c.id = s.categoryId
            WHERE pts.packageId IN ($placeholders)
            ORDER BY pts.packageId ASC, pts.position ASC, s.name ASC
            ",
            $package_ids
        ),
        ARRAY_A
    );

    $service_ids = [];
    $services_by_package = [];

    foreach ( (array) $service_rows as $row ) {
        $package_service_id = isset( $row['packageServiceId'] ) ? (int) $row['packageServiceId'] : 0;
        if ( $package_service_id ) {
            $service_ids[] = $package_service_id;
        }

        $services_by_package[ (int) $row['packageId'] ][] = [
            'packageServiceId'       => $package_service_id,
            'serviceId'              => isset( $row['serviceId'] ) ? (int) $row['serviceId'] : 0,
            'name'                   => isset( $row['serviceName'] ) ? sanitize_text_field( $row['serviceName'] ) : '',
            'category'               => isset( $row['categoryName'] ) ? sanitize_text_field( $row['categoryName'] ) : '',
            'quantity'               => isset( $row['quantity'] ) ? (int) $row['quantity'] : 1,
            'duration'               => isset( $row['duration'] ) ? (int) $row['duration'] : 0,
            'minimumScheduled'       => isset( $row['minimumScheduled'] ) ? (int) $row['minimumScheduled'] : 0,
            'maximumScheduled'       => isset( $row['maximumScheduled'] ) ? (int) $row['maximumScheduled'] : 0,
            'allowProviderSelection' => ! empty( $row['allowProviderSelection'] ),
            'providerIds'            => [],
        ];
    }

    if ( $service_ids ) {
        $service_ids = array_values( array_unique( array_map( 'intval', $service_ids ) ) );
        $provider_placeholders = implode( ',', array_fill( 0, count( $service_ids ), '%d' ) );
        $provider_rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT packageServiceId, userId
                FROM $providers_table
                WHERE packageServiceId IN ($provider_placeholders)
                ",
                $service_ids
            ),
            ARRAY_A
        );

        $providers_by_package_service = [];
        foreach ( (array) $provider_rows as $provider_row ) {
            $providers_by_package_service[ (int) $provider_row['packageServiceId'] ][] = (int) $provider_row['userId'];
        }

        foreach ( $services_by_package as $pkg_id => $items ) {
            foreach ( $items as $index => $service ) {
                $services_by_package[ $pkg_id ][ $index ]['providerIds'] = isset( $providers_by_package_service[ $service['packageServiceId'] ] )
                    ? array_values( array_unique( $providers_by_package_service[ $service['packageServiceId'] ] ) )
                    : [];
            }
        }
    }

    $result = [];
    foreach ( $packages as $package ) {
        $pkg_id = (int) $package['id'];
        $result[] = vm_package_public_package_from_row( $package, $services_by_package[ $pkg_id ] ?? [] );
    }

    return $result;
}

function vm_amelia_get_packages_handler() {
    $cache_key = 'vm_amelia_packages_catalog_v1';
    $cached = get_transient( $cache_key );

    if ( is_array( $cached ) ) {
        wp_send_json_success( [ 'packages' => $cached ] );
    }

    $packages = vm_package_get_catalog();
    set_transient( $cache_key, $packages, 5 * MINUTE_IN_SECONDS );

    wp_send_json_success( [ 'packages' => $packages ] );
}

function vm_package_get_catalog_item( $package_id ) {
    $packages = vm_package_get_catalog( (int) $package_id );

    return $packages ? $packages[0] : null;
}

function vm_package_extract_customer_data( $data ) {
    $customer = isset( $data['customerData'] ) && is_array( $data['customerData'] ) ? $data['customerData'] : $data;
    $name = isset( $customer['name'] ) ? trim( sanitize_text_field( $customer['name'] ) ) : '';
    $first_name = isset( $customer['firstName'] ) ? trim( sanitize_text_field( $customer['firstName'] ) ) : '';
    $last_name  = isset( $customer['lastName'] ) ? trim( sanitize_text_field( $customer['lastName'] ) ) : '';

    if ( ! $first_name && $name ) {
        $parts = preg_split( '/\s+/', $name );
        $first_name = array_shift( $parts );
        $last_name = trim( implode( ' ', $parts ) );
    }

    return [
        'firstName'       => $first_name ?: 'Paciente',
        'lastName'        => $last_name ?: ' ',
        'fullName'        => $name ?: trim( $first_name . ' ' . $last_name ),
        'email'           => isset( $customer['email'] ) ? sanitize_email( $customer['email'] ) : '',
        'phone'           => isset( $customer['phone'] ) ? sanitize_text_field( $customer['phone'] ) : '',
        'countryPhoneIso' => isset( $customer['countryPhoneIso'] ) ? strtolower( sanitize_text_field( $customer['countryPhoneIso'] ) ) : '',
        'city'            => isset( $customer['city'] ) ? sanitize_text_field( $customer['city'] ) : '',
        'message'         => isset( $customer['message'] ) ? sanitize_textarea_field( $customer['message'] ) : '',
    ];
}

function vm_package_find_customer_by_email( $email ) {
    global $wpdb;

    $email = sanitize_email( $email );
    if ( ! $email ) {
        return 0;
    }

    $table = $wpdb->prefix . 'amelia_users';
    if ( ! vm_package_table_exists( $table ) ) {
        return 0;
    }

    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE email = %s AND type = 'customer' ORDER BY id DESC LIMIT 1",
        $email
    ) );
}

function vm_package_pick_id_from_paths( $data, $paths ) {
    foreach ( $paths as $path ) {
        $value = $data;
        foreach ( $path as $key ) {
            if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
                $value = $value[ $key ];
                continue;
            }
            $value = null;
            break;
        }

        if ( is_numeric( $value ) && (int) $value > 0 ) {
            return (int) $value;
        }
    }

    return 0;
}

function vm_package_create_customer_via_api( $customer ) {
    $payload = [
        'firstName' => $customer['firstName'],
        'lastName'  => $customer['lastName'],
        'email'     => $customer['email'],
        'phone'     => $customer['phone'],
    ];

    if ( ! empty( $customer['countryPhoneIso'] ) ) {
        $payload['countryPhoneIso'] = $customer['countryPhoneIso'];
    }

    $result = vm_amelia_api_request( 'POST', '/users/customers', $payload );

    if ( empty( $result['success'] ) ) {
        return [
            'success' => false,
            'message' => isset( $result['message'] ) ? $result['message'] : 'No se pudo crear el cliente en Amelia',
            'amelia'  => $result,
        ];
    }

    $customer_id = vm_package_pick_id_from_paths( $result['data'], [
        [ 'data', 'user', 'id' ],
        [ 'data', 'customer', 'id' ],
        [ 'data', 'id' ],
        [ 'user', 'id' ],
        [ 'customer', 'id' ],
        [ 'id' ],
    ] );

    if ( ! $customer_id ) {
        $customer_id = vm_package_find_customer_by_email( $customer['email'] );
    }

    if ( ! $customer_id ) {
        return [
            'success' => false,
            'message' => 'Amelia creó el cliente, pero no devolvió customerId',
            'amelia'  => $result['data'],
        ];
    }

    return [ 'success' => true, 'customerId' => $customer_id, 'created' => true ];
}

function vm_package_get_or_create_customer( $customer ) {
    if ( empty( $customer['email'] ) || ! is_email( $customer['email'] ) ) {
        return [ 'success' => false, 'message' => 'Correo del cliente inválido' ];
    }

    $existing_id = vm_package_find_customer_by_email( $customer['email'] );
    if ( $existing_id ) {
        return [ 'success' => true, 'customerId' => $existing_id, 'created' => false ];
    }

    return vm_package_create_customer_via_api( $customer );
}

function vm_package_mark_payment_paid( $payment_id, $amount, $gateway, $reference ) {
    $payment_id = (int) $payment_id;
    if ( ! $payment_id ) {
        return [ 'success' => false, 'message' => 'Sin paymentId' ];
    }

    $payload = [
        'status'           => 'paid',
        'gateway'          => $gateway,
        'amount'           => (float) $amount,
        'entity'           => 'package',
        'dateTime'         => current_time( 'mysql' ),
        'actionsCompleted' => 1,
        'transactionId'    => sanitize_text_field( $reference ),
        'data'             => [
            'source' => 'virtualmd_package_widget',
        ],
    ];

    $result = vm_amelia_api_request( 'POST', '/payments/' . $payment_id, $payload );

    if ( empty( $result['success'] ) ) {
        vm_package_log( 'No se pudo marcar el pago del paquete como pagado.', [
            'paymentId' => $payment_id,
            'gateway'   => $gateway,
            'result'    => $result,
        ] );
    }

    return $result;
}

function vm_package_create_amelia_purchase( $package, $customer, $gateway, $reference ) {
    if ( ! defined( 'AMELIA_API_KEY' ) || ! AMELIA_API_KEY ) {
        return [ 'success' => false, 'message' => 'AMELIA_API_KEY no definida' ];
    }

    if ( empty( $package['id'] ) || empty( $package['price'] ) ) {
        return [ 'success' => false, 'message' => 'Paquete inválido' ];
    }

    $customer_result = vm_package_get_or_create_customer( $customer );
    if ( empty( $customer_result['success'] ) ) {
        return $customer_result;
    }

    $payload = [
        'packageId'  => (int) $package['id'],
        'customerId' => (int) $customer_result['customerId'],
        'notify'     => true,
    ];

    $purchase_result = vm_amelia_api_request( 'POST', '/packages/customers', $payload );

    if ( empty( $purchase_result['success'] ) ) {
        return [
            'success' => false,
            'message' => isset( $purchase_result['message'] ) ? $purchase_result['message'] : 'No se pudo crear la compra del paquete en Amelia',
            'amelia'  => $purchase_result,
        ];
    }

    $response_data = $purchase_result['data'];
    $package_customer_id = vm_package_pick_id_from_paths( $response_data, [
        [ 'data', 'packageCustomer', 'id' ],
        [ 'data', 'packageCustomerId' ],
        [ 'data', 'id' ],
        [ 'packageCustomer', 'id' ],
        [ 'packageCustomerId' ],
        [ 'id' ],
    ] );
    $payment_id = vm_package_pick_id_from_paths( $response_data, [
        [ 'data', 'payment', 'id' ],
        [ 'data', 'paymentId' ],
        [ 'payment', 'id' ],
        [ 'paymentId' ],
    ] );

    $payment_update = null;
    if ( $payment_id ) {
        $payment_update = vm_package_mark_payment_paid( $payment_id, $package['price'], $gateway, $reference );
    }

    return [
        'success'           => true,
        'customerId'        => (int) $customer_result['customerId'],
        'customerCreated'   => ! empty( $customer_result['created'] ),
        'packageCustomerId' => $package_customer_id,
        'paymentId'         => $payment_id,
        'paymentUpdate'     => $payment_update,
        'amelia'            => $response_data,
    ];
}

function vm_package_transient_key( $prefix, $id ) {
    return $prefix . '_' . sanitize_key( $id );
}

function vm_package_build_checkout_context_from_request( $data ) {
    $package_id = isset( $data['packageId'] ) ? (int) $data['packageId'] : 0;
    $package = vm_package_get_catalog_item( $package_id );

    if ( ! $package || empty( $package['price'] ) || $package['price'] <= 0 ) {
        return [ 'success' => false, 'message' => 'Paquete no disponible para compra' ];
    }

    $customer = vm_package_extract_customer_data( $data );
    if ( empty( $customer['email'] ) || ! is_email( $customer['email'] ) ) {
        return [ 'success' => false, 'message' => 'Correo del cliente inválido' ];
    }

    return [
        'success'  => true,
        'package'  => $package,
        'customer' => $customer,
    ];
}

function vm_stripe_create_package_session_handler() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Método no permitido' ] );
        return;
    }

    if ( ! defined( 'STRIPE_SECRET_KEY' ) || ! STRIPE_SECRET_KEY ) {
        wp_send_json_error( [ 'message' => 'STRIPE_SECRET_KEY no está definida en wp-config.php' ] );
        return;
    }

    $vendor_path = VMHB_PLUGIN_DIR . 'stripe/vendor/autoload.php';
    if ( ! file_exists( $vendor_path ) ) {
        wp_send_json_error( [ 'message' => 'Librería Stripe no instalada en el plugin.' ] );
        return;
    }
    require_once $vendor_path;

    $raw_body = file_get_contents( 'php://input' );
    $data = json_decode( $raw_body, true );
    if ( empty( $data ) || ! is_array( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos' ] );
        return;
    }

    $context = vm_package_build_checkout_context_from_request( $data );
    if ( empty( $context['success'] ) ) {
        wp_send_json_error( [ 'message' => $context['message'] ] );
        return;
    }

    try {
        $stripe = new \Stripe\StripeClient( STRIPE_SECRET_KEY );
        $package = $context['package'];
        $customer = $context['customer'];
        $amount_cents = (int) round( $package['price'] * 100 );
        $page_url = isset( $data['pageUrl'] ) ? esc_url_raw( $data['pageUrl'] ) : home_url( '/' );
        $page_url = strtok( $page_url, '?' );
        $return_url = $page_url . '?stripe_return=1&stripe_flow=package&session_id={CHECKOUT_SESSION_ID}';

        $checkout_session = $stripe->checkout->sessions->create( [
            'ui_mode'        => 'embedded_page',
            'mode'           => 'payment',
            'customer_email' => $customer['email'],
            'line_items'     => [ [
                'price_data' => [
                    'currency'     => 'mxn',
                    'unit_amount'  => $amount_cents,
                    'product_data' => [
                        'name'        => $package['name'],
                        'description' => 'Paquete de consultas VirtualMD',
                    ],
                ],
                'quantity' => 1,
            ] ],
            'metadata' => [
                'source'    => 'virtualmd_package_widget',
                'packageId' => (string) $package['id'],
            ],
            'return_url' => $return_url,
        ] );

        set_transient(
            vm_package_transient_key( 'vm_stripe_package', $checkout_session->id ),
            [
                'package'  => $package,
                'customer' => $customer,
            ],
            2 * HOUR_IN_SECONDS
        );

        wp_send_json_success( [
            'clientSecret' => $checkout_session->client_secret,
            'sessionId'    => $checkout_session->id,
        ] );
    } catch ( \Stripe\Exception\ApiErrorException $e ) {
        wp_send_json_error( [ 'message' => 'Error de Stripe: ' . $e->getMessage() ] );
    } catch ( \Exception $e ) {
        wp_send_json_error( [ 'message' => 'Error inesperado: ' . $e->getMessage() ] );
    }
}

function vm_stripe_verify_package_payment_handler() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Método no permitido' ] );
        return;
    }

    if ( ! defined( 'STRIPE_SECRET_KEY' ) || ! STRIPE_SECRET_KEY ) {
        wp_send_json_error( [ 'message' => 'STRIPE_SECRET_KEY no está definida en wp-config.php' ] );
        return;
    }

    $vendor_path = VMHB_PLUGIN_DIR . 'stripe/vendor/autoload.php';
    if ( ! file_exists( $vendor_path ) ) {
        wp_send_json_error( [ 'message' => 'Librería Stripe no instalada.' ] );
        return;
    }
    require_once $vendor_path;

    $raw = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );
    $session_id = isset( $data['session_id'] ) ? sanitize_text_field( $data['session_id'] ) : '';

    if ( ! $session_id ) {
        wp_send_json_error( [ 'message' => 'session_id requerido' ] );
        return;
    }

    try {
        $stripe = new \Stripe\StripeClient( STRIPE_SECRET_KEY );
        $session = $stripe->checkout->sessions->retrieve( $session_id );

        if ( $session->payment_status !== 'paid' ) {
            wp_send_json_error( [
                'message' => 'Pago no completado',
                'status'  => $session->payment_status,
            ] );
            return;
        }

        $stored = get_transient( vm_package_transient_key( 'vm_stripe_package', $session_id ) );
        if ( ! is_array( $stored ) || empty( $stored['package'] ) || empty( $stored['customer'] ) ) {
            wp_send_json_error( [ 'message' => 'No se encontró el contexto del paquete para esta sesión.' ] );
            return;
        }

        $reference = $session->payment_intent ? (string) $session->payment_intent : $session_id;
        $purchase_result = vm_package_create_amelia_purchase( $stored['package'], $stored['customer'], 'stripe', $reference );

        if ( empty( $purchase_result['success'] ) ) {
            wp_send_json_error( [
                'message'        => isset( $purchase_result['message'] ) ? $purchase_result['message'] : 'El pago se completó, pero no se pudo registrar el paquete.',
                'payment_status' => 'paid',
                'package_result' => $purchase_result,
            ] );
            return;
        }

        delete_transient( vm_package_transient_key( 'vm_stripe_package', $session_id ) );

        wp_send_json_success( [
            'status'         => 'complete',
            'package_result' => $purchase_result,
        ] );
    } catch ( \Stripe\Exception\ApiErrorException $e ) {
        wp_send_json_error( [ 'message' => 'Error de Stripe: ' . $e->getMessage() ] );
    } catch ( \Exception $e ) {
        wp_send_json_error( [ 'message' => 'Error inesperado: ' . $e->getMessage() ] );
    }
}

function vm_paypal_create_package_order_handler() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Método no permitido' ] );
        return;
    }

    if ( ! defined( 'PAYPAL_CLIENT_ID' ) || ! PAYPAL_CLIENT_ID ||
         ! defined( 'PAYPAL_CLIENT_SECRET' ) || ! PAYPAL_CLIENT_SECRET ) {
        wp_send_json_error( [ 'message' => 'Credenciales de PayPal no configuradas en wp-config.php' ] );
        return;
    }

    $raw = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );
    if ( empty( $data ) || ! is_array( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos' ] );
        return;
    }

    $context = vm_package_build_checkout_context_from_request( $data );
    if ( empty( $context['success'] ) ) {
        wp_send_json_error( [ 'message' => $context['message'] ] );
        return;
    }

    $token = vm_paypal_get_access_token();
    if ( is_wp_error( $token ) ) {
        wp_send_json_error( [ 'message' => 'Error de autenticación PayPal: ' . $token->get_error_message() ] );
        return;
    }

    $package = $context['package'];
    $order_payload = [
        'intent'         => 'CAPTURE',
        'purchase_units' => [ [
            'description' => $package['name'] . ' - VirtualMD',
            'amount'      => [
                'currency_code' => 'MXN',
                'value'         => number_format( $package['price'], 2, '.', '' ),
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

    set_transient(
        vm_package_transient_key( 'vm_paypal_package', $body['id'] ),
        [
            'package'  => $package,
            'customer' => $context['customer'],
        ],
        2 * HOUR_IN_SECONDS
    );

    wp_send_json_success( [ 'orderID' => $body['id'] ] );
}

function vm_paypal_capture_package_order_handler() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Método no permitido' ] );
        return;
    }

    if ( ! defined( 'PAYPAL_CLIENT_ID' ) || ! PAYPAL_CLIENT_ID ||
         ! defined( 'PAYPAL_CLIENT_SECRET' ) || ! PAYPAL_CLIENT_SECRET ) {
        wp_send_json_error( [ 'message' => 'Credenciales de PayPal no configuradas en wp-config.php' ] );
        return;
    }

    $raw = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );
    if ( empty( $data ) || ! is_array( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos' ] );
        return;
    }

    $order_id = isset( $data['orderID'] ) ? sanitize_text_field( $data['orderID'] ) : '';
    if ( ! $order_id ) {
        wp_send_json_error( [ 'message' => 'orderID requerido' ] );
        return;
    }

    $token = vm_paypal_get_access_token();
    if ( is_wp_error( $token ) ) {
        wp_send_json_error( [ 'message' => 'Error de autenticación PayPal: ' . $token->get_error_message() ] );
        return;
    }

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

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $status = isset( $body['status'] ) ? $body['status'] : 'UNKNOWN';

    if ( $status !== 'COMPLETED' ) {
        wp_send_json_error( [
            'message' => 'El pago no se completó. Estado: ' . $status,
            'details' => $body,
        ] );
        return;
    }

    $stored = get_transient( vm_package_transient_key( 'vm_paypal_package', $order_id ) );
    if ( ! is_array( $stored ) || empty( $stored['package'] ) || empty( $stored['customer'] ) ) {
        wp_send_json_error( [
            'message'           => 'El pago se completó, pero no se encontró el contexto del paquete.',
            'status'            => 'COMPLETED',
            'payment_completed' => true,
            'details'           => $body,
        ] );
        return;
    }

    $capture = $body['purchase_units'][0]['payments']['captures'][0] ?? [];
    $reference = ! empty( $capture['id'] ) ? $capture['id'] : $order_id;
    $purchase_result = vm_package_create_amelia_purchase( $stored['package'], $stored['customer'], 'payPal', $reference );

    if ( empty( $purchase_result['success'] ) ) {
        wp_send_json_error( [
            'message'           => isset( $purchase_result['message'] ) ? $purchase_result['message'] : 'El pago se completó, pero no se pudo registrar el paquete.',
            'status'            => 'COMPLETED',
            'payment_completed' => true,
            'details'           => $body,
            'package_result'    => $purchase_result,
        ] );
        return;
    }

    delete_transient( vm_package_transient_key( 'vm_paypal_package', $order_id ) );

    wp_send_json_success( [
        'status'         => 'COMPLETED',
        'details'        => $body,
        'package_result' => $purchase_result,
    ] );
}
