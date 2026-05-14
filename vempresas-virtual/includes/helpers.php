<?php
namespace VirtualMD\EmpresasVirtual;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function table_name( $name ) {
    global $wpdb;
    return $wpdb->prefix . 'vev_' . $name;
}

function vev_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();

    dbDelta( "CREATE TABLE " . table_name( 'companies' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        account_role VARCHAR(20) NOT NULL DEFAULT 'admin',
        parent_company_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        company_name VARCHAR(190) NOT NULL,
        contact_name VARCHAR(190) NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(80) NULL,
        password_hash VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY account_role (account_role),
        KEY parent_company_id (parent_company_id),
        KEY email (email),
        KEY status (status)
    ) $charset;" );

    dbDelta( "CREATE TABLE " . table_name( 'products' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL,
        description TEXT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'service',
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        validity_days INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY active (active),
        KEY type (type)
    ) $charset;" );

    dbDelta( "CREATE TABLE " . table_name( 'product_items' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        service_id BIGINT UNSIGNED NOT NULL,
        service_name VARCHAR(190) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY service_id (service_id)
    ) $charset;" );

    dbDelta( "CREATE TABLE " . table_name( 'price_rules' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        min_quantity INT NOT NULL DEFAULT 1,
        discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY min_quantity (min_quantity)
    ) $charset;" );

    dbDelta( "CREATE TABLE " . table_name( 'product_companies' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        company_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY product_company (product_id, company_id),
        KEY company_id (company_id)
    ) $charset;" );

    dbDelta( "CREATE TABLE " . table_name( 'purchases' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        stripe_session_id VARCHAR(190) NULL,
        payment_gateway VARCHAR(30) NULL,
        payment_reference VARCHAR(190) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency VARCHAR(10) NOT NULL DEFAULT 'MXN',
        quantity INT NOT NULL DEFAULT 1,
        payload LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        paid_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY company_id (company_id),
        KEY status (status),
        KEY stripe_session_id (stripe_session_id),
        KEY payment_reference (payment_reference)
    ) $charset;" );

    dbDelta( "CREATE TABLE " . table_name( 'credits' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id BIGINT UNSIGNED NOT NULL,
        purchase_id BIGINT UNSIGNED NOT NULL,
        source_credit_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        assigned_by_company_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        origin VARCHAR(30) NOT NULL DEFAULT 'purchase',
        service_id BIGINT UNSIGNED NOT NULL,
        service_name VARCHAR(190) NOT NULL,
        total_quantity INT NOT NULL DEFAULT 0,
        used_quantity INT NOT NULL DEFAULT 0,
        assignment_note TEXT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY company_id (company_id),
        KEY service_id (service_id),
        KEY purchase_id (purchase_id),
        KEY source_credit_id (source_credit_id),
        KEY assigned_by_company_id (assigned_by_company_id)
    ) $charset;" );

    dbDelta( "CREATE TABLE " . table_name( 'appointments' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id BIGINT UNSIGNED NOT NULL,
        credit_id BIGINT UNSIGNED NOT NULL,
        service_id BIGINT UNSIGNED NOT NULL,
        service_name VARCHAR(190) NOT NULL,
        provider_id BIGINT UNSIGNED NOT NULL,
        provider_name VARCHAR(190) NULL,
        booking_start DATETIME NOT NULL,
        customer_name VARCHAR(190) NOT NULL,
        customer_email VARCHAR(190) NOT NULL,
        customer_phone VARCHAR(80) NULL,
        amelia_booking_id BIGINT UNSIGNED NULL,
        meeting_url TEXT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'booked',
        amelia_response LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY company_id (company_id),
        KEY credit_id (credit_id),
        KEY service_id (service_id),
        KEY booking_start (booking_start)
    ) $charset;" );

    vev_upgrade_existing_tables();
}

function column_exists( $table, $column ) {
    global $wpdb;

    return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', $column ) );
}

function add_column_if_missing( $table, $column, $definition ) {
    global $wpdb;

    if ( column_exists( $table, $column ) ) {
        return;
    }

    $wpdb->query( 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $definition );
}

function vev_upgrade_existing_tables() {
    global $wpdb;

    $companies = table_name( 'companies' );
    $credits   = table_name( 'credits' );

    add_column_if_missing( $companies, 'account_role', "account_role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER user_id" );
    add_column_if_missing( $companies, 'parent_company_id', 'parent_company_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER account_role' );
    add_column_if_missing( $companies, 'updated_at', 'updated_at DATETIME NULL AFTER created_at' );

    add_column_if_missing( $credits, 'source_credit_id', 'source_credit_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER purchase_id' );
    add_column_if_missing( $credits, 'assigned_by_company_id', 'assigned_by_company_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER source_credit_id' );
    add_column_if_missing( $credits, 'origin', "origin VARCHAR(30) NOT NULL DEFAULT 'purchase' AFTER assigned_by_company_id" );
    add_column_if_missing( $credits, 'assignment_note', 'assignment_note TEXT NULL AFTER used_quantity' );

    $wpdb->query( "UPDATE {$companies} SET account_role = 'admin' WHERE account_role = '' OR account_role IS NULL" );
    $wpdb->query( "UPDATE {$companies} SET parent_company_id = 0 WHERE account_role = 'admin'" );
    $wpdb->query( "UPDATE {$credits} SET origin = 'purchase' WHERE origin = '' OR origin IS NULL" );
}

function session_cookie_name() {
    return 'vev_company_session';
}

function session_signature( $company_id, $expires ) {
    return hash_hmac( 'sha256', (int) $company_id . '|' . (int) $expires, wp_salt( 'auth' ) );
}

function set_company_session( $company_id ) {
    $expires = time() + 12 * HOUR_IN_SECONDS;
    $value   = (int) $company_id . '|' . $expires . '|' . session_signature( $company_id, $expires );

    setcookie(
        session_cookie_name(),
        $value,
        [
            'expires'  => $expires,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );

    $_COOKIE[ session_cookie_name() ] = $value;
}

function clear_company_session() {
    setcookie(
        session_cookie_name(),
        '',
        [
            'expires'  => time() - HOUR_IN_SECONDS,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );

    unset( $_COOKIE[ session_cookie_name() ] );
}

function session_company_id() {
    $raw = isset( $_COOKIE[ session_cookie_name() ] ) ? (string) wp_unslash( $_COOKIE[ session_cookie_name() ] ) : '';
    $parts = explode( '|', $raw );

    if ( count( $parts ) !== 3 ) {
        return 0;
    }

    $company_id = (int) $parts[0];
    $expires    = (int) $parts[1];
    $signature  = (string) $parts[2];

    if ( ! $company_id || $expires < time() ) {
        return 0;
    }

    if ( ! hash_equals( session_signature( $company_id, $expires ), $signature ) ) {
        return 0;
    }

    return $company_id;
}

function current_company() {
    $company_id = session_company_id();
    if ( ! $company_id ) {
        return null;
    }

    global $wpdb;
    $company = $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'companies' ) . ' WHERE id = %d AND status = %s',
        $company_id,
        'active'
    ), ARRAY_A );

    return $company ?: null;
}

function is_company_user() {
    return (bool) current_company();
}

function account_role( $company ) {
    $role = isset( $company['account_role'] ) ? sanitize_key( $company['account_role'] ) : 'admin';

    return $role === 'user' ? 'user' : 'admin';
}

function is_company_admin_account( $company ) {
    return is_array( $company ) && account_role( $company ) === 'admin';
}

function is_company_child_account( $company ) {
    return is_array( $company ) && account_role( $company ) === 'user';
}

function company_belongs_to_admin( $company_id, $admin_id ) {
    global $wpdb;

    return (bool) $wpdb->get_var( $wpdb->prepare(
        'SELECT id FROM ' . table_name( 'companies' ) . ' WHERE id = %d AND parent_company_id = %d AND account_role = %s AND status = %s',
        (int) $company_id,
        (int) $admin_id,
        'user',
        'active'
    ) );
}

function consultation_is_expired( $consultation ) {
    return ! empty( $consultation['expires_at'] ) && strtotime( $consultation['expires_at'] ) < current_time( 'timestamp' );
}

function available_consultations_count( $consultation ) {
    if ( ! is_array( $consultation ) || consultation_is_expired( $consultation ) ) {
        return 0;
    }

    return max( 0, (int) $consultation['total_quantity'] - (int) $consultation['used_quantity'] );
}

function company_available_consultations_count( $company_id ) {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        'SELECT total_quantity, used_quantity, expires_at FROM ' . table_name( 'credits' ) . ' WHERE company_id = %d',
        (int) $company_id
    ), ARRAY_A );

    return array_reduce( $rows, static function ( $carry, $row ) {
        return $carry + available_consultations_count( $row );
    }, 0 );
}

function insert_consultation_inventory( $args ) {
    global $wpdb;

    $quantity = max( 0, (int) ( $args['quantity'] ?? 0 ) );
    if ( ! $quantity ) {
        return 0;
    }

    $wpdb->insert( table_name( 'credits' ), [
        'company_id'              => (int) ( $args['company_id'] ?? 0 ),
        'purchase_id'             => (int) ( $args['purchase_id'] ?? 0 ),
        'source_credit_id'        => (int) ( $args['source_credit_id'] ?? 0 ),
        'assigned_by_company_id'  => (int) ( $args['assigned_by_company_id'] ?? 0 ),
        'origin'                  => sanitize_key( $args['origin'] ?? 'manual' ),
        'service_id'              => (int) ( $args['service_id'] ?? 0 ),
        'service_name'            => sanitize_text_field( $args['service_name'] ?? '' ),
        'total_quantity'          => $quantity,
        'used_quantity'           => max( 0, (int) ( $args['used_quantity'] ?? 0 ) ),
        'assignment_note'         => sanitize_textarea_field( $args['assignment_note'] ?? '' ),
        'created_at'              => current_time( 'mysql' ),
        'expires_at'              => ! empty( $args['expires_at'] ) ? sanitize_text_field( $args['expires_at'] ) : null,
    ] );

    return (int) $wpdb->insert_id;
}

function return_unused_consultations( $consultation_id, $expected_admin_id = 0 ) {
    global $wpdb;

    $consultation = $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'credits' ) . ' WHERE id = %d',
        (int) $consultation_id
    ), ARRAY_A );

    if ( ! $consultation ) {
        return [ 'success' => false, 'message' => 'No se encontró la consulta asignada.' ];
    }

    if ( $expected_admin_id && (int) $consultation['assigned_by_company_id'] !== (int) $expected_admin_id ) {
        return [ 'success' => false, 'message' => 'Esta consulta no pertenece a tu ecosistema.' ];
    }

    $unused = max( 0, (int) $consultation['total_quantity'] - (int) $consultation['used_quantity'] );
    if ( ! $unused ) {
        return [ 'success' => true, 'returned' => 0 ];
    }

    if ( ! empty( $consultation['source_credit_id'] ) ) {
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . table_name( 'credits' ) . ' SET total_quantity = total_quantity + %d WHERE id = %d',
            $unused,
            (int) $consultation['source_credit_id']
        ) );
    }

    if ( (int) $consultation['used_quantity'] <= 0 ) {
        $wpdb->delete( table_name( 'credits' ), [ 'id' => (int) $consultation['id'] ] );
    } else {
        $wpdb->update( table_name( 'credits' ), [
            'total_quantity' => (int) $consultation['used_quantity'],
        ], [ 'id' => (int) $consultation['id'] ] );
    }

    return [ 'success' => true, 'returned' => $unused ];
}

function return_unused_consultations_for_company( $company_id, $expected_admin_id = 0 ) {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        'SELECT id FROM ' . table_name( 'credits' ) . ' WHERE company_id = %d',
        (int) $company_id
    ), ARRAY_A );

    $returned = 0;
    foreach ( $rows as $row ) {
        $result = return_unused_consultations( (int) $row['id'], $expected_admin_id );
        if ( ! empty( $result['success'] ) ) {
            $returned += (int) ( $result['returned'] ?? 0 );
        }
    }

    return $returned;
}

function money( $amount ) {
    return 'MX$' . number_format( (float) $amount, 2 );
}

function amelia_services() {
    global $wpdb;
    $svc = $wpdb->prefix . 'amelia_services';
    $cat = $wpdb->prefix . 'amelia_categories';

    return $wpdb->get_results(
        "SELECT s.id, s.name, s.price, s.duration, c.name AS category
         FROM {$svc} s
         LEFT JOIN {$cat} c ON c.id = s.categoryId
         WHERE s.status = 'visible'
         ORDER BY c.position ASC, s.position ASC, s.name ASC",
        ARRAY_A
    );
}

function amelia_service_name( $service_id ) {
    foreach ( amelia_services() as $service ) {
        if ( (int) $service['id'] === (int) $service_id ) {
            return trim( ( $service['category'] ? $service['category'] . ' - ' : '' ) . $service['name'] );
        }
    }

    return 'Servicio #' . (int) $service_id;
}

function amelia_provider_name( $provider_id ) {
    global $wpdb;
    $users = $wpdb->prefix . 'amelia_users';

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT firstName, lastName FROM {$users} WHERE id = %d",
        (int) $provider_id
    ), ARRAY_A );

    if ( ! $row ) {
        return 'Doctor #' . (int) $provider_id;
    }

    return trim( $row['firstName'] . ' ' . $row['lastName'] );
}

function build_schedule_map( $provider_ids, $service_id ) {
    global $wpdb;
    $prefix = $wpdb->prefix;
    $ids    = implode( ',', array_map( 'intval', $provider_ids ) );

    if ( $ids === '' ) {
        return [];
    }

    $rows = $wpdb->get_results(
        "SELECT id, userId, dayIndex, startTime, endTime
         FROM {$prefix}amelia_providers_to_weekdays
         WHERE userId IN ({$ids})",
        ARRAY_A
    );

    $schedule = [];
    $wd_map   = [];
    $wd_ids   = [];

    foreach ( $rows as $row ) {
        $uid = (int) $row['userId'];
        $day = (int) $row['dayIndex'];
        $wid = (int) $row['id'];

        $wd_ids[]       = $wid;
        $wd_map[ $wid ] = [ 'userId' => $uid, 'dayIndex' => $day ];

        if ( ! empty( $row['startTime'] ) && ! empty( $row['endTime'] ) ) {
            $schedule[ $uid ][ $day ][] = [
                'start' => substr( $row['startTime'], 0, 5 ),
                'end'   => substr( $row['endTime'], 0, 5 ),
            ];
        }
    }

    if ( empty( $wd_ids ) ) {
        return $schedule;
    }

    $wd_in   = implode( ',', array_map( 'intval', $wd_ids ) );
    $periods = $wpdb->get_results(
        "SELECT id AS periodId, weekDayId, startTime, endTime
         FROM {$prefix}amelia_providers_to_periods
         WHERE weekDayId IN ({$wd_in})",
        ARRAY_A
    );

    if ( empty( $periods ) ) {
        return $schedule;
    }

    $period_ids = implode( ',', array_map( 'intval', wp_list_pluck( $periods, 'periodId' ) ) );
    $svc_rows   = $period_ids
        ? $wpdb->get_results( "SELECT periodId, serviceId FROM {$prefix}amelia_providers_to_periods_services WHERE periodId IN ({$period_ids})", ARRAY_A )
        : [];

    $period_services = [];
    foreach ( $svc_rows as $row ) {
        $period_services[ (int) $row['periodId'] ][] = (int) $row['serviceId'];
    }

    $by_weekday = [];
    foreach ( $periods as $period ) {
        $pid = (int) $period['periodId'];
        if ( isset( $period_services[ $pid ] ) && ! in_array( (int) $service_id, $period_services[ $pid ], true ) ) {
            continue;
        }

        $by_weekday[ (int) $period['weekDayId'] ][] = [
            'start' => substr( $period['startTime'], 0, 5 ),
            'end'   => substr( $period['endTime'], 0, 5 ),
        ];
    }

    foreach ( $by_weekday as $week_day_id => $items ) {
        if ( empty( $wd_map[ $week_day_id ] ) ) {
            continue;
        }
        $uid = $wd_map[ $week_day_id ]['userId'];
        $day = $wd_map[ $week_day_id ]['dayIndex'];
        $schedule[ $uid ][ $day ] = $items;
    }

    return $schedule;
}

function get_slots_data( $service_id, $provider_id = 0, $start_date = '', $end_date = '', $duration = 0 ) {
    global $wpdb;
    $prefix  = $wpdb->prefix;
    $is_auto = empty( $provider_id );

    $now_ts      = current_time( 'timestamp' );
    $today       = date( 'Y-m-d', $now_ts );
    $now_minutes = (int) date( 'H', $now_ts ) * 60 + (int) date( 'i', $now_ts );

    $start_date = $start_date ?: $today;
    if ( $start_date < $today ) {
        $start_date = $today;
    }
    $end_date = $end_date ?: $start_date;
    if ( $end_date < $start_date ) {
        $end_date = $start_date;
    }

    if ( ! $duration ) {
        $duration = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT duration FROM {$prefix}amelia_services WHERE id = %d",
            (int) $service_id
        ) );
    }
    $duration = $duration ?: 3600;
    $duration_min = max( 1, (int) round( $duration / 60 ) );

    $service = $wpdb->get_row( $wpdb->prepare(
        "SELECT timeBefore, timeAfter FROM {$prefix}amelia_services WHERE id = %d",
        (int) $service_id
    ), ARRAY_A );
    $before_min = $service ? (int) $service['timeBefore'] / 60 : 0;
    $after_min  = $service ? (int) $service['timeAfter'] / 60 : 0;

    if ( $is_auto ) {
        $provider_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT ps.userId
             FROM {$prefix}amelia_providers_to_services ps
             INNER JOIN {$prefix}amelia_users u ON u.id = ps.userId
             WHERE ps.serviceId = %d AND u.status = 'visible' AND u.type = 'provider'",
            (int) $service_id
        ) );
    } else {
        $provider_ids = [ (int) $provider_id ];
    }

    $provider_ids = array_values( array_filter( array_map( 'intval', $provider_ids ) ) );
    if ( empty( $provider_ids ) ) {
        return [ 'slots' => (object) [], 'providerMap' => (object) [] ];
    }

    $provider_in = implode( ',', $provider_ids );
    $schedule    = build_schedule_map( $provider_ids, $service_id );

    $days_off_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT userId, startDate, endDate
         FROM {$prefix}amelia_providers_to_daysoff
         WHERE userId IN ({$provider_in}) AND endDate >= %s",
        $start_date
    ), ARRAY_A );

    $days_off = [];
    foreach ( $days_off_rows as $row ) {
        $days_off[ (int) $row['userId'] ][] = [ $row['startDate'], $row['endDate'] ];
    }

    $appointments = $wpdb->get_results( $wpdb->prepare(
        "SELECT providerId, bookingStart, bookingEnd
         FROM {$prefix}amelia_appointments
         WHERE providerId IN ({$provider_in})
           AND status IN ('approved', 'pending')
           AND bookingStart <= %s
           AND bookingEnd >= %s",
        $end_date . ' 23:59:59',
        $start_date . ' 00:00:00'
    ), ARRAY_A );

    $busy = [];
    foreach ( $appointments as $appointment ) {
        $pid = (int) $appointment['providerId'];
        $date = substr( $appointment['bookingStart'], 0, 10 );
        $start = (int) substr( $appointment['bookingStart'], 11, 2 ) * 60 + (int) substr( $appointment['bookingStart'], 14, 2 );
        $end = (int) substr( $appointment['bookingEnd'], 11, 2 ) * 60 + (int) substr( $appointment['bookingEnd'], 14, 2 );
        $busy[ $pid ][ $date ][] = [ $start - $before_min, $end + $after_min ];
    }

    $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( date_default_timezone_get() );
    $current = new \DateTime( $start_date, $tz );
    $last = new \DateTime( $end_date, $tz );
    $free = [];
    $provider_map = [];

    while ( $current <= $last ) {
        $date = $current->format( 'Y-m-d' );
        $day = (int) $current->format( 'N' );
        $is_today = ( $date === $today );

        foreach ( $provider_ids as $pid ) {
            if ( ! empty( $days_off[ $pid ] ) ) {
                $skip = false;
                foreach ( $days_off[ $pid ] as $range ) {
                    if ( $date >= $range[0] && $date <= $range[1] ) {
                        $skip = true;
                        break;
                    }
                }
                if ( $skip ) {
                    continue;
                }
            }

            if ( empty( $schedule[ $pid ][ $day ] ) ) {
                continue;
            }

            foreach ( $schedule[ $pid ][ $day ] as $period ) {
                $period_start = (int) substr( $period['start'], 0, 2 ) * 60 + (int) substr( $period['start'], 3, 2 );
                $period_end = (int) substr( $period['end'], 0, 2 ) * 60 + (int) substr( $period['end'], 3, 2 );

                for ( $slot = $period_start; $slot + $duration_min <= $period_end; $slot += $duration_min ) {
                    if ( $is_today && $slot <= $now_minutes ) {
                        continue;
                    }

                    $slot_end = $slot + $duration_min;
                    $conflict = false;
                    foreach ( $busy[ $pid ][ $date ] ?? [] as $range ) {
                        if ( $slot < $range[1] && $slot_end > $range[0] ) {
                            $conflict = true;
                            break;
                        }
                    }

                    if ( $conflict ) {
                        continue;
                    }

                    $time = sprintf( '%02d:%02d', floor( $slot / 60 ), $slot % 60 );
                    $free[ $date ][] = $time;
                    $provider_map[ $date ][ $time ][] = $pid;
                }
            }
        }

        if ( ! empty( $free[ $date ] ) ) {
            $free[ $date ] = array_values( array_unique( $free[ $date ] ) );
            sort( $free[ $date ] );
        }

        $current->modify( '+1 day' );
    }

    return [
        'slots'       => ! empty( $free ) ? $free : (object) [],
        'providerMap' => ! empty( $provider_map ) ? $provider_map : (object) [],
    ];
}

function validate_slot( $service_id, $provider_id, $booking_start ) {
    $date = substr( (string) $booking_start, 0, 10 );
    $time = substr( (string) $booking_start, 11, 5 );

    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
        return false;
    }

    $availability = get_slots_data( (int) $service_id, (int) $provider_id, $date, $date );
    $slots = is_array( $availability['slots'] ?? null ) && isset( $availability['slots'][ $date ] )
        ? $availability['slots'][ $date ]
        : [];

    return in_array( $time, $slots, true );
}

function extract_meeting_url( $payload ) {
    $keys = [ 'joinUrl', 'join_url', 'meetingUrl', 'meeting_url', 'meetingLink', 'zoomJoinUrl', 'googleMeetUrl', 'lessonSpaceUrl' ];

    if ( is_array( $payload ) ) {
        foreach ( $payload as $key => $value ) {
            if ( in_array( (string) $key, $keys, true ) && is_string( $value ) && strpos( $value, 'http' ) === 0 ) {
                return esc_url_raw( $value );
            }
        }
        foreach ( $payload as $value ) {
            $found = extract_meeting_url( $value );
            if ( $found ) {
                return $found;
            }
        }
    }

    return '';
}

function extract_booking_id( $payload ) {
    if ( isset( $payload['data']['appointment']['bookings'][0]['id'] ) ) {
        return (int) $payload['data']['appointment']['bookings'][0]['id'];
    }

    if ( isset( $payload['appointment']['bookings'][0]['id'] ) ) {
        return (int) $payload['appointment']['bookings'][0]['id'];
    }

    return 0;
}

function create_amelia_booking( $args ) {
    if ( ! defined( 'AMELIA_API_KEY' ) || ! AMELIA_API_KEY ) {
        return [ 'success' => false, 'message' => 'AMELIA_API_KEY no está definida' ];
    }

    $payload = [
        'type'                 => 'appointment',
        'bookingStart'         => $args['booking_start'],
        'notifyParticipants'   => 1,
        'locationId'           => 1,
        'providerId'           => (int) $args['provider_id'],
        'serviceId'            => (int) $args['service_id'],
        'bookings'             => [
            [
                'persons'      => 1,
                'duration'     => 0,
                'customerId'   => null,
                'customer'     => [
                    'id'           => null,
                    'firstName'    => $args['first_name'],
                    'lastName'     => $args['last_name'],
                    'email'        => $args['email'],
                    'phone'        => $args['phone'],
                    'externalId'   => null,
                ],
                'extras'       => [],
                'customFields' => [],
            ],
        ],
        'payment'              => [
            'gateway'  => 'onSite',
            'currency' => 'MXN',
            'data'     => [],
        ],
        'internalNotes'        => $args['notes'] ?? '',
        'runInstantPostBookingActions' => true,
    ];

    $url = add_query_arg( [
        'action' => 'wpamelia_api',
        'call'   => '/api/v1/bookings',
    ], admin_url( 'admin-ajax.php' ) );

    $response = wp_remote_request( $url, [
        'method'    => 'POST',
        'timeout'   => 20,
        'headers'   => [
            'Content-Type' => 'application/json',
            'Amelia'       => AMELIA_API_KEY,
        ],
        'body'      => wp_json_encode( $payload ),
        'sslverify' => false,
        'cookies'   => $_COOKIE,
    ] );

    if ( is_wp_error( $response ) ) {
        return [ 'success' => false, 'message' => $response->get_error_message() ];
    }

    $status = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $decoded = json_decode( $body, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return [ 'success' => false, 'message' => 'Amelia no respondió JSON válido. HTTP ' . $status ];
    }

    if ( $status >= 200 && $status < 300 && isset( $decoded['message'] ) ) {
        return [
            'success'    => true,
            'payload'    => $decoded,
            'booking_id' => extract_booking_id( $decoded ),
            'meeting_url'=> extract_meeting_url( $decoded ),
        ];
    }

    return [
        'success' => false,
        'message' => $decoded['message'] ?? ( $decoded['data']['message'] ?? 'No se pudo crear la cita en Amelia' ),
        'payload' => $decoded,
    ];
}

function product_with_items( $product_id ) {
    global $wpdb;

    $product = $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'products' ) . ' WHERE id = %d',
        (int) $product_id
    ), ARRAY_A );

    if ( ! $product ) {
        return null;
    }

    $product['items'] = $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'product_items' ) . ' WHERE product_id = %d ORDER BY id ASC',
        (int) $product_id
    ), ARRAY_A );

    $product['rules'] = $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'price_rules' ) . ' WHERE product_id = %d ORDER BY min_quantity ASC',
        (int) $product_id
    ), ARRAY_A );

    return $product;
}

function product_price_for_quantity( $product, $quantity ) {
    $quantity = max( 1, (int) $quantity );

    if ( $product['type'] === 'package' ) {
        return [
            'unit_price' => (float) $product['price'],
            'discount'   => 0,
            'total'      => (float) $product['price'] * $quantity,
        ];
    }

    $unit = (float) $product['price'];
    if ( ! $unit && ! empty( $product['items'][0]['unit_price'] ) ) {
        $unit = (float) $product['items'][0]['unit_price'];
    }

    $discount = 0;
    foreach ( $product['rules'] as $rule ) {
        if ( $quantity >= (int) $rule['min_quantity'] ) {
            $discount = (float) $rule['discount_percent'];
        }
    }

    $total = $unit * $quantity * ( 1 - ( $discount / 100 ) );

    return [
        'unit_price' => $unit,
        'discount'   => $discount,
        'total'      => max( 0, $total ),
    ];
}
