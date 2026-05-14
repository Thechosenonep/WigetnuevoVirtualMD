<?php
namespace VirtualMD\HeroBooking;

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Invalida todos los transients de disponibilidad de VirtualMD.
 *
 * Se llama automáticamente cuando Amelia crea, cancela o reprograma
 * una cita, y también manualmente desde nuestros flujos de pago
 * (Stripe/PayPal) después de crear la cita exitosamente.
 */
function vm_amelia_invalidate_availability_cache() {
  global $wpdb;

  // Borrar todos los transients que empiecen con vm_amelia_
  // Esto cubre: catalog, providers, has_availability, available_provider_ids, team_member_map
  $wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_vm_amelia_%'
        OR option_name LIKE '_transient_timeout_vm_amelia_%'"
  );
}

// --- Hooks de Amelia: invalidar caché cuando cambia una reserva ---
add_action( 'amelia_after_booking_added',       __NAMESPACE__ . '\\vm_amelia_invalidate_availability_cache' );
add_action( 'amelia_after_booking_canceled',    __NAMESPACE__ . '\\vm_amelia_invalidate_availability_cache' );
add_action( 'amelia_after_booking_rescheduled', __NAMESPACE__ . '\\vm_amelia_invalidate_availability_cache' );

// Hooks de acción internos de Amelia (post-booking actions)
add_action( 'AmeliaAppointmentBookingAdded',    __NAMESPACE__ . '\\vm_amelia_invalidate_availability_cache' );

function vm_amelia_get_catalog_handler() {
  $cache_key = 'vm_amelia_catalog_available_v2';
  $cached    = get_transient( $cache_key );
  if ( $cached !== false ) {
    wp_send_json_success( $cached );
    return;
  }

  global $wpdb;
  $t_cat = $wpdb->prefix . 'amelia_categories';
  $t_svc = $wpdb->prefix . 'amelia_services';

  // Categorías visibles
  $categories = $wpdb->get_results(
    "SELECT id, name FROM {$t_cat} WHERE status = 'visible' ORDER BY position ASC",
    ARRAY_A
  );

  if ( empty( $categories ) ) {
    wp_send_json_success( [] );
    return;
  }

  $cat_ids = wp_list_pluck( $categories, 'id' );
  $in      = implode( ',', array_map( 'intval', $cat_ids ) );

  // Servicios visibles dentro de esas categorías
  $services = $wpdb->get_results(
    "SELECT id, name, price, duration, categoryId
     FROM {$t_svc}
     WHERE status = 'visible' AND categoryId IN ({$in})
     ORDER BY position ASC",
    ARRAY_A
  );

  $by_cat = [];
  foreach ( $services as $s ) {
    if ( ! vm_amelia_service_has_availability( (int) $s['id'] ) ) {
      continue;
    }

    $by_cat[ (int) $s['categoryId'] ][] = [
      'id'       => (int) $s['id'],
      'type'     => $s['name'],
      'mode'     => 'Videoconsulta',
      'price'    => (float) $s['price'],
      'duration' => (int) $s['duration'],
    ];
  }

  $output = [];
  foreach ( $categories as $c ) {
    $cid = (int) $c['id'];
    if ( empty( $by_cat[ $cid ] ) ) {
      continue;
    }
    $output[] = [
      'categoryId' => $cid,
      'category'   => $c['name'],
      'services'   => $by_cat[ $cid ],
    ];
  }

  set_transient( $cache_key, $output, 5 * MINUTE_IN_SECONDS );
  wp_send_json_success( $output );
}

function vm_amelia_normalize_team_member_name( $name ) {
  $prefixes = [ 'Dr\.', 'Dra\.', 'Dr', 'Dra', 'Lic\.', 'Lic', 'Mtro\.', 'Mtro' ];
  $name     = preg_replace( '/^(' . implode( '|', $prefixes ) . ')\s*/i', '', (string) $name );
  $name     = trim( preg_replace( '/\s+/', ' ', $name ) );

  if ( function_exists( 'remove_accents' ) ) {
    $name = remove_accents( $name );
  }

  return strtolower( trim( $name ) );
}

function vm_amelia_get_team_member_map() {
  $cache_key = 'vm_amelia_team_member_map_v1';
  $cached    = get_transient( $cache_key );
  if ( is_array( $cached ) ) {
    return $cached;
  }

  $query = new \WP_Query( [
    'post_type'      => 'ctshowcase_member',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
    'no_found_rows'  => true,
  ] );

  $map = [];
  foreach ( $query->get_posts() as $post ) {
    $post_id = (int) $post->ID;
    $key     = vm_amelia_normalize_team_member_name( $post->post_title );

    if ( ! $key ) {
      continue;
    }

    $job_title = get_post_meta( $post_id, 'ctshowcase_job_title', true );
    $excerpt   = has_excerpt( $post_id )
      ? get_the_excerpt( $post_id )
      : wp_trim_words( wp_strip_all_tags( $post->post_content ), 32 );
    $content   = apply_filters( 'the_content', $post->post_content );
    $permalink = get_permalink( $post_id );
    $image     = get_the_post_thumbnail_url( $post_id, 'medium' ) ?: '';

    $map[ $key ] = [
      'id'        => $post_id,
      'image'     => esc_url_raw( $image ),
      'url'       => esc_url_raw( $permalink ),
      'cargo'     => wp_strip_all_tags( (string) $job_title ),
      'resumen'   => wp_strip_all_tags( (string) $excerpt ),
      'contenido' => wp_kses_post( $content ),
      'enlace'    => esc_url_raw( $permalink ),
    ];
  }

  wp_reset_postdata();
  set_transient( $cache_key, $map, 5 * MINUTE_IN_SECONDS );

  return $map;
}

/**
 * AJAX: Obtener doctores (providers) filtrados por servicio desde DB.
 * Params GET: serviceId (opcional)
 * Retorna: [{ id, name, meta, image, teamMember }]
 */
function vm_amelia_get_providers_handler() {
  $service_id = isset( $_GET['serviceId'] ) ? (int) $_GET['serviceId'] : 0;

  $cache_key = 'vm_amelia_providers_available_v2_' . $service_id;
  $cached    = get_transient( $cache_key );
  if ( $cached !== false ) {
    wp_send_json_success( $cached );
    return;
  }

  global $wpdb;
  $t_usr = $wpdb->prefix . 'amelia_users';
  $t_ps  = $wpdb->prefix . 'amelia_providers_to_services';
  $t_svc = $wpdb->prefix . 'amelia_services';

  // Obtener providers (opcionalmente filtrados por servicio)
  if ( $service_id ) {
    $providers = $wpdb->get_results( $wpdb->prepare(
      "SELECT DISTINCT u.id, u.firstName, u.lastName
       FROM {$t_usr} u
       INNER JOIN {$t_ps} ps ON ps.userId = u.id
       WHERE u.status = 'visible' AND u.type = 'provider' AND ps.serviceId = %d
       ORDER BY u.firstName ASC, u.lastName ASC",
      $service_id
    ), ARRAY_A );
  } else {
    $providers = $wpdb->get_results(
      "SELECT id, firstName, lastName FROM {$t_usr}
       WHERE status = 'visible' AND type = 'provider'
       ORDER BY firstName ASC, lastName ASC",
      ARRAY_A
    );
  }

  if ( empty( $providers ) ) {
    wp_send_json_success( [] );
    return;
  }

  $prov_ids = array_map( 'intval', wp_list_pluck( $providers, 'id' ) );
  $in       = implode( ',', $prov_ids );

  // Servicios de cada provider para el meta
  $svc_rows = $wpdb->get_results(
    "SELECT ps.userId, s.name
     FROM {$t_ps} ps
     INNER JOIN {$t_svc} s ON s.id = ps.serviceId
     WHERE ps.userId IN ({$in}) AND s.status = 'visible'
     ORDER BY s.name ASC",
    ARRAY_A
  );

  $meta_map = [];
  foreach ( $svc_rows as $r ) {
    $meta_map[ (int) $r['userId'] ][] = $r['name'];
  }

  $available_provider_ids = $service_id ? vm_amelia_get_available_provider_ids_for_service( $service_id ) : [];
  $team_members           = vm_amelia_get_team_member_map();

  $output = [];
  foreach ( $providers as $p ) {
    $pid  = (int) $p['id'];

    if ( $service_id && ! in_array( $pid, $available_provider_ids, true ) ) {
      continue;
    }

    $name = trim( $p['firstName'] . ' ' . $p['lastName'] );
    $meta = isset( $meta_map[ $pid ] )
      ? implode( ', ', array_unique( $meta_map[ $pid ] ) )
      : '';
    $team_key    = vm_amelia_normalize_team_member_name( $name );
    $team_member = isset( $team_members[ $team_key ] ) ? $team_members[ $team_key ] : [
      'id'        => null,
      'image'     => '',
      'url'       => '',
      'cargo'     => '',
      'resumen'   => '',
      'contenido' => '',
      'enlace'    => '',
    ];
    $specialties = isset( $meta_map[ $pid ] ) ? array_values( array_unique( $meta_map[ $pid ] ) ) : [];

    $output[] = [
      'id'          => $pid,
      'name'        => $name,
      'meta'        => $meta,
      'specialties' => $specialties,
      'image'       => $team_member['image'],
      'imagen'      => $team_member['image'],
      'profileUrl'  => $team_member['url'],
      'teamMember'  => $team_member,
      'team_member' => [
        'id'        => $team_member['id'],
        'cargo'     => $team_member['cargo'],
        'resumen'   => $team_member['resumen'],
        'contenido' => $team_member['contenido'],
        'enlace'    => $team_member['enlace'],
      ],
    ];
  }

  set_transient( $cache_key, $output, 5 * MINUTE_IN_SECONDS );
  wp_send_json_success( $output );
}

/**
 * Helper: construir mapa de horarios semanales para providers de un servicio.
 * Retorna: [ providerId => [ dayIndex => [ [ 'start' => 'HH:MM', 'end' => 'HH:MM' ], ... ] ] ]
 */
function vm_amelia_build_schedule_map( $prov_ids, $service_id ) {
  global $wpdb;
  $prefix = $wpdb->prefix;
  $in     = implode( ',', array_map( 'intval', $prov_ids ) );

  // 1. Horarios base por día de la semana
  $wd_rows = $wpdb->get_results(
    "SELECT id, userId, dayIndex, startTime, endTime
     FROM {$prefix}amelia_providers_to_weekdays
     WHERE userId IN ({$in})",
    ARRAY_A
  );

  $schedule    = [];
  $wd_id_map   = []; // weekDayId => { userId, dayIndex }
  $wd_ids      = [];

  foreach ( $wd_rows as $r ) {
    $uid = (int) $r['userId'];
    $di  = (int) $r['dayIndex'];
    $wid = (int) $r['id'];

    $wd_ids[]          = $wid;
    $wd_id_map[ $wid ] = [ 'userId' => $uid, 'dayIndex' => $di ];

    if ( ! isset( $schedule[ $uid ] ) ) {
      $schedule[ $uid ] = [];
    }
    if ( ! isset( $schedule[ $uid ][ $di ] ) ) {
      $schedule[ $uid ][ $di ] = [];
    }

    // Horario por defecto del weekday
    if ( ! empty( $r['startTime'] ) && ! empty( $r['endTime'] ) ) {
      $schedule[ $uid ][ $di ][] = [
        'start' => substr( $r['startTime'], 0, 5 ),
        'end'   => substr( $r['endTime'], 0, 5 ),
      ];
    }
  }

  // 2. Periodos específicos (overrides)
  if ( ! empty( $wd_ids ) ) {
    $wd_in   = implode( ',', $wd_ids );
    $periods = $wpdb->get_results(
      "SELECT p.id AS periodId, p.weekDayId, p.startTime, p.endTime
       FROM {$prefix}amelia_providers_to_periods p
       WHERE p.weekDayId IN ({$wd_in})",
      ARRAY_A
    );

    if ( ! empty( $periods ) ) {
      // Restricciones de servicio por periodo
      $period_ids = wp_list_pluck( $periods, 'periodId' );
      $p_in       = implode( ',', array_map( 'intval', $period_ids ) );

      $ps_rows = $wpdb->get_results(
        "SELECT periodId, serviceId
         FROM {$prefix}amelia_providers_to_periods_services
         WHERE periodId IN ({$p_in})",
        ARRAY_A
      );

      $period_svc = [];
      foreach ( $ps_rows as $ps ) {
        $period_svc[ (int) $ps['periodId'] ][] = (int) $ps['serviceId'];
      }

      // Agrupar periodos por weekDayId
      $periods_by_wd = [];
      foreach ( $periods as $p ) {
        $pid_key = (int) $p['periodId'];
        // Si el periodo tiene restricción de servicio y no aplica a nuestro servicio, omitir
        if ( isset( $period_svc[ $pid_key ] ) && ! in_array( $service_id, $period_svc[ $pid_key ], true ) ) {
          continue;
        }
        $wdId = (int) $p['weekDayId'];
        $periods_by_wd[ $wdId ][] = [
          'start' => substr( $p['startTime'], 0, 5 ),
          'end'   => substr( $p['endTime'], 0, 5 ),
        ];
      }

      // Los periodos reemplazan el horario por defecto del weekday
      foreach ( $periods_by_wd as $wdId => $period_list ) {
        if ( ! isset( $wd_id_map[ $wdId ] ) ) {
          continue;
        }
        $uid = $wd_id_map[ $wdId ]['userId'];
        $di  = $wd_id_map[ $wdId ]['dayIndex'];
        $schedule[ $uid ][ $di ] = $period_list;
      }
    }
  }

  return $schedule;
}

function vm_amelia_availability_window_dates() {
  $tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( date_default_timezone_get() );
  $start = new \DateTimeImmutable( 'today', $tz );
  $end   = $start->modify( '+3 months' );

  return [ $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ];
}

function vm_amelia_get_slots_data( $service_id, $provider_id = 0, $start_date = '', $end_date = '', $duration = 0 ) {
  global $wpdb;
  $prefix  = $wpdb->prefix;
  $is_auto = empty( $provider_id );

  $now_ts      = current_time( 'timestamp' );
  $today_str   = date( 'Y-m-d', $now_ts );
  $now_minutes = (int) date( 'H', $now_ts ) * 60 + (int) date( 'i', $now_ts );

  if ( empty( $start_date ) ) {
    $start_date = $today_str;
  }

  if ( $start_date < $today_str ) {
    $start_date = $today_str;
  }

  if ( empty( $end_date ) ) {
    $end_date = date( 'Y-m-t', strtotime( $start_date ) );
  }

  if ( $end_date < $start_date ) {
    $end_date = $start_date;
  }

  // Duración del servicio (en segundos) — convertir a minutos
  if ( ! $duration ) {
    $duration = (int) $wpdb->get_var( $wpdb->prepare(
      "SELECT duration FROM {$prefix}amelia_services WHERE id = %d",
      $service_id
    ) );
    if ( ! $duration ) {
      $duration = 3600;
    }
  }
  $duration_min = intval( $duration / 60 );

  // Buffers del servicio (timeBefore / timeAfter en segundos)
  $svc_info = $wpdb->get_row( $wpdb->prepare(
    "SELECT timeBefore, timeAfter FROM {$prefix}amelia_services WHERE id = %d",
    $service_id
  ), ARRAY_A );
  $time_before_min = $svc_info ? intval( (int) $svc_info['timeBefore'] / 60 ) : 0;
  $time_after_min  = $svc_info ? intval( (int) $svc_info['timeAfter'] / 60 ) : 0;

  // 1. Obtener providers para este servicio
  if ( $is_auto ) {
    $prov_ids = $wpdb->get_col( $wpdb->prepare(
      "SELECT DISTINCT ps.userId
       FROM {$prefix}amelia_providers_to_services ps
       INNER JOIN {$prefix}amelia_users u ON u.id = ps.userId
       WHERE ps.serviceId = %d AND u.status = 'visible' AND u.type = 'provider'",
      $service_id
    ) );
    $prov_ids = array_map( 'intval', $prov_ids );
  } else {
    $prov_ids = [ (int) $provider_id ];
  }

  if ( empty( $prov_ids ) ) {
    return [ 'slots' => (object) [], 'occupied' => (object) [] ];
  }

  $prov_in = implode( ',', $prov_ids );

  // 2. Horarios semanales
  $schedule = vm_amelia_build_schedule_map( $prov_ids, $service_id );

  // 3. Días libres
  $daysoff_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT userId, startDate, endDate
     FROM {$prefix}amelia_providers_to_daysoff
     WHERE userId IN ({$prov_in}) AND endDate >= %s",
    $start_date
  ), ARRAY_A );

  $daysoff = [];
  foreach ( $daysoff_rows as $d ) {
    $daysoff[ (int) $d['userId'] ][] = [ $d['startDate'], $d['endDate'] ];
  }

  // 4. Citas existentes (todos los servicios para evitar doble booking).
  // Amelia guarda el bloque horario en appointments y el estado real de la
  // reserva del paciente en customer_bookings.
  $appts = $wpdb->get_results( $wpdb->prepare(
    "SELECT DISTINCT
       a.providerId,
       a.bookingStart,
       a.bookingEnd,
       a.status AS appointmentStatus,
       cb.status AS bookingStatus,
       cb.duration AS bookingDuration
     FROM {$prefix}amelia_appointments a
     LEFT JOIN {$prefix}amelia_customer_bookings cb ON cb.appointmentId = a.id
     WHERE a.providerId IN ({$prov_in})
       AND a.status IN ('approved', 'pending')
       AND a.bookingStart <= %s
       AND (
         a.bookingEnd >= %s
         OR a.bookingEnd IS NULL
         OR a.bookingEnd = ''
         OR a.bookingEnd = '0000-00-00 00:00:00'
       )
       AND (
         cb.id IS NULL
         OR cb.status IN ('approved', 'pending')
       )",
    $end_date . ' 23:59:59',
    $start_date . ' 00:00:00'
  ), ARRAY_A );

  $appt_map = [];
  foreach ( $appts as $a ) {
    if ( empty( $a['bookingStart'] ) ) {
      continue;
    }

    $pid      = (int) $a['providerId'];
    $dk       = substr( $a['bookingStart'], 0, 10 );
    $s_h      = (int) substr( $a['bookingStart'], 11, 2 );
    $s_m      = (int) substr( $a['bookingStart'], 14, 2 );
    $start_ts = strtotime( $a['bookingStart'] );
    $end_ts   = ! empty( $a['bookingEnd'] ) ? strtotime( $a['bookingEnd'] ) : false;

    if ( ! $start_ts ) {
      continue;
    }

    if ( ! $end_ts || $end_ts <= $start_ts ) {
      $booking_duration = ! empty( $a['bookingDuration'] ) ? (int) $a['bookingDuration'] : 0;
      $fallback_seconds = $booking_duration > 0 ? $booking_duration : (int) $duration;
      $end_ts           = $start_ts + max( 60, $fallback_seconds );
    }

    $duration_minutes = max( 1, (int) ceil( ( $end_ts - $start_ts ) / 60 ) );
    $start_minutes    = $s_h * 60 + $s_m;
    $end_minutes      = $start_minutes + $duration_minutes;

    $appt_map[ $pid ][ $dk ][] = [
      $start_minutes - $time_before_min,
      $end_minutes + $time_after_min,
    ];
  }

  // 5. Generar slots
  $free         = [];
  $occupied     = [];
  $provider_map = [];

  $tz      = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( date_default_timezone_get() );
  $current = new \DateTime( $start_date, $tz );
  $end_dt  = new \DateTime( $end_date, $tz );

  while ( $current <= $end_dt ) {
    $dk       = $current->format( 'Y-m-d' );
    $day_idx  = (int) $current->format( 'N' ); // 1=Lunes ... 7=Domingo
    $is_today = ( $dk === $today_str );

    $free_times    = [];
    $occ_times     = [];
    $prov_for_time = [];

    foreach ( $prov_ids as $pid ) {
      if ( isset( $daysoff[ $pid ] ) ) {
        $skip = false;
        foreach ( $daysoff[ $pid ] as $off ) {
          if ( $dk >= $off[0] && $dk <= $off[1] ) {
            $skip = true;
            break;
          }
        }
        if ( $skip ) {
          continue;
        }
      }

      if ( empty( $schedule[ $pid ][ $day_idx ] ) ) {
        continue;
      }

      foreach ( $schedule[ $pid ][ $day_idx ] as $period ) {
        $p_start = (int) substr( $period['start'], 0, 2 ) * 60 + (int) substr( $period['start'], 3, 2 );
        $p_end   = (int) substr( $period['end'], 0, 2 ) * 60 + (int) substr( $period['end'], 3, 2 );

        for ( $t = $p_start; $t + $duration_min <= $p_end; $t += $duration_min ) {
          if ( $is_today && $t <= $now_minutes ) {
            continue;
          }

          $time_str = sprintf( '%02d:%02d', intval( $t / 60 ), $t % 60 );
          $slot_end = $t + $duration_min;

          $conflict = false;
          if ( isset( $appt_map[ $pid ][ $dk ] ) ) {
            foreach ( $appt_map[ $pid ][ $dk ] as $ap ) {
              if ( $t < $ap[1] && $slot_end > $ap[0] ) {
                $conflict = true;
                break;
              }
            }
          }

          if ( $conflict ) {
            if ( ! in_array( $time_str, $occ_times, true ) ) {
              $occ_times[] = $time_str;
            }
            continue;
          }

          if ( ! in_array( $time_str, $free_times, true ) ) {
            $free_times[] = $time_str;
          }

          if ( $is_auto ) {
            if ( ! isset( $prov_for_time[ $time_str ] ) ) {
              $prov_for_time[ $time_str ] = [];
            }
            $prov_for_time[ $time_str ][] = $pid;
          }
        }
      }
    }

    $occ_times = array_values( array_diff( $occ_times, $free_times ) );

    sort( $free_times );
    sort( $occ_times );

    if ( ! empty( $free_times ) ) {
      $free[ $dk ] = $free_times;
      if ( $is_auto && ! empty( $prov_for_time ) ) {
        $provider_map[ $dk ] = $prov_for_time;
      }
    }

    if ( ! empty( $occ_times ) ) {
      $occupied[ $dk ] = $occ_times;
    }

    $current->modify( '+1 day' );
  }

  $response = [
    'slots'    => ! empty( $free ) ? $free : (object) [],
    'occupied' => ! empty( $occupied ) ? $occupied : (object) [],
  ];

  if ( $is_auto && ! empty( $provider_map ) ) {
    $response['providerMap'] = $provider_map;
  }

  return $response;
}

function vm_amelia_service_has_availability( $service_id, $provider_id = 0 ) {
  if ( empty( $provider_id ) ) {
    return ! empty( vm_amelia_get_available_provider_ids_for_service( $service_id ) );
  }

  [ $start_date, $end_date ] = vm_amelia_availability_window_dates();

  $cache_key = 'vm_amelia_has_availability_v2_' . md5( wp_json_encode( [
    'service'  => (int) $service_id,
    'provider' => (int) $provider_id,
    'start'    => $start_date,
    'end'      => $end_date,
  ] ) );
  $cached = get_transient( $cache_key );
  if ( $cached !== false ) {
    return (bool) $cached;
  }

  $slots = vm_amelia_get_slots_data( (int) $service_id, (int) $provider_id, $start_date, $end_date );
  $has   = ! empty( (array) $slots['slots'] );

  set_transient( $cache_key, $has ? 1 : 0, 5 * MINUTE_IN_SECONDS );
  return $has;
}

function vm_amelia_get_available_provider_ids_for_service( $service_id ) {
  [ $start_date, $end_date ] = vm_amelia_availability_window_dates();

  $cache_key = 'vm_amelia_available_provider_ids_v2_' . md5( wp_json_encode( [
    'service' => (int) $service_id,
    'start'   => $start_date,
    'end'     => $end_date,
  ] ) );
  $cached = get_transient( $cache_key );
  if ( $cached !== false ) {
    return array_map( 'intval', (array) $cached );
  }

  $slots        = vm_amelia_get_slots_data( (int) $service_id, 0, $start_date, $end_date );
  $provider_ids = [];

  if ( ! empty( $slots['providerMap'] ) && is_array( $slots['providerMap'] ) ) {
    foreach ( $slots['providerMap'] as $times ) {
      if ( ! is_array( $times ) ) {
        continue;
      }
      foreach ( $times as $ids ) {
        if ( ! is_array( $ids ) ) {
          continue;
        }
        foreach ( $ids as $id ) {
          $provider_ids[] = (int) $id;
        }
      }
    }
  }

  $provider_ids = array_values( array_unique( $provider_ids ) );
  set_transient( $cache_key, $provider_ids, 5 * MINUTE_IN_SECONDS );

  return $provider_ids;
}

function vm_amelia_get_booking_slot_parts( $booking_data ) {
  $service_id    = isset( $booking_data['serviceId'] ) ? (int) $booking_data['serviceId'] : 0;
  $provider_id   = isset( $booking_data['providerId'] ) ? (int) $booking_data['providerId'] : 0;
  $booking_start = isset( $booking_data['bookingStart'] ) ? (string) $booking_data['bookingStart'] : '';
  $date          = substr( $booking_start, 0, 10 );
  $time          = substr( $booking_start, 11, 5 );
  $duration      = 0;

  if ( ! empty( $booking_data['bookings'][0]['duration'] ) ) {
    $duration = (int) $booking_data['bookings'][0]['duration'];
  }

  return [
    'serviceId'  => $service_id,
    'providerId' => $provider_id,
    'date'       => $date,
    'time'       => $time,
    'duration'   => $duration,
  ];
}

function vm_amelia_validate_booking_slot( $booking_data, $doctor_mode = 'manual' ) {
  if ( empty( $booking_data ) || ! is_array( $booking_data ) ) {
    return [ 'success' => false, 'message' => 'Payload de Amelia inválido' ];
  }

  $parts       = vm_amelia_get_booking_slot_parts( $booking_data );
  $service_id  = $parts['serviceId'];
  $provider_id = $parts['providerId'];
  $date        = $parts['date'];
  $time        = $parts['time'];
  $duration    = $parts['duration'];
  $is_auto     = ( $doctor_mode === 'auto' );

  if ( ! $service_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
    return [ 'success' => false, 'message' => 'Horario de consulta inválido' ];
  }

  if ( $is_auto ) {
    $availability = vm_amelia_get_slots_data( $service_id, 0, $date, $date, $duration );
    $provider_map = isset( $availability['providerMap'] ) && is_array( $availability['providerMap'] )
      ? $availability['providerMap']
      : [];
    $providers    = isset( $provider_map[ $date ][ $time ] ) ? $provider_map[ $date ][ $time ] : [];
    $providers    = is_array( $providers ) ? array_map( 'intval', $providers ) : [];

    if ( empty( $providers ) ) {
      return [ 'success' => false, 'message' => 'El horario seleccionado ya no está disponible' ];
    }

    if ( $provider_id > 0 && in_array( $provider_id, $providers, true ) ) {
      return [ 'success' => true, 'booking_data' => $booking_data ];
    }

    $booking_data['providerId'] = (int) $providers[0];

    return [ 'success' => true, 'booking_data' => $booking_data ];
  }

  if ( ! $provider_id ) {
    return [ 'success' => false, 'message' => 'Selecciona un doctor para validar el horario' ];
  }

  $availability = vm_amelia_get_slots_data( $service_id, $provider_id, $date, $date, $duration );
  $slot_map     = isset( $availability['slots'] ) && is_array( $availability['slots'] )
    ? $availability['slots']
    : [];
  $slots        = isset( $slot_map[ $date ] ) ? $slot_map[ $date ] : [];

  if ( ! is_array( $slots ) || ! in_array( $time, $slots, true ) ) {
    return [ 'success' => false, 'message' => 'El horario seleccionado ya no está disponible' ];
  }

  return [ 'success' => true, 'booking_data' => $booking_data ];
}

/**
 * AJAX: Obtener slots de disponibilidad desde DB.
 * Params GET: serviceId (requerido), date (YYYY-MM-DD), providerId, duration
 * Retorna: { slots: { "2026-03-10": ["09:00",...] }, occupied: {...}, providerMap: {...} }
 */
function vm_amelia_get_slots_handler() {
  $service_id  = isset( $_GET['serviceId'] ) ? (int) $_GET['serviceId'] : 0;
  $provider_id = isset( $_GET['providerId'] ) ? (int) $_GET['providerId'] : 0;
  $date        = isset( $_GET['date'] ) ? sanitize_text_field( $_GET['date'] ) : '';
  $end_date    = isset( $_GET['endDate'] ) ? sanitize_text_field( $_GET['endDate'] ) : '';
  $duration    = isset( $_GET['duration'] ) ? (int) $_GET['duration'] : 0;

  if ( ! $service_id ) {
    wp_send_json_error( [ 'message' => 'serviceId es requerido' ] );
    return;
  }

  // Rango de fechas
  if ( $date ) {
    $start_date = $date;
    if ( empty( $end_date ) ) {
      $end_date = date( 'Y-m-t', strtotime( $date ) );
    }
  } else {
    $start_date = current_time( 'Y-m-d' );
    if ( empty( $end_date ) ) {
      $end_date = date( 'Y-m-t', current_time( 'timestamp' ) );
    }
  }

  $response = vm_amelia_get_slots_data( $service_id, $provider_id, $start_date, $end_date, $duration );

  nocache_headers();
  wp_send_json_success( $response );
}
