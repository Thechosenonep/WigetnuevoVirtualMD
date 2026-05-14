<?php
namespace VirtualMD\HeroBooking;

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if (!function_exists(__NAMESPACE__ . '\\vm_amelia_is_generated_customer_email')) {
  function vm_amelia_is_generated_customer_email($email) {
    $email = strtolower(trim((string) $email));

    if ($email === '') {
      return false;
    }

    return (bool) preg_match('/(^|[<\s,;])(?:amelia-placeholder|paciente)?\+?vm[a-f0-9]{8,12}@/i', $email)
      || (bool) preg_match('/\+vm[a-f0-9]{8,12}@/i', $email);
  }
}

if (!function_exists(__NAMESPACE__ . '\\vm_amelia_normalize_phone_for_delivery')) {
  function vm_amelia_normalize_phone_for_delivery($phone) {
    $phone = trim((string) $phone);

    if ($phone === '') {
      return '';
    }

    if (strpos($phone, '+') === 0) {
      $digits = preg_replace('/\D+/', '', $phone);
      return $digits !== '' ? '+' . $digits : $phone;
    }

    $digits = preg_replace('/\D+/', '', $phone);

    if ($digits === '') {
      return $phone;
    }

    if (strpos($digits, '00') === 0 && strlen($digits) > 4) {
      return '+' . substr($digits, 2);
    }

    if (strlen($digits) === 10) {
      return '+52' . $digits;
    }

    if (strlen($digits) >= 11 && strlen($digits) <= 15) {
      return '+' . $digits;
    }

    return $phone;
  }
}

if (!function_exists(__NAMESPACE__ . '\\vm_amelia_country_iso_for_phone')) {
  function vm_amelia_country_iso_for_phone($phone) {
    $phone = vm_amelia_normalize_phone_for_delivery($phone);
    $prefix_map = [
      '+502' => 'gt',
      '+503' => 'sv',
      '+504' => 'hn',
      '+505' => 'ni',
      '+506' => 'cr',
      '+507' => 'pa',
      '+52'  => 'mx',
      '+57'  => 'co',
      '+51'  => 'pe',
      '+56'  => 'cl',
      '+54'  => 'ar',
      '+55'  => 'br',
      '+34'  => 'es',
      '+44'  => 'gb',
      '+33'  => 'fr',
      '+49'  => 'de',
      '+1'   => 'us',
    ];

    foreach ($prefix_map as $prefix => $iso) {
      if (strpos($phone, $prefix) === 0) {
        return $iso;
      }
    }

    return '';
  }
}

if (!function_exists(__NAMESPACE__ . '\\vm_amelia_get_form_context_by_token')) {
  function vm_amelia_get_form_context_by_token($token) {
    $token = sanitize_text_field((string) $token);

    if (!$token) {
      return null;
    }

    $stored = get_transient('vm_amelia_form_context_' . $token);

    return is_array($stored) && !empty($stored['customer']) && is_array($stored['customer'])
      ? $stored
      : null;
  }
}

if (!function_exists(__NAMESPACE__ . '\\vm_amelia_extract_form_context_markers')) {
  function vm_amelia_extract_form_context_markers($source) {
    if (is_array($source) || is_object($source)) {
      $source = wp_json_encode($source);
    }

    $source = (string) $source;
    $markers = [];

    if ($source === '') {
      return [];
    }

    if (preg_match_all('/\+vm([a-f0-9]{8,12})@/i', $source, $matches)) {
      $markers = array_merge($markers, $matches[1]);
    }

    if (preg_match_all('/VM-([a-f0-9]{8,12})/i', $source, $matches)) {
      $markers = array_merge($markers, $matches[1]);
    }

    return array_values(array_unique(array_map('strtolower', $markers)));
  }
}

if (!function_exists(__NAMESPACE__ . '\\vm_amelia_get_form_context_from_sources')) {
  function vm_amelia_get_form_context_from_sources($sources = []) {
    $context = vm_amelia_get_form_context_from_request();

    if (!empty($context['customer']) && is_array($context['customer'])) {
      return $context;
    }

    foreach ((array) $sources as $source) {
      foreach (vm_amelia_extract_form_context_markers($source) as $marker) {
        $token = get_transient('vm_amelia_form_context_marker_' . $marker);
        $context = vm_amelia_get_form_context_by_token($token);

        if (!empty($context['customer']) && is_array($context['customer'])) {
          return $context;
        }
      }
    }

    return null;
  }
}

if (!function_exists(__NAMESPACE__ . '\\vm_amelia_get_form_context_from_request')) {
  function vm_amelia_get_form_context_from_request() {
    static $context_loaded = false;
    static $context = null;

    if ($context_loaded) {
      return $context;
    }

    $context_loaded = true;
    $token = isset($_SERVER['HTTP_X_VM_AMELIA_FORM_TOKEN'])
      ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_VM_AMELIA_FORM_TOKEN']))
      : '';

    if (!$token) {
      return null;
    }

    $context = vm_amelia_get_form_context_by_token($token);

    return $context;
  }
}

if (!function_exists(__NAMESPACE__ . '\\vm_amelia_notification_text_contains')) {
  function vm_amelia_notification_text_contains($source, $needles) {
    if (is_array($source) || is_object($source)) {
      $source = wp_json_encode($source);
    }

    $source = strtolower(html_entity_decode(wp_strip_all_tags((string) $source), ENT_QUOTES, 'UTF-8'));

    foreach ((array) $needles as $needle) {
      if ($needle !== '' && strpos($source, strtolower($needle)) !== false) {
        return true;
      }
    }

    return false;
  }

  function vm_amelia_notification_identity_sources($data, $notificationType = null) {
    $sources = [
      $notificationType,
      isset($data['sendTo']) ? $data['sendTo'] : '',
      isset($data['send_to']) ? $data['send_to'] : '',
      isset($data['recipientType']) ? $data['recipientType'] : '',
      isset($data['recipient_type']) ? $data['recipient_type'] : '',
      isset($data['notificationName']) ? $data['notificationName'] : '',
      isset($data['notification_name']) ? $data['notification_name'] : '',
      isset($data['name']) ? $data['name'] : '',
      isset($data['event']) ? $data['event'] : '',
      isset($data['template']) ? $data['template'] : '',
      isset($data['notification']) ? $data['notification'] : '',
    ];

    return $sources;
  }

  function vm_amelia_is_customer_delivery_notification($data, $notificationType = null) {
    $sources = vm_amelia_notification_identity_sources($data, $notificationType);

    if (vm_amelia_notification_text_contains($sources, [
      'employee',
      'provider',
      'doctor',
      'admin',
      'staff',
      'usuario',
      'empleado',
      'especialista',
    ])) {
      return false;
    }

    if (vm_amelia_notification_text_contains($sources, [
      'customer',
      'client',
      'cliente',
      'patient',
      'paciente',
    ])) {
      return true;
    }

    $to = isset($data['to']) ? $data['to'] : [];
    $to = is_array($to) ? $to : array_map('trim', explode(',', (string) $to));

    foreach ($to as $recipient) {
      if (vm_amelia_is_generated_customer_email($recipient)) {
        return true;
      }
    }

    return false;
  }

  function vm_amelia_wp_mail_is_generated_user_notice($args) {
    $subject = isset($args['subject']) ? (string) $args['subject'] : '';
    $message = isset($args['message']) ? (string) $args['message'] : '';
    $haystack = $subject . "\n" . $message;

    if (!vm_amelia_notification_text_contains($haystack, [
      'registrado un nuevo usuario',
      'new user registration',
      'nuevo usuario',
      'nombre de usuario:',
      'username:',
    ])) {
      return false;
    }

    return vm_amelia_is_generated_customer_email($haystack)
      || (bool) preg_match('/amelia-placeholder\+?vm[a-f0-9]{8,12}@virtualmd\.mx/i', $haystack);
  }

  function vm_amelia_wp_mail_targets_generated_customer($args) {
    $to = isset($args['to']) ? $args['to'] : [];
    $to = is_array($to) ? $to : array_map('trim', explode(',', (string) $to));

    foreach ($to as $recipient) {
      if (vm_amelia_is_generated_customer_email($recipient)) {
        return true;
      }
    }

    return false;
  }
}

if (!function_exists(__NAMESPACE__ . '\\vm_amelia_apply_form_context_to_notification')) {
  function vm_amelia_notification_value_contains($value, $needles) {
    if (is_array($value) || is_object($value)) {
      $value = wp_json_encode($value);
    }

    $value = strtolower((string) $value);

    foreach ((array) $needles as $needle) {
      if ($needle !== '' && strpos($value, strtolower($needle)) !== false) {
        return true;
      }
    }

    return false;
  }

  function vm_amelia_is_email_notification_context($data, $notificationType = null) {
    $type_sources = [
      $notificationType,
      isset($data['type']) ? $data['type'] : '',
      isset($data['channel']) ? $data['channel'] : '',
      isset($data['medium']) ? $data['medium'] : '',
      isset($data['notificationType']) ? $data['notificationType'] : '',
      isset($data['notification']['type']) ? $data['notification']['type'] : '',
    ];

    if (vm_amelia_notification_value_contains($type_sources, ['whatsapp', 'sms'])) {
      return false;
    }

    if (vm_amelia_notification_value_contains($type_sources, ['email', 'mail'])) {
      return true;
    }

    if (!isset($data['to'])) {
      return false;
    }

    $to = is_array($data['to']) ? implode(',', $data['to']) : (string) $data['to'];

    return strpos($to, '@') !== false;
  }

  function vm_amelia_replace_technical_customer_text($text, $context, $real_customer) {
    if (!is_string($text) || $text === '' || empty($context['technicalCustomer']) || !is_array($context['technicalCustomer'])) {
      return $text;
    }

    $technical = $context['technicalCustomer'];
    $technical_first_name = isset($technical['firstName']) ? trim((string) $technical['firstName']) : '';
    $technical_last_name  = isset($technical['lastName']) ? trim((string) $technical['lastName']) : '';
    $technical_full_name  = trim($technical_first_name . ' ' . $technical_last_name);
    $technical_email      = isset($technical['email']) ? sanitize_email($technical['email']) : '';
    $technical_phone      = isset($technical['phone']) ? sanitize_text_field($technical['phone']) : '';

    $real_first_name = isset($real_customer['firstName']) ? trim((string) $real_customer['firstName']) : '';
    $real_last_name  = isset($real_customer['lastName']) ? trim((string) $real_customer['lastName']) : '';
    $real_full_name  = trim($real_first_name . ' ' . $real_last_name);
    $real_email      = isset($real_customer['email']) ? sanitize_email($real_customer['email']) : '';
    $real_phone      = isset($real_customer['phone']) ? sanitize_text_field($real_customer['phone']) : '';

    $replacements = [];

    if ($technical_full_name !== '' && $real_full_name !== '') {
      $replacements[$technical_full_name] = $real_full_name;
    }

    if ($technical_last_name !== '' && $real_last_name !== '') {
      $replacements[$technical_last_name] = $real_last_name;
    }

    if ($technical_email !== '' && $real_email !== '') {
      $replacements[$technical_email] = $real_email;
    }

    if ($technical_phone !== '' && $real_phone !== '') {
      $replacements[$technical_phone] = $real_phone;
    }

    return $replacements ? strtr($text, $replacements) : $text;
  }

  function vm_amelia_replace_technical_customer_values_recursive($value, $context, $real_customer) {
    if (is_array($value)) {
      foreach ($value as $key => $item) {
        $value[$key] = vm_amelia_replace_technical_customer_values_recursive($item, $context, $real_customer);
      }

      return $value;
    }

    if (is_string($value)) {
      return vm_amelia_replace_technical_customer_text($value, $context, $real_customer);
    }

    return $value;
  }

  function vm_amelia_apply_form_context_to_notification($data, $notificationType = null) {
    $context = vm_amelia_get_form_context_from_sources([$data]);
    if (empty($context['customer']) || !is_array($context['customer'])) {
      return $data;
    }

    $customer = $context['customer'];
    $first_name = isset($customer['firstName']) ? trim((string) $customer['firstName']) : '';
    $last_name  = isset($customer['lastName']) ? trim((string) $customer['lastName']) : '';
    $full_name  = trim($first_name . ' ' . $last_name);
    $email      = isset($customer['email']) ? sanitize_email($customer['email']) : '';
    $phone      = isset($customer['phone']) ? sanitize_text_field($customer['phone']) : '';
    $country_phone_iso = isset($customer['countryPhoneIso']) ? strtolower(sanitize_text_field($customer['countryPhoneIso'])) : '';
    $delivery_phone = vm_amelia_normalize_phone_for_delivery($phone);
    $is_email_notification = vm_amelia_is_email_notification_context($data, $notificationType);
    $is_customer_notification = vm_amelia_is_customer_delivery_notification($data, $notificationType);

    if (!isset($data['placeholders']) || !is_array($data['placeholders'])) {
      $data['placeholders'] = [];
    }

    $placeholder_values = [
      '%customer_first_name%' => $first_name,
      '%customer_last_name%'  => $last_name,
      '%customer_full_name%'  => $full_name,
      '%customer_name%'       => $full_name,
      '%customer_email%'      => $email,
      '%customer_email_address%' => $email,
      '%customer_phone%'      => $is_email_notification ? $phone : $delivery_phone,
      '%customer_phone_number%' => $is_email_notification ? $phone : $delivery_phone,
      '%customer_phone_num%'  => $is_email_notification ? $phone : $delivery_phone,
    ];

    foreach ($placeholder_values as $placeholder => $value) {
      if ($value !== '') {
        $data['placeholders'][$placeholder] = $value;
      }
    }

    $form_customer = [
      'firstName' => $first_name,
      'lastName'  => $last_name,
      'email'     => $email,
      'phone'     => $is_email_notification ? $phone : $delivery_phone,
      'countryPhoneIso' => $country_phone_iso,
    ];

    if (!isset($data['customer']) || !is_array($data['customer'])) {
      $data['customer'] = [];
    }
    $data['customer'] = array_merge($data['customer'], array_filter($form_customer, function($value) {
      return $value !== '';
    }));

    if (isset($data['appointment']['bookings']) && is_array($data['appointment']['bookings'])) {
      foreach ($data['appointment']['bookings'] as $index => $booking) {
        if (!isset($data['appointment']['bookings'][$index]['customer']) || !is_array($data['appointment']['bookings'][$index]['customer'])) {
          $data['appointment']['bookings'][$index]['customer'] = [];
        }
        $data['appointment']['bookings'][$index]['customer'] = array_merge(
          $data['appointment']['bookings'][$index]['customer'],
          array_filter($form_customer, function($value) {
            return $value !== '';
          })
        );
      }
    }

    if (!$is_email_notification && $delivery_phone && isset($data['to'])) {
      $data['to'] = is_array($data['to'])
        ? array_map(function($recipient) use ($delivery_phone) {
          $recipient = trim((string) $recipient);
          return strpos($recipient, '@') === false ? $delivery_phone : $recipient;
        }, $data['to'])
        : $delivery_phone;
    }

    if ($delivery_phone) {
      foreach (['phone', 'customerPhone', 'customer_phone', 'recipientPhone', 'recipient_phone', 'phoneNumber', 'phone_number'] as $phone_key) {
        if (array_key_exists($phone_key, $data)) {
          $data[$phone_key] = $is_email_notification ? $phone : $delivery_phone;
        }
      }
    }

    if ($country_phone_iso) {
      foreach (['countryPhoneIso', 'country_phone_iso'] as $country_key) {
        if (array_key_exists($country_key, $data)) {
          $data[$country_key] = $country_phone_iso;
        }
      }
    }

    $data = vm_amelia_replace_technical_customer_values_recursive($data, $context, $form_customer);

    if ($is_email_notification && $is_customer_notification && $email && isset($data['to'])) {
      $data['to'] = [$email];
    }

    error_log('[Amelia Filter] Datos de notificación reemplazados con formulario del hero widget.');

    return $data;
  }
}

/**
 * Hook principal: amelia_before_notification_sent
 * Este hook se ejecuta ANTES de enviar cada notificación
 */
add_filter('amelia_before_notification_sent', function ($data, $notificationType = null) {
  error_log('[Amelia Filter] === FILTRO EJECUTADO === Tipo: ' . print_r($notificationType, true));
  error_log('[Amelia Filter] Data estructura: ' . print_r(array_keys($data), true));

  $is_email_notification = vm_amelia_is_email_notification_context($data, $notificationType);
  $is_customer_notification = vm_amelia_is_customer_delivery_notification($data, $notificationType);
  $data = vm_amelia_apply_form_context_to_notification($data, $notificationType);

  $bookingEmail = null;

  // 1. Intentar extraer el email correcto de múltiples fuentes

  // Opción A: desde placeholders
  if (isset($data['placeholders']['%customer_email%'])) {
    $bookingEmail = trim($data['placeholders']['%customer_email%']);
    error_log('[Amelia Filter] Email encontrado en placeholders: ' . $bookingEmail);
  }

  // Opción B: desde bookings array
  if (!$bookingEmail && isset($data['appointment']['bookings']) && is_array($data['appointment']['bookings'])) {
    foreach ($data['appointment']['bookings'] as $bk) {
      if (!empty($bk['customer']['email'])) {
        $bookingEmail = trim($bk['customer']['email']);
        error_log('[Amelia Filter] Email encontrado en bookings: ' . $bookingEmail);
        break;
      }
    }
  }

  // Opción C: desde customer directo
  if (!$bookingEmail && isset($data['customer']['email'])) {
    $bookingEmail = trim($data['customer']['email']);
    error_log('[Amelia Filter] Email encontrado en customer: ' . $bookingEmail);
  }

  // 2. Procesar destinatarios
  if ($is_email_notification && $is_customer_notification && isset($data['to'])) {
    $to_original = $data['to'];
    error_log('[Amelia Filter] Destinatarios ORIGINALES: ' . print_r($to_original, true));

    // Normalizar a array
    $to = is_array($data['to']) ? $data['to'] : array_map('trim', explode(',', $data['to']));

    if ($bookingEmail) {
      // FILTRAR: en la notificación de cliente sólo debe ir el correo del paciente actual.
      $to = array_filter($to, function($email) use ($bookingEmail) {
        $email = trim($email);

        // Mantener el email de la reserva actual
        if (strcasecmp($email, $bookingEmail) === 0) {
          error_log('[Amelia Filter] ✓ Manteniendo email de reserva: ' . $email);
          return true;
        }

        // Descartar cualquier otro email: doctor, admin, clientes viejos o placeholders.
        error_log('[Amelia Filter] ✗ REMOVIENDO destinatario no-cliente: ' . $email);
        return false;
      });

      // Asegurar que el email actual esté incluido
      if (!in_array($bookingEmail, $to, true)) {
        $to[] = $bookingEmail;
        error_log('[Amelia Filter] + Agregando email de reserva manualmente: ' . $bookingEmail);
      }
    }

    // Limpiar y actualizar
    $data['to'] = array_values(array_unique($to));
    error_log('[Amelia Filter] Destinatarios FINALES: ' . print_r($data['to'], true));
  } else {
    error_log('[Amelia Filter] No es notificación email de cliente; no se fuerzan destinatarios.');
  }

  return $data;
}, 999, 2);

/**
 * Hook alternativo: amelia_manipulate_email_data
 * Por si el hook anterior no funciona en tu versión de Amelia
 */
add_filter('amelia_manipulate_email_data', function ($data) {
  error_log('[Amelia Filter ALT] === FILTRO ALTERNATIVO EJECUTADO ===');
  return vm_amelia_apply_form_context_to_notification($data, 'email');
}, 999, 1);

add_filter('pre_wp_mail', function ($return, $args) {
  if (vm_amelia_wp_mail_is_generated_user_notice($args)) {
    error_log('[Amelia Filter wp_mail] Aviso de usuario tecnico Amelia bloqueado.');
    return true;
  }

  return $return;
}, 10, 2);

add_filter('wp_mail', function ($args) {
  if (vm_amelia_wp_mail_is_generated_user_notice($args)) {
    return $args;
  }

  $context = vm_amelia_get_form_context_from_sources([$args]);
  if (empty($context['customer']) || empty($context['customer']['email'])) {
    return $args;
  }

  $form_email = sanitize_email($context['customer']['email']);
  if (!$form_email) {
    return $args;
  }

  if (!vm_amelia_wp_mail_targets_generated_customer($args)) {
    return $args;
  }

  $to = isset($args['to']) ? $args['to'] : [];
  $to = is_array($to) ? $to : array_map('trim', explode(',', $to));

  $args['to'] = [$form_email];

  if (!empty($args['message']) && is_string($args['message'])) {
    $customer = $context['customer'];
    $first_name = isset($customer['firstName']) ? trim((string) $customer['firstName']) : '';
    $last_name  = isset($customer['lastName']) ? trim((string) $customer['lastName']) : '';
    $full_name  = trim($first_name . ' ' . $last_name);
    $phone      = isset($customer['phone']) ? sanitize_text_field($customer['phone']) : '';

    $replacements = [
      '%customer_first_name%' => $first_name,
      '%customer_last_name%'  => $last_name,
      '%customer_full_name%'  => $full_name,
      '%customer_name%'       => $full_name,
      '%customer_email%'      => $form_email,
      '%customer_email_address%' => $form_email,
      '%customer_phone%'      => $phone,
      '%customer_phone_number%' => $phone,
      '%customer_phone_num%'  => $phone,
    ];

    $args['message'] = strtr($args['message'], array_filter($replacements, function($value) {
      return $value !== '';
    }));

    $args['message'] = vm_amelia_replace_technical_customer_text($args['message'], $context, $customer);
  }

  if (!empty($args['subject']) && is_string($args['subject'])) {
    $args['subject'] = vm_amelia_replace_technical_customer_text($args['subject'], $context, $context['customer']);
  }

  error_log('[Amelia Filter wp_mail] Destinatarios forzados al correo del formulario.');

  return $args;
}, 999);
