<?php
namespace VirtualMD\HeroBooking;

/**
 * Stripe Payment Verification & Amelia Booking Creator
 *
 * AJAX endpoint que verifica el estado de un pago de Stripe
 * y si es exitoso, crea la cita en Amelia usando los datos
 * guardados en la metadata de la Checkout Session.
 *
 * @package VirtualMD
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handler AJAX: Verificar pago de Stripe y crear cita en Amelia.
 *
 * Recibe (POST JSON):
 *   - session_id: string – ID de la Checkout Session de Stripe
 *
 * Retorna:
 *   - status:         string – 'complete', 'open', 'expired'
 *   - customer_email: string – Email del cliente (si complete)
 *   - booking_result: object – Resultado de la creación de cita en Amelia (si complete)
 */
function vm_stripe_verify_payment_handler()
{
    // Solo POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wp_send_json_error(['message' => 'Método no permitido']);
        return;
    }

    // Verificar que las claves de Stripe estén definidas
    if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
        wp_send_json_error(['message' => 'STRIPE_SECRET_KEY no está definida en wp-config.php']);
        return;
    }

    // Cargar librería Stripe
    $vendor_path = VMHB_PLUGIN_DIR . 'stripe/vendor/autoload.php';
    if (!file_exists($vendor_path)) {
        wp_send_json_error(['message' => 'Librería Stripe no instalada en el plugin']);
        return;
    }
    require_once $vendor_path;

    // Obtener datos del request
    $raw_body = file_get_contents('php://input');
    $data = json_decode($raw_body, true);

    $session_id = isset($data['session_id']) ? sanitize_text_field($data['session_id']) : '';

    if (empty($session_id)) {
        wp_send_json_error(['message' => 'session_id es requerido']);
        return;
    }

    try {
        $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
        $session = $stripe->checkout->sessions->retrieve($session_id);

        $response = [
            'status' => $session->status,
            'payment_status' => $session->payment_status,
            'customer_email' => $session->customer_details ? $session->customer_details->email : '',
        ];

        // Si el pago está completo, crear la cita en Amelia
        if ($session->status === 'complete' && $session->payment_status === 'paid') {
            // Leer booking data del transient de WordPress
            $transient_key = 'vm_stripe_booking_' . $session_id;
            $stored_data = get_transient($transient_key);
            $booking_data = [];
            $doctor_mode = 'manual';

            if (is_array($stored_data) && isset($stored_data['bookingData']) && is_array($stored_data['bookingData'])) {
                $booking_data = $stored_data['bookingData'];
                $doctor_mode = isset($stored_data['doctorMode']) && $stored_data['doctorMode'] === 'auto' ? 'auto' : 'manual';
            } elseif (is_array($stored_data)) {
                // Compatibilidad con sesiones creadas antes de guardar metadata.
                $booking_data = $stored_data;
            }

            if (!empty($booking_data) && is_array($booking_data)) {
                // Mantener gateway como 'onSite' para que Amelia lo acepte
                // Agregar info de Stripe en las notas internas
                $stripe_note = 'Pago Stripe: ' . $session->payment_intent . ' | Monto: $' . ($session->amount_total / 100) . ' ' . strtoupper($session->currency);
                if (!empty($booking_data['internalNotes'])) {
                    $booking_data['internalNotes'] .= "\n" . $stripe_note;
                } else {
                    $booking_data['internalNotes'] = $stripe_note;
                }

                // Crear la cita en Amelia via la API interna
                $amelia_result = vm_stripe_create_amelia_booking($booking_data, $doctor_mode);
                $response['booking_result'] = $amelia_result;

                // Eliminar transient después de usarlo (evitar doble booking)
                delete_transient($transient_key);
            } else {
                $response['booking_result'] = [
                    'success' => false,
                    'message' => 'Datos de booking no encontrados o expirados',
                ];
            }
        }

        wp_send_json_success($response);

    } catch (\Stripe\Exception\ApiErrorException $e) {
        wp_send_json_error([
            'message' => 'Error de Stripe: ' . $e->getMessage(),
        ]);
    } catch (\Exception $e) {
        wp_send_json_error([
            'message' => 'Error inesperado: ' . $e->getMessage(),
        ]);
    }
}

/**
 * Crear cita en Amelia vía API interna.
 * Usa la misma lógica que vm_amelia_book_appointment_handler() pero de forma interna.
 *
 * @param array $booking_data Payload de Amelia para crear la cita.
 * @return array Resultado de la creación.
 */
function vm_stripe_create_amelia_booking($booking_data, $doctor_mode = '')
{
    // Verificar que la API key de Amelia esté definida
    if (!defined('AMELIA_API_KEY') || !AMELIA_API_KEY) {
        return ['success' => false, 'message' => 'AMELIA_API_KEY no definida'];
    }

    $provider_result = function_exists(__NAMESPACE__ . '\\vm_paypal_ensure_provider_for_booking')
        ? vm_paypal_ensure_provider_for_booking($booking_data, $doctor_mode)
        : vm_amelia_validate_booking_slot($booking_data, $doctor_mode === 'auto' ? 'auto' : 'manual');

    if (empty($provider_result['success'])) {
        return [
            'success' => false,
            'message' => $provider_result['message'],
        ];
    }

    $booking_data = $provider_result['booking_data'];

    $form_context_token = vm_amelia_store_form_context_for_booking($booking_data);

    $booking_data = vm_amelia_append_form_customer_to_internal_notes($booking_data);

    $customer_result = vm_paypal_prepare_customer_for_booking($booking_data, $form_context_token);

    if (empty($customer_result['success'])) {
        return [
            'success' => false,
            'message' => $customer_result['message'],
        ];
    }

    $booking_data = $customer_result['booking_data'];
    vm_amelia_update_form_context_technical_customer($form_context_token, $booking_data);

    $booking_data['notifyParticipants'] = 1;
    $booking_data['runInstantPostBookingActions'] = false;

    // Construir URL para la API interna de Amelia
    $base = admin_url('admin-ajax.php');
    $url = add_query_arg([
        'action' => 'wpamelia_api',
        'call' => '/api/v1/bookings',
    ], $base);

    $headers = [
        'Content-Type' => 'application/json',
        'Amelia' => AMELIA_API_KEY,
    ];

    if ($form_context_token) {
        $headers['X-VM-Amelia-Form-Token'] = $form_context_token;
    }

    // Llamada HTTP
    $response = wp_remote_request($url, [
        'method' => 'POST',
        'timeout' => 15,
        'headers' => $headers,
        'body' => wp_json_encode($booking_data),
        'sslverify' => false,
        'cookies' => $_COOKIE,
    ]);

    if (is_wp_error($response)) {
        return [
            'success' => false,
            'message' => 'Error de conexión interna: ' . $response->get_error_message(),
        ];
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body_res = wp_remote_retrieve_body($response);
    $decoded = json_decode($body_res, true);

    // --- LOGICA DE REINTENTO DE EMERGENCIA ---
    if (isset($decoded['data']['message']) && strpos($decoded['data']['message'], 'different email or phone number') !== false) {
        $token = substr(md5(uniqid('vm_retry_', true)), 0, 10);

        foreach ($booking_data['bookings'] as $index => $booking) {
            $booking_data['bookings'][$index]['customerId'] = null;
            if (isset($booking_data['bookings'][$index]['customer'])) {
                $booking_data['bookings'][$index]['customer']['id'] = null;
                $booking_data['bookings'][$index]['customer']['email'] = 'amelia-placeholder+vm' . $token . '@virtualmd.mx';
                $booking_data['bookings'][$index]['customer']['phone'] = '999' . substr(str_pad((string) hexdec(substr($token, 0, 7)), 7, '0', STR_PAD_LEFT), -7);
                $last_name = $booking_data['bookings'][$index]['customer']['lastName'] ?? '';
                $booking_data['bookings'][$index]['customer']['lastName'] = trim($last_name . ' VM-' . strtoupper(substr($token, 0, 8)));
            }
        }

        vm_amelia_update_form_context_technical_customer($form_context_token, $booking_data);

        vm_paypal_log('Amelia rechazó por colisión de email (Stripe). Reintentando con identidad ofuscada.', [
            'newPayload' => $booking_data
        ]);

        $response = wp_remote_request($url, [
            'method' => 'POST',
            'timeout' => 15,
            'headers' => $headers,
            'body' => wp_json_encode($booking_data),
            'sslverify' => false,
            'cookies' => $_COOKIE,
        ]);

        if (!is_wp_error($response)) {
            $status_code = wp_remote_retrieve_response_code($response);
            $body_res = wp_remote_retrieve_body($response);
            $decoded = json_decode($body_res, true);
        }
    }
    // --- FIN LOGICA DE REINTENTO ---

    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'message' => 'Respuesta de Amelia no es JSON válido. HTTP ' . $status_code,
        ];
    }

    if (isset($decoded['message']) && $status_code >= 200 && $status_code < 300) {
        $post_booking_actions = null;
        $customer_update = null;
        $manual_whatsapp = null;

        $success_payload = vm_amelia_extract_success_payload_from_booking_response($decoded);

        if (!empty($success_payload['success'])) {
            $customer_update = vm_amelia_update_created_customer_with_form_data(
                $success_payload['payload']['customerId'],
                $form_context_token
            );

            $post_booking_actions = vm_amelia_run_booking_success_actions(
                $success_payload['booking_id'],
                $success_payload['payload'],
                $form_context_token
            );

            $manual_whatsapp = vm_amelia_send_manual_whatsapp_confirmation(
                $booking_data,
                $success_payload,
                $form_context_token
            );
        } else {
            $post_booking_actions = $success_payload;
        }

        return [
            'success' => true,
            'data' => $decoded,
            'customer_update' => $customer_update,
            'manual_whatsapp' => $manual_whatsapp,
            'post_booking_actions' => $post_booking_actions,
        ];
    }

    return [
        'success' => false,
        'message' => isset($decoded['message']) ? $decoded['message'] : 'Error al crear la cita',
        'amelia' => $decoded,
    ];
}
