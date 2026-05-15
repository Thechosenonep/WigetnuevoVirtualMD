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

function vm_package_money( $amount ) {
    return round( max( 0, (float) $amount ), 2 );
}

function vm_package_base_price_from_row( $row ) {
    $price = isset( $row['price'] ) ? (float) $row['price'] : 0.0;

    if ( $price <= 0 && isset( $row['calculatedPrice'] ) ) {
        $price = (float) $row['calculatedPrice'];
    }

    return vm_package_money( $price );
}

function vm_package_discount_percent_from_row( $row ) {
    $discount = isset( $row['discount'] ) ? (float) $row['discount'] : 0.0;

    return min( 100, max( 0, $discount ) );
}

function vm_package_price_details_from_row( $row ) {
    $base_price       = vm_package_base_price_from_row( $row );
    $discount_percent = vm_package_discount_percent_from_row( $row );
    $discount_amount  = vm_package_money( $base_price * ( $discount_percent / 100 ) );
    $final_price      = vm_package_money( $base_price - $discount_amount );

    return [
        'basePrice'              => $base_price,
        'packageDiscountPercent' => $discount_percent,
        'packageDiscountAmount'  => $discount_amount,
        'price'                  => $final_price,
    ];
}

function vm_package_price_from_row( $row ) {
    $details = vm_package_price_details_from_row( $row );

    return $details['price'];
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
    $price_details = vm_package_price_details_from_row( $package );
    $price = $price_details['price'];
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
        'basePrice'     => $price_details['basePrice'],
        'packageDiscountPercent' => $price_details['packageDiscountPercent'],
        'packageDiscountAmount'  => $price_details['packageDiscountAmount'],
        'price'         => $price,
        'subtotal'      => $price,
        'baseDisplayPrice' => '$' . number_format_i18n( $price_details['basePrice'], 2 ) . ' MXN',
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
            p.discount,
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
        return vm_package_base_price_from_row( $package ) > 0;
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
    $cache_key = 'vm_amelia_packages_catalog_v2';
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

function vm_package_coupon_public_data( $coupon ) {
    return [
        'id'              => isset( $coupon['id'] ) ? (int) $coupon['id'] : 0,
        'code'            => isset( $coupon['code'] ) ? sanitize_text_field( $coupon['code'] ) : '',
        'discountPercent' => isset( $coupon['discount'] ) ? min( 100, max( 0, (float) $coupon['discount'] ) ) : 0,
        'deduction'       => isset( $coupon['deduction'] ) ? vm_package_money( $coupon['deduction'] ) : 0,
    ];
}

function vm_package_build_pricing( $package, $coupon = null ) {
    $base_price              = isset( $package['basePrice'] ) ? vm_package_money( $package['basePrice'] ) : 0;
    $package_discount_amount = isset( $package['packageDiscountAmount'] ) ? vm_package_money( $package['packageDiscountAmount'] ) : 0;
    $package_discount_pct    = isset( $package['packageDiscountPercent'] ) ? (float) $package['packageDiscountPercent'] : 0;
    $subtotal                = isset( $package['price'] ) ? vm_package_money( $package['price'] ) : vm_package_money( $base_price - $package_discount_amount );
    $coupon_data             = $coupon ? vm_package_coupon_public_data( $coupon ) : null;
    $coupon_percent_amount   = 0;
    $coupon_fixed_amount     = 0;

    if ( $coupon_data ) {
        $coupon_percent_amount = vm_package_money( $subtotal * ( $coupon_data['discountPercent'] / 100 ) );
        $coupon_fixed_amount   = vm_package_money( $coupon_data['deduction'] );
    }

    $coupon_discount_amount = min( $subtotal, vm_package_money( $coupon_percent_amount + $coupon_fixed_amount ) );
    $total                  = vm_package_money( $subtotal - $coupon_discount_amount );

    return [
        'basePrice'              => $base_price,
        'packageDiscountPercent' => $package_discount_pct,
        'packageDiscountAmount'  => $package_discount_amount,
        'subtotal'               => $subtotal,
        'coupon'                 => $coupon_data,
        'couponId'               => $coupon_data ? $coupon_data['id'] : 0,
        'couponCode'             => $coupon_data ? $coupon_data['code'] : '',
        'couponDiscountAmount'   => $coupon_discount_amount,
        'total'                  => $total,
    ];
}

function vm_package_find_coupon_by_code( $coupon_code ) {
    global $wpdb;

    $coupon_code = trim( sanitize_text_field( $coupon_code ) );
    if ( $coupon_code === '' ) {
        return null;
    }

    $table = $wpdb->prefix . 'amelia_coupons';
    if ( ! vm_package_table_exists( $table ) ) {
        return null;
    }

    $coupon = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table WHERE LOWER(code) = LOWER(%s) LIMIT 1",
        $coupon_code
    ), ARRAY_A );

    return is_array( $coupon ) ? $coupon : null;
}

function vm_package_coupon_applies_to_package( $coupon, $package ) {
    global $wpdb;

    $coupon_id  = isset( $coupon['id'] ) ? (int) $coupon['id'] : 0;
    $package_id = isset( $package['id'] ) ? (int) $package['id'] : 0;

    if ( ! $coupon_id || ! $package_id ) {
        return false;
    }

    if ( ! empty( $coupon['allPackages'] ) ) {
        return true;
    }

    $coupon_packages_table = $wpdb->prefix . 'amelia_coupons_to_packages';
    if ( vm_package_table_exists( $coupon_packages_table ) ) {
        $package_match = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $coupon_packages_table WHERE couponId = %d AND packageId = %d LIMIT 1",
            $coupon_id,
            $package_id
        ) );

        if ( $package_match ) {
            return true;
        }
    }

    $service_ids = [];
    if ( ! empty( $package['services'] ) && is_array( $package['services'] ) ) {
        foreach ( $package['services'] as $service ) {
            if ( ! empty( $service['serviceId'] ) ) {
                $service_ids[] = (int) $service['serviceId'];
            }
        }
    }

    if ( empty( $service_ids ) ) {
        return false;
    }

    if ( ! empty( $coupon['allServices'] ) ) {
        return true;
    }

    $coupon_services_table = $wpdb->prefix . 'amelia_coupons_to_services';
    if ( ! vm_package_table_exists( $coupon_services_table ) ) {
        return false;
    }

    $placeholders = implode( ',', array_fill( 0, count( $service_ids ), '%d' ) );
    $query_args   = array_merge( [ $coupon_id ], $service_ids );
    $service_match = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $coupon_services_table WHERE couponId = %d AND serviceId IN ($placeholders) LIMIT 1",
        $query_args
    ) );

    return (bool) $service_match;
}

function vm_package_coupon_usage_count( $coupon_id, $customer_id = 0 ) {
    global $wpdb;

    $table = $wpdb->prefix . 'amelia_packages_to_customers';
    if ( ! vm_package_table_exists( $table ) ) {
        return 0;
    }

    $where = 'couponId = %d AND status IN (%s, %s)';
    $args  = [ (int) $coupon_id, 'approved', 'pending' ];

    if ( $customer_id ) {
        $where .= ' AND customerId = %d';
        $args[] = (int) $customer_id;
    }

    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE $where",
        $args
    ) );
}

function vm_package_validate_coupon_for_package( $package, $coupon_code, $customer_email = '' ) {
    $coupon = vm_package_find_coupon_by_code( $coupon_code );

    if ( ! $coupon ) {
        return [ 'success' => false, 'message' => 'Cupón no encontrado.' ];
    }

    if ( empty( $coupon['status'] ) || $coupon['status'] !== 'visible' ) {
        return [ 'success' => false, 'message' => 'Este cupón no está activo.' ];
    }

    $now = current_time( 'timestamp' );

    if ( ! empty( $coupon['startDate'] ) && strtotime( $coupon['startDate'] ) > $now ) {
        return [ 'success' => false, 'message' => 'Este cupón todavía no está vigente.' ];
    }

    if ( ! empty( $coupon['expirationDate'] ) ) {
        $expiration_raw = trim( (string) $coupon['expirationDate'] );
        $expiration_ts  = strtotime( $expiration_raw );
        if ( $expiration_ts && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiration_raw ) ) {
            $expiration_ts = strtotime( $expiration_raw . ' 23:59:59' );
        }
        if ( $expiration_ts && $expiration_ts < $now ) {
            return [ 'success' => false, 'message' => 'Este cupón ya venció.' ];
        }
    }

    if ( ! vm_package_coupon_applies_to_package( $coupon, $package ) ) {
        return [ 'success' => false, 'message' => 'Este cupón no aplica para el paquete seleccionado.' ];
    }

    $coupon_public = vm_package_coupon_public_data( $coupon );
    if ( $coupon_public['discountPercent'] <= 0 && $coupon_public['deduction'] <= 0 ) {
        return [ 'success' => false, 'message' => 'Este cupón no tiene descuento configurado.' ];
    }

    $coupon_id = (int) $coupon['id'];
    $limit     = isset( $coupon['limit'] ) ? (int) $coupon['limit'] : 0;
    if ( $limit > 0 && vm_package_coupon_usage_count( $coupon_id ) >= $limit ) {
        return [ 'success' => false, 'message' => 'Este cupón alcanzó su límite de uso.' ];
    }

    $customer_limit = isset( $coupon['customerLimit'] ) ? (int) $coupon['customerLimit'] : 0;
    if ( $customer_limit > 0 && $customer_email ) {
        $customer_id = vm_package_find_customer_by_email( $customer_email );
        if ( $customer_id && vm_package_coupon_usage_count( $coupon_id, $customer_id ) >= $customer_limit ) {
            return [ 'success' => false, 'message' => 'Este cupón ya fue usado por este cliente.' ];
        }
    }

    $pricing = vm_package_build_pricing( $package, $coupon );

    return [
        'success' => true,
        'coupon'  => $coupon_public,
        'pricing' => $pricing,
    ];
}

function vm_amelia_validate_package_coupon_handler() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Método no permitido' ] );
        return;
    }

    $raw  = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );

    if ( empty( $data ) || ! is_array( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos' ] );
        return;
    }

    $package_id  = isset( $data['packageId'] ) ? (int) $data['packageId'] : 0;
    $coupon_code = isset( $data['couponCode'] ) ? sanitize_text_field( $data['couponCode'] ) : '';
    $email       = isset( $data['customerEmail'] ) ? sanitize_email( $data['customerEmail'] ) : '';
    $package     = vm_package_get_catalog_item( $package_id );

    if ( ! $package ) {
        wp_send_json_error( [ 'message' => 'Paquete no disponible.' ] );
        return;
    }

    $result = vm_package_validate_coupon_for_package( $package, $coupon_code, $email );

    if ( empty( $result['success'] ) ) {
        wp_send_json_error( [ 'message' => $result['message'] ] );
        return;
    }

    wp_send_json_success( [
        'coupon'  => $result['coupon'],
        'pricing' => $result['pricing'],
    ] );
}

function vm_appointment_build_pricing( $service_price, $coupon = null ) {
    $base_price = vm_package_money( $service_price );
    $coupon_data = $coupon ? vm_package_coupon_public_data( $coupon ) : null;
    $coupon_percent_amount = 0;
    $coupon_fixed_amount = 0;

    if ( $coupon_data ) {
        $coupon_percent_amount = vm_package_money( $base_price * ( $coupon_data['discountPercent'] / 100 ) );
        $coupon_fixed_amount = vm_package_money( $coupon_data['deduction'] );
    }

    $coupon_discount_amount = min( $base_price, vm_package_money( $coupon_percent_amount + $coupon_fixed_amount ) );

    return [
        'basePrice'              => $base_price,
        'packageDiscountPercent' => 0,
        'packageDiscountAmount'  => 0,
        'subtotal'               => $base_price,
        'coupon'                 => $coupon_data,
        'couponId'               => $coupon_data ? $coupon_data['id'] : 0,
        'couponCode'             => $coupon_data ? $coupon_data['code'] : '',
        'couponDiscountAmount'   => $coupon_discount_amount,
        'total'                  => vm_package_money( $base_price - $coupon_discount_amount ),
    ];
}

function vm_appointment_coupon_applies_to_service( $coupon, $service_id ) {
    global $wpdb;

    $coupon_id = isset( $coupon['id'] ) ? (int) $coupon['id'] : 0;
    $service_id = (int) $service_id;

    if ( ! $coupon_id || ! $service_id ) {
        return false;
    }

    if ( ! empty( $coupon['allServices'] ) ) {
        return true;
    }

    $table = $wpdb->prefix . 'amelia_coupons_to_services';
    if ( ! vm_package_table_exists( $table ) ) {
        return false;
    }

    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE couponId = %d AND serviceId = %d LIMIT 1",
        $coupon_id,
        $service_id
    ) );
}

function vm_appointment_coupon_usage_count( $coupon_id, $customer_id = 0 ) {
    global $wpdb;

    $table = $wpdb->prefix . 'amelia_customer_bookings';
    if ( ! vm_package_table_exists( $table ) ) {
        return 0;
    }

    $where = 'couponId = %d AND status IN (%s, %s)';
    $args = [ (int) $coupon_id, 'approved', 'pending' ];

    if ( $customer_id ) {
        $where .= ' AND customerId = %d';
        $args[] = (int) $customer_id;
    }

    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE $where",
        $args
    ) );
}

function vm_appointment_validate_coupon_for_service( $service_id, $service_price, $coupon_code, $customer_email = '' ) {
    $coupon = vm_package_find_coupon_by_code( $coupon_code );

    if ( ! $coupon ) {
        return [ 'success' => false, 'message' => 'Cupón no encontrado.' ];
    }

    if ( empty( $coupon['status'] ) || $coupon['status'] !== 'visible' ) {
        return [ 'success' => false, 'message' => 'Este cupón no está activo.' ];
    }

    $now = current_time( 'timestamp' );

    if ( ! empty( $coupon['startDate'] ) && strtotime( $coupon['startDate'] ) > $now ) {
        return [ 'success' => false, 'message' => 'Este cupón todavía no está vigente.' ];
    }

    if ( ! empty( $coupon['expirationDate'] ) ) {
        $expiration_raw = trim( (string) $coupon['expirationDate'] );
        $expiration_ts = strtotime( $expiration_raw );
        if ( $expiration_ts && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiration_raw ) ) {
            $expiration_ts = strtotime( $expiration_raw . ' 23:59:59' );
        }
        if ( $expiration_ts && $expiration_ts < $now ) {
            return [ 'success' => false, 'message' => 'Este cupón ya venció.' ];
        }
    }

    if ( ! vm_appointment_coupon_applies_to_service( $coupon, $service_id ) ) {
        return [ 'success' => false, 'message' => 'Este cupón no aplica para la consulta seleccionada.' ];
    }

    $coupon_public = vm_package_coupon_public_data( $coupon );
    if ( $coupon_public['discountPercent'] <= 0 && $coupon_public['deduction'] <= 0 ) {
        return [ 'success' => false, 'message' => 'Este cupón no tiene descuento configurado.' ];
    }

    $coupon_id = (int) $coupon['id'];
    $limit = isset( $coupon['limit'] ) ? (int) $coupon['limit'] : 0;
    if ( $limit > 0 && vm_appointment_coupon_usage_count( $coupon_id ) >= $limit ) {
        return [ 'success' => false, 'message' => 'Este cupón alcanzó su límite de uso.' ];
    }

    $customer_limit = isset( $coupon['customerLimit'] ) ? (int) $coupon['customerLimit'] : 0;
    if ( $customer_limit > 0 && $customer_email ) {
        $customer_id = vm_package_find_customer_by_email( $customer_email );
        if ( $customer_id && vm_appointment_coupon_usage_count( $coupon_id, $customer_id ) >= $customer_limit ) {
            return [ 'success' => false, 'message' => 'Este cupón ya fue usado por este cliente.' ];
        }
    }

    return [
        'success' => true,
        'coupon'  => $coupon_public,
        'pricing' => vm_appointment_build_pricing( $service_price, $coupon ),
    ];
}

function vm_amelia_validate_appointment_coupon_handler() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Método no permitido' ] );
        return;
    }

    $raw = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );

    if ( empty( $data ) || ! is_array( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos' ] );
        return;
    }

    $service_id = isset( $data['serviceId'] ) ? (int) $data['serviceId'] : 0;
    $service_price = isset( $data['servicePrice'] ) ? (float) $data['servicePrice'] : 0;
    $coupon_code = isset( $data['couponCode'] ) ? sanitize_text_field( $data['couponCode'] ) : '';
    $email = isset( $data['customerEmail'] ) ? sanitize_email( $data['customerEmail'] ) : '';

    if ( ! $service_id || $service_price <= 0 ) {
        wp_send_json_error( [ 'message' => 'Consulta inválida para cupón.' ] );
        return;
    }

    $result = vm_appointment_validate_coupon_for_service( $service_id, $service_price, $coupon_code, $email );

    if ( empty( $result['success'] ) ) {
        wp_send_json_error( [ 'message' => $result['message'] ] );
        return;
    }

    wp_send_json_success( [
        'coupon'  => $result['coupon'],
        'pricing' => $result['pricing'],
    ] );
}

function vm_appointment_apply_coupon_to_booking_data( $booking_data, $pricing ) {
    if ( empty( $pricing ) || ! is_array( $pricing ) ) {
        return $booking_data;
    }

    if ( empty( $booking_data['payment'] ) || ! is_array( $booking_data['payment'] ) ) {
        $booking_data['payment'] = [];
    }

    $booking_data['payment']['amount'] = (float) ( $pricing['total'] ?? 0 );
    $booking_data['payment']['currency'] = $booking_data['payment']['currency'] ?? 'MXN';
    $booking_data['payment']['data'] = $booking_data['payment']['data'] ?? [];

    if ( ! empty( $pricing['couponId'] ) ) {
        $booking_data['payment']['couponId'] = (int) $pricing['couponId'];
        $booking_data['couponId'] = (int) $pricing['couponId'];
        foreach ( $booking_data['bookings'] ?? [] as $index => $booking ) {
            $booking_data['bookings'][ $index ]['couponId'] = (int) $pricing['couponId'];
            $booking_data['bookings'][ $index ]['price'] = (float) $pricing['total'];
        }
    }

    if ( ! empty( $pricing['couponCode'] ) ) {
        $note = 'Cupón aplicado: ' . sanitize_text_field( $pricing['couponCode'] ) . ' | Descuento: $' . number_format_i18n( $pricing['couponDiscountAmount'], 2 ) . ' MXN | Total: $' . number_format_i18n( $pricing['total'], 2 ) . ' MXN';
        $booking_data['internalNotes'] = ! empty( $booking_data['internalNotes'] )
            ? $booking_data['internalNotes'] . "\n" . $note
            : $note;
        $booking_data['payment']['data']['couponCode'] = sanitize_text_field( $pricing['couponCode'] );
    }

    return $booking_data;
}

function vm_appointment_complete_free_booking_handler() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Método no permitido' ] );
        return;
    }

    $raw = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );

    if ( empty( $data ) || ! is_array( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos' ] );
        return;
    }

    $service_id = isset( $data['serviceId'] ) ? (int) $data['serviceId'] : 0;
    $service_price = isset( $data['servicePrice'] ) ? (float) $data['servicePrice'] : 0;
    $coupon_code = isset( $data['couponCode'] ) ? sanitize_text_field( $data['couponCode'] ) : '';
    $customer_email = isset( $data['customerEmail'] ) ? sanitize_email( $data['customerEmail'] ) : '';
    $booking_data = isset( $data['bookingData'] ) && is_array( $data['bookingData'] ) ? $data['bookingData'] : [];
    $doctor_mode = isset( $data['doctorMode'] ) && $data['doctorMode'] === 'auto' ? 'auto' : 'manual';

    $coupon_result = vm_appointment_validate_coupon_for_service( $service_id, $service_price, $coupon_code, $customer_email );
    if ( empty( $coupon_result['success'] ) ) {
        wp_send_json_error( [ 'message' => $coupon_result['message'] ] );
        return;
    }

    if ( (float) $coupon_result['pricing']['total'] > 0 ) {
        wp_send_json_error( [ 'message' => 'Esta consulta todavía requiere pago.' ] );
        return;
    }

    $booking_data = vm_appointment_apply_coupon_to_booking_data( $booking_data, $coupon_result['pricing'] );
    $result = function_exists( __NAMESPACE__ . '\\vm_stripe_create_amelia_booking' )
        ? vm_stripe_create_amelia_booking( $booking_data, $doctor_mode )
        : [ 'success' => false, 'message' => 'No se pudo registrar la consulta gratuita: helper no disponible.' ];

    if ( empty( $result['success'] ) ) {
        wp_send_json_error( [
            'message' => $result['message'] ?? 'No se pudo registrar la consulta.',
            'booking_result' => $result,
        ] );
        return;
    }

    wp_send_json_success( [
        'status' => 'complete',
        'booking_result' => $result,
    ] );
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
        'lastName'        => $last_name ?: 'VirtualMD',
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

    $customer_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE email = %s AND type = 'customer' ORDER BY id DESC LIMIT 1",
        $email
    ) );

    if ( $customer_id ) {
        return $customer_id;
    }

    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE email = %s ORDER BY IF(type = 'customer', 0, 1), id DESC LIMIT 1",
        $email
    ) );
}

function vm_package_find_customer_by_phone( $phone ) {
    global $wpdb;

    $normalized_phone = preg_replace( '/\D+/', '', (string) $phone );
    if ( ! $normalized_phone ) {
        return 0;
    }

    $table = $wpdb->prefix . 'amelia_users';
    if ( ! vm_package_table_exists( $table ) ) {
        return 0;
    }

    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '') LIKE %s ORDER BY IF(type = 'customer', 0, 1), id DESC LIMIT 1",
        '%' . $wpdb->esc_like( substr( $normalized_phone, -10 ) )
    ) );
}

function vm_package_find_customer_by_external_id( $external_id ) {
    global $wpdb;

    $external_id = (int) $external_id;
    if ( ! $external_id ) {
        return 0;
    }

    $table = $wpdb->prefix . 'amelia_users';
    if ( ! vm_package_table_exists( $table ) ) {
        return 0;
    }

    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE externalId = %d ORDER BY IF(type = 'customer', 0, 1), id DESC LIMIT 1",
        $external_id
    ) );
}

function vm_package_get_wp_user_id_for_email( $email ) {
    $email = sanitize_email( $email );
    if ( ! $email || ! function_exists( 'get_user_by' ) ) {
        return 0;
    }

    $wp_user = get_user_by( 'email', $email );

    return $wp_user && ! empty( $wp_user->ID ) ? (int) $wp_user->ID : 0;
}

function vm_package_find_customer_via_api_search( $email ) {
    if ( ! defined( 'AMELIA_API_KEY' ) || ! AMELIA_API_KEY ) {
        return 0;
    }

    $email = sanitize_email( $email );
    if ( ! $email ) {
        return 0;
    }

    $url = add_query_arg( [
        'action' => 'wpamelia_api',
        'call'   => '/api/v1/users/customers',
        'page'   => 1,
        'search' => $email,
    ], admin_url( 'admin-ajax.php' ) );

    $response = wp_remote_request( $url, [
        'method'    => 'GET',
        'timeout'   => 15,
        'headers'   => [
            'Content-Type' => 'application/json',
            'Amelia'       => AMELIA_API_KEY,
        ],
        'sslverify' => false,
        'cookies'   => $_COOKIE,
    ] );

    if ( is_wp_error( $response ) ) {
        vm_package_log( 'No se pudo buscar customer de paquete en Amelia API.', [
            'emailHash' => substr( md5( strtolower( $email ) ), 0, 10 ),
            'message'   => $response->get_error_message(),
        ] );
        return 0;
    }

    $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $decoded ) ) {
        return 0;
    }

    $users = [];
    if ( ! empty( $decoded['data']['users'] ) && is_array( $decoded['data']['users'] ) ) {
        $users = $decoded['data']['users'];
    } elseif ( ! empty( $decoded['users'] ) && is_array( $decoded['users'] ) ) {
        $users = $decoded['users'];
    }

    foreach ( $users as $user ) {
        if (
            is_array( $user ) &&
            ! empty( $user['id'] ) &&
            ! empty( $user['email'] ) &&
            strtolower( sanitize_email( $user['email'] ) ) === strtolower( $email )
        ) {
            return (int) $user['id'];
        }
    }

    return vm_package_pick_customer_id_recursive( $decoded );
}

function vm_package_find_customer_by_identity( $customer ) {
    $customer_id = ! empty( $customer['email'] ) ? vm_package_find_customer_by_email( $customer['email'] ) : 0;

    if ( $customer_id ) {
        return $customer_id;
    }

    $wp_user_id = ! empty( $customer['email'] ) ? vm_package_get_wp_user_id_for_email( $customer['email'] ) : 0;
    $customer_id = $wp_user_id ? vm_package_find_customer_by_external_id( $wp_user_id ) : 0;

    if ( $customer_id ) {
        return $customer_id;
    }

    $customer_id = ! empty( $customer['email'] ) ? vm_package_find_customer_via_api_search( $customer['email'] ) : 0;

    if ( $customer_id ) {
        return $customer_id;
    }

    return ! empty( $customer['phone'] ) ? vm_package_find_customer_by_phone( $customer['phone'] ) : 0;
}

function vm_package_pick_customer_id_recursive( $data ) {
    if ( ! is_array( $data ) ) {
        return 0;
    }

    foreach ( [ 'customerId', 'customerID', 'customer_id', 'userId', 'userID', 'user_id' ] as $key ) {
        if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) && (int) $data[ $key ] > 0 ) {
            return (int) $data[ $key ];
        }
    }

    foreach ( [ 'customer', 'user', 'customers', 'users' ] as $key ) {
        if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
            $customer_id = vm_package_pick_customer_id_recursive( $data[ $key ] );
            if ( $customer_id ) {
                return $customer_id;
            }
        }
    }

    if (
        isset( $data['id'] ) &&
        is_numeric( $data['id'] ) &&
        (int) $data['id'] > 0 &&
        (
            empty( $data['type'] ) ||
            strtolower( (string) $data['type'] ) === 'customer'
        )
    ) {
        return (int) $data['id'];
    }

    foreach ( $data as $value ) {
        if ( is_array( $value ) ) {
            $customer_id = vm_package_pick_customer_id_recursive( $value );
            if ( $customer_id ) {
                return $customer_id;
            }
        }
    }

    return 0;
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

    $wp_user_id = ! empty( $customer['email'] ) ? vm_package_get_wp_user_id_for_email( $customer['email'] ) : 0;
    if ( $wp_user_id ) {
        $payload['externalId'] = $wp_user_id;
    }

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
        [ 'data', 'users', 0, 'id' ],
        [ 'data', 'customers', 0, 'id' ],
        [ 'data', 'userId' ],
        [ 'data', 'customerId' ],
        [ 'data', 'id' ],
        [ 'user', 'id' ],
        [ 'customer', 'id' ],
        [ 'users', 0, 'id' ],
        [ 'customers', 0, 'id' ],
        [ 'userId' ],
        [ 'customerId' ],
        [ 'id' ],
    ] );

    if ( ! $customer_id ) {
        $customer_id = vm_package_pick_customer_id_recursive( $result['data'] );
    }

    if ( ! $customer_id ) {
        $customer_id = vm_package_find_customer_by_identity( $customer );
    }

    if ( ! $customer_id ) {
        vm_package_log( 'Amelia creó cliente para paquete sin customerId detectable.', [
            'emailHash' => ! empty( $customer['email'] ) ? substr( md5( strtolower( $customer['email'] ) ), 0, 10 ) : '',
            'keys'      => is_array( $result['data'] ) ? array_keys( $result['data'] ) : [],
        ] );

        return [
            'success' => false,
            'message' => 'Amelia creó el cliente, pero no pudimos localizar su customerId para registrar el paquete.',
            'amelia'  => $result['data'],
        ];
    }

    return [ 'success' => true, 'customerId' => $customer_id, 'created' => true ];
}

function vm_package_get_or_create_customer( $customer ) {
    if ( empty( $customer['email'] ) || ! is_email( $customer['email'] ) ) {
        return [ 'success' => false, 'message' => 'Correo del cliente inválido' ];
    }

    $existing_id = vm_package_find_customer_by_identity( $customer );
    if ( $existing_id ) {
        return [ 'success' => true, 'customerId' => $existing_id, 'created' => false ];
    }

    return vm_package_create_customer_via_api( $customer );
}

function vm_package_build_customer_update_body( $customer ) {
    $body = [];

    if ( ! empty( $customer['firstName'] ) ) {
        $body['firstName'] = sanitize_text_field( $customer['firstName'] );
    }

    if ( ! empty( $customer['lastName'] ) ) {
        $body['lastName'] = sanitize_text_field( $customer['lastName'] );
    }

    if ( ! empty( $customer['phone'] ) ) {
        $phone = sanitize_text_field( $customer['phone'] );
        $body['phone'] = function_exists( __NAMESPACE__ . '\\vm_amelia_normalize_phone_for_delivery' )
            ? vm_amelia_normalize_phone_for_delivery( $phone )
            : $phone;
    }

    if ( ! empty( $customer['countryPhoneIso'] ) ) {
        $body['countryPhoneIso'] = strtolower( sanitize_text_field( $customer['countryPhoneIso'] ) );
    }

    return $body;
}

function vm_package_update_customer_directly( $customer_id, $body ) {
    global $wpdb;

    $customer_id = (int) $customer_id;
    if ( ! $customer_id || empty( $body ) || ! is_array( $body ) ) {
        return [ 'success' => false, 'message' => 'Datos insuficientes para actualizar customer en DB' ];
    }

    $table = $wpdb->prefix . 'amelia_users';
    if ( ! vm_package_table_exists( $table ) ) {
        return [ 'success' => false, 'message' => 'Tabla amelia_users no disponible' ];
    }

    $data = [];

    foreach ( [ 'firstName', 'lastName', 'phone', 'countryPhoneIso' ] as $key ) {
        if ( array_key_exists( $key, $body ) && $body[ $key ] !== '' ) {
            $data[ $key ] = sanitize_text_field( $body[ $key ] );
        }
    }

    if ( empty( $data ) ) {
        return [ 'success' => false, 'message' => 'Sin campos para actualizar customer en DB' ];
    }

    $updated = $wpdb->update( $table, $data, [ 'id' => $customer_id ] );

    if ( $updated === false ) {
        return [ 'success' => false, 'message' => 'No se pudo actualizar customer en DB' ];
    }

    return [ 'success' => true, 'directDb' => true ];
}

function vm_package_sync_customer_form_data( $customer_id, $customer ) {
    $customer_id = (int) $customer_id;
    if ( ! $customer_id ) {
        return [ 'success' => false, 'message' => 'Sin customerId para sincronizar datos del formulario' ];
    }

    $body = vm_package_build_customer_update_body( $customer );
    if ( empty( $body ) ) {
        return [ 'success' => false, 'message' => 'Sin datos del formulario para sincronizar' ];
    }

    $result = function_exists( __NAMESPACE__ . '\\vm_amelia_send_customer_update_request' )
        ? vm_amelia_send_customer_update_request( $customer_id, $body )
        : vm_amelia_api_request( 'POST', '/users/customers/' . $customer_id, $body );

    if ( ! empty( $result['success'] ) ) {
        return $result;
    }

    $fallback = vm_package_update_customer_directly( $customer_id, $body );

    if ( empty( $fallback['success'] ) ) {
        vm_package_log( 'No se pudo sincronizar customer de paquete con datos del formulario.', [
            'customerId' => $customer_id,
            'apiResult'  => $result,
            'dbResult'   => $fallback,
        ] );

        return $result;
    }

    vm_package_log( 'Customer de paquete sincronizado por fallback DB.', [
        'customerId' => $customer_id,
    ] );

    return $fallback;
}

function vm_package_mark_payment_paid( $payment_id, $amount, $gateway, $reference, $pricing = [] ) {
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
        'couponId'         => ! empty( $pricing['couponId'] ) ? (int) $pricing['couponId'] : null,
        'data'             => [
            'source'     => 'virtualmd_package_widget',
            'couponCode' => ! empty( $pricing['couponCode'] ) ? sanitize_text_field( $pricing['couponCode'] ) : '',
        ],
    ];

    if ( empty( $payload['couponId'] ) ) {
        unset( $payload['couponId'] );
    }

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

function vm_package_send_purchase_confirmation_email( $package, $customer, $pricing, $purchase_result ) {
    $email = ! empty( $customer['email'] ) ? sanitize_email( $customer['email'] ) : '';
    if ( ! $email || ! is_email( $email ) ) {
        return [ 'success' => false, 'message' => 'Sin correo válido para notificación de paquete' ];
    }

    $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $subject   = 'Confirmación de compra de paquete - ' . $site_name;
    $lines     = [
        'Hola ' . trim( $customer['firstName'] . ' ' . $customer['lastName'] ) . ',',
        '',
        'Tu paquete fue comprado correctamente.',
        '',
        'Paquete: ' . $package['name'],
    ];

    if ( ! empty( $package['services'] ) && is_array( $package['services'] ) ) {
        $lines[] = 'Incluye:';
        foreach ( $package['services'] as $service ) {
            $qty   = ! empty( $service['quantity'] ) ? (int) $service['quantity'] : 1;
            $name  = ! empty( $service['name'] ) ? $service['name'] : 'Servicio';
            $lines[] = '- ' . $qty . ' x ' . $name;
        }
    }

    $lines[] = '';
    $lines[] = 'Total pagado: MX$' . number_format_i18n( $pricing['total'] ?? $package['price'], 2 );

    if ( ! empty( $pricing['couponCode'] ) ) {
        $lines[] = 'Cupón aplicado: ' . $pricing['couponCode'];
    }

    if ( ! empty( $purchase_result['packageCustomerId'] ) ) {
        $lines[] = 'Folio de paquete: ' . (int) $purchase_result['packageCustomerId'];
    }

    $lines[] = '';
    $lines[] = 'Gracias por confiar en VirtualMD.';

    $sent = wp_mail( $email, $subject, implode( "\n", $lines ) );

    return $sent
        ? [ 'success' => true, 'email' => $email ]
        : [ 'success' => false, 'message' => 'wp_mail no pudo enviar la notificación de paquete' ];
}

function vm_package_create_amelia_purchase( $package, $customer, $gateway, $reference, $pricing = [], $idempotency_key = '' ) {
    if ( ! defined( 'AMELIA_API_KEY' ) || ! AMELIA_API_KEY ) {
        return [ 'success' => false, 'message' => 'AMELIA_API_KEY no definida' ];
    }

    if ( empty( $package['id'] ) || empty( $package['basePrice'] ) || (float) $package['basePrice'] <= 0 ) {
        return [ 'success' => false, 'message' => 'Paquete inválido' ];
    }

    if ( empty( $pricing ) || ! is_array( $pricing ) ) {
        $pricing = vm_package_build_pricing( $package );
    }

    if ( $idempotency_key ) {
        $result_key = vm_package_transient_key( 'vm_package_purchase_result', $idempotency_key );
        $stored_result = get_transient( $result_key );
        if ( is_array( $stored_result ) && ! empty( $stored_result['success'] ) ) {
            return $stored_result;
        }
    }

    $customer_result = vm_package_get_or_create_customer( $customer );
    if ( empty( $customer_result['success'] ) ) {
        return $customer_result;
    }

    $customer_sync = vm_package_sync_customer_form_data( $customer_result['customerId'], $customer );
    if ( empty( $customer_sync['success'] ) ) {
        vm_package_log( 'Se continuará con compra de paquete aunque no se pudo actualizar nombre del customer antes de notificar.', [
            'customerId' => (int) $customer_result['customerId'],
            'result'     => $customer_sync,
        ] );
    }

    $payload = [
        'packageId'  => (int) $package['id'],
        'customerId' => (int) $customer_result['customerId'],
        'notify'     => true,
    ];

    vm_package_log( 'Creando compra de paquete en Amelia.', [
        'packageId' => (int) $package['id'],
        'customerId' => (int) $customer_result['customerId'],
        'basePrice' => $pricing['basePrice'] ?? null,
        'packageDiscount' => $pricing['packageDiscountAmount'] ?? null,
        'couponId' => $pricing['couponId'] ?? 0,
        'total' => $pricing['total'] ?? null,
    ] );

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
        $payment_update = vm_package_mark_payment_paid( $payment_id, $pricing['total'], $gateway, $reference, $pricing );
    }

    if ( ! $package_customer_id ) {
        return [
            'success' => false,
            'message' => 'Amelia no devolvió packageCustomerId al crear el paquete.',
            'amelia'  => $response_data,
        ];
    }

    $result = [
        'success'           => true,
        'customerId'        => (int) $customer_result['customerId'],
        'customerCreated'   => ! empty( $customer_result['created'] ),
        'customerSync'      => $customer_sync,
        'packageCustomerId' => $package_customer_id,
        'paymentId'         => $payment_id,
        'paymentUpdate'     => $payment_update,
        'pricing'           => $pricing,
        'amelia'            => $response_data,
    ];

    $manual_notification = vm_package_send_purchase_confirmation_email( $package, $customer, $pricing, $result );
    $result['manualNotification'] = $manual_notification;

    if ( empty( $manual_notification['success'] ) ) {
        vm_package_log( 'Compra de paquete creada, pero falló notificación manual.', [
            'packageCustomerId' => $package_customer_id,
            'result'            => $manual_notification,
        ] );
    } else {
        vm_package_log( 'Notificación manual de paquete enviada.', [
            'packageCustomerId' => $package_customer_id,
            'packageId'         => (int) $package['id'],
        ] );
    }

    if ( ! empty( $result_key ) ) {
        set_transient( $result_key, $result, 24 * HOUR_IN_SECONDS );
    }

    return $result;
}

function vm_package_transient_key( $prefix, $id ) {
    return $prefix . '_' . sanitize_key( $id );
}

function vm_package_build_checkout_context_from_request( $data ) {
    $package_id = isset( $data['packageId'] ) ? (int) $data['packageId'] : 0;
    $package = vm_package_get_catalog_item( $package_id );

    if ( ! $package || empty( $package['basePrice'] ) || (float) $package['basePrice'] <= 0 ) {
        return [ 'success' => false, 'message' => 'Paquete no disponible para compra' ];
    }

    $customer = vm_package_extract_customer_data( $data );
    if ( empty( $customer['email'] ) || ! is_email( $customer['email'] ) ) {
        return [ 'success' => false, 'message' => 'Correo del cliente inválido' ];
    }

    $coupon_code = '';
    if ( ! empty( $data['couponCode'] ) ) {
        $coupon_code = sanitize_text_field( $data['couponCode'] );
    } elseif ( ! empty( $data['coupon']['code'] ) ) {
        $coupon_code = sanitize_text_field( $data['coupon']['code'] );
    }

    $coupon  = null;
    $pricing = vm_package_build_pricing( $package );

    if ( $coupon_code !== '' ) {
        $coupon_result = vm_package_validate_coupon_for_package( $package, $coupon_code, $customer['email'] );

        if ( empty( $coupon_result['success'] ) ) {
            return [ 'success' => false, 'message' => $coupon_result['message'] ];
        }

        $coupon  = $coupon_result['coupon'];
        $pricing = $coupon_result['pricing'];
    }

    return [
        'success'  => true,
        'package'  => $package,
        'customer' => $customer,
        'coupon'   => $coupon,
        'pricing'  => $pricing,
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
        $pricing = $context['pricing'];
        if ( $pricing['total'] <= 0 ) {
            wp_send_json_error( [ 'message' => 'Este paquete no requiere pago. Completa la compra con el botón de cupón.' ] );
            return;
        }
        $amount_cents = (int) round( $pricing['total'] * 100 );
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
                'couponId'  => ! empty( $pricing['couponId'] ) ? (string) $pricing['couponId'] : '',
                'couponCode'=> ! empty( $pricing['couponCode'] ) ? (string) $pricing['couponCode'] : '',
                'total'     => (string) $pricing['total'],
            ],
            'return_url' => $return_url,
        ] );

        set_transient(
            vm_package_transient_key( 'vm_stripe_package', $checkout_session->id ),
            [
                'package'  => $package,
                'customer' => $customer,
                'coupon'   => $context['coupon'],
                'pricing'  => $pricing,
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

        $stored_result = get_transient( vm_package_transient_key( 'vm_package_purchase_result', 'stripe_' . $session_id ) );
        if ( is_array( $stored_result ) && ! empty( $stored_result['success'] ) ) {
            wp_send_json_success( [
                'status'         => 'complete',
                'package_result' => $stored_result,
            ] );
            return;
        }

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
        $purchase_result = vm_package_create_amelia_purchase(
            $stored['package'],
            $stored['customer'],
            'stripe',
            $reference,
            $stored['pricing'] ?? [],
            'stripe_' . $session_id
        );

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
    $pricing = $context['pricing'];
    if ( $pricing['total'] <= 0 ) {
        wp_send_json_error( [ 'message' => 'Este paquete no requiere pago. Completa la compra con el botón de cupón.' ] );
        return;
    }

    $order_payload = [
        'intent'         => 'CAPTURE',
        'purchase_units' => [ [
            'description' => $package['name'] . ' - VirtualMD',
            'custom_id'   => 'package_' . (int) $package['id'] . ( ! empty( $pricing['couponId'] ) ? '_coupon_' . (int) $pricing['couponId'] : '' ),
            'amount'      => [
                'currency_code' => 'MXN',
                'value'         => number_format( $pricing['total'], 2, '.', '' ),
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
            'coupon'   => $context['coupon'],
            'pricing'  => $pricing,
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

    $stored_result = get_transient( vm_package_transient_key( 'vm_package_purchase_result', 'paypal_' . $order_id ) );
    if ( is_array( $stored_result ) && ! empty( $stored_result['success'] ) ) {
        wp_send_json_success( [
            'status'         => 'COMPLETED',
            'package_result' => $stored_result,
        ] );
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
    $purchase_result = vm_package_create_amelia_purchase(
        $stored['package'],
        $stored['customer'],
        'payPal',
        $reference,
        $stored['pricing'] ?? [],
        'paypal_' . $order_id
    );

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

function vm_package_complete_free_purchase_handler() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Método no permitido' ] );
        return;
    }

    $raw  = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );

    if ( empty( $data ) || ! is_array( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos' ] );
        return;
    }

    $attempt_id = ! empty( $data['attemptId'] )
        ? sanitize_key( $data['attemptId'] )
        : md5( wp_json_encode( [
            $data['packageId'] ?? 0,
            $data['couponCode'] ?? '',
            $data['customerData']['email'] ?? '',
        ] ) );

    $stored_result = get_transient( vm_package_transient_key( 'vm_package_purchase_result', 'free_' . $attempt_id ) );
    if ( is_array( $stored_result ) && ! empty( $stored_result['success'] ) ) {
        wp_send_json_success( [
            'status'         => 'complete',
            'package_result' => $stored_result,
        ] );
        return;
    }

    $context = vm_package_build_checkout_context_from_request( $data );
    if ( empty( $context['success'] ) ) {
        wp_send_json_error( [ 'message' => $context['message'] ] );
        return;
    }

    if ( empty( $context['pricing'] ) || (float) $context['pricing']['total'] > 0 ) {
        wp_send_json_error( [ 'message' => 'Este paquete todavía requiere pago.' ] );
        return;
    }

    $reference = ! empty( $context['pricing']['couponCode'] )
        ? 'coupon-' . sanitize_key( $context['pricing']['couponCode'] )
        : 'free-package';

    $purchase_result = vm_package_create_amelia_purchase(
        $context['package'],
        $context['customer'],
        'onSite',
        $reference,
        $context['pricing'],
        'free_' . $attempt_id
    );

    if ( empty( $purchase_result['success'] ) ) {
        wp_send_json_error( [
            'message'        => isset( $purchase_result['message'] ) ? $purchase_result['message'] : 'No se pudo registrar el paquete.',
            'package_result' => $purchase_result,
        ] );
        return;
    }

    wp_send_json_success( [
        'status'         => 'complete',
        'package_result' => $purchase_result,
    ] );
}
