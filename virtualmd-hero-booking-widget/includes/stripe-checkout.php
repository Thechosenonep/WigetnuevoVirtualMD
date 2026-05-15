<?php
namespace VirtualMD\HeroBooking;

/**
 * Stripe Checkout Session Creator
 *
 * AJAX endpoint que crea una Stripe Checkout Session embebida
 * para cobrar el monto del servicio seleccionado en Amelia.
 *
 * Usa price_data dinámico (no Price IDs fijos) para que funcione
 * con cualquier servicio/precio de Amelia.
 *
 * @package VirtualMD
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handler AJAX: Crear Stripe Checkout Session.
 *
 * Recibe (POST JSON):
 *   - serviceName:  string  – Nombre del servicio
 *   - servicePrice: float   – Precio en MXN
 *   - customerEmail: string – Email del cliente
 *   - bookingData:  object  – Payload completo de Amelia para crear la cita después del pago
 *
 * Retorna:
 *   - clientSecret: string  – Client secret para Stripe Checkout embebido
 */
function vm_stripe_create_session_handler() {
    // Solo POST
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Método no permitido' ] );
        return;
    }

    // Verificar que las claves de Stripe estén definidas
    if ( ! defined( 'STRIPE_SECRET_KEY' ) || ! STRIPE_SECRET_KEY ) {
        wp_send_json_error( [ 'message' => 'STRIPE_SECRET_KEY no está definida en wp-config.php' ] );
        return;
    }

    // Cargar librería Stripe
    $vendor_path = VMHB_PLUGIN_DIR . 'stripe/vendor/autoload.php';
    if ( ! file_exists( $vendor_path ) ) {
        wp_send_json_error( [ 'message' => 'Librería Stripe no instalada en el plugin.' ] );
        return;
    }
    require_once $vendor_path;

    // Obtener datos del request
    $raw_body = file_get_contents( 'php://input' );
    $data     = json_decode( $raw_body, true );

    if ( empty( $data ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos' ] );
        return;
    }

    $service_name   = isset( $data['serviceName'] )   ? sanitize_text_field( $data['serviceName'] )   : '';
    $service_price  = isset( $data['servicePrice'] )   ? floatval( $data['servicePrice'] )             : 0;
    $customer_email = isset( $data['customerEmail'] )  ? sanitize_email( $data['customerEmail'] )       : '';
    $booking_data   = isset( $data['bookingData'] )    ? $data['bookingData']                            : [];
    $doctor_mode    = isset( $data['doctorMode'] ) && $data['doctorMode'] === 'auto' ? 'auto' : 'manual';
    $coupon_code    = isset( $data['couponCode'] ) ? sanitize_text_field( $data['couponCode'] ) : '';
    $service_id     = isset( $data['serviceId'] ) ? (int) $data['serviceId'] : ( isset( $booking_data['serviceId'] ) ? (int) $booking_data['serviceId'] : 0 );

    // Validaciones
    if ( empty( $service_name ) || $service_price <= 0 ) {
        wp_send_json_error( [ 'message' => 'Servicio o precio inválido' ] );
        return;
    }

    if ( empty( $customer_email ) || ! is_email( $customer_email ) ) {
        wp_send_json_error( [ 'message' => 'Email inválido' ] );
        return;
    }

    if ( empty( $booking_data ) || ! is_array( $booking_data ) ) {
        wp_send_json_error( [ 'message' => 'Datos de booking incompletos. No se creó la sesión de pago.' ] );
        return;
    }

    $pricing = function_exists( __NAMESPACE__ . '\\vm_appointment_build_pricing' )
        ? vm_appointment_build_pricing( $service_price )
        : [ 'total' => $service_price ];

    if ( $coupon_code !== '' ) {
        if ( ! function_exists( __NAMESPACE__ . '\\vm_appointment_validate_coupon_for_service' ) ) {
            wp_send_json_error( [ 'message' => 'No se pudo validar el cupón.' ] );
            return;
        }

        $coupon_result = vm_appointment_validate_coupon_for_service( $service_id, $service_price, $coupon_code, $customer_email );

        if ( empty( $coupon_result['success'] ) ) {
            wp_send_json_error( [ 'message' => $coupon_result['message'] ] );
            return;
        }

        $pricing = $coupon_result['pricing'];
        $booking_data = vm_appointment_apply_coupon_to_booking_data( $booking_data, $pricing );
    }

    if ( (float) $pricing['total'] <= 0 ) {
        wp_send_json_error( [ 'message' => 'Esta consulta no requiere pago. Confírmala con el botón de cupón.' ] );
        return;
    }

    $availability_result = function_exists( __NAMESPACE__ . '\\vm_paypal_ensure_provider_for_booking' )
        ? vm_paypal_ensure_provider_for_booking( $booking_data, $doctor_mode )
        : vm_amelia_validate_booking_slot( $booking_data, $doctor_mode );

    if ( empty( $availability_result['success'] ) ) {
        wp_send_json_error( [
            'message' => isset( $availability_result['message'] ) ? $availability_result['message'] : 'El horario seleccionado ya no está disponible',
        ] );
        return;
    }

    $booking_data = $availability_result['booking_data'];

    try {
        $stripe = new \Stripe\StripeClient( STRIPE_SECRET_KEY );

        // Convertir precio a centavos (Stripe trabaja en la unidad más pequeña)
        $amount_cents = intval( round( (float) $pricing['total'] * 100 ) );

        // Determinar la URL de retorno (usar la URL de la página actual enviada desde el frontend)
        $page_url = isset( $data['pageUrl'] ) ? esc_url_raw( $data['pageUrl'] ) : home_url( '/' );
        // Limpiar query strings existentes de la pageUrl
        $page_url = strtok( $page_url, '?' );
        $return_url = $page_url . '?stripe_return=1&session_id={CHECKOUT_SESSION_ID}';

        // Crear Checkout Session embebida
        $checkout_session = $stripe->checkout->sessions->create( [
            'ui_mode'                      => 'embedded_page',
            // TODO: Descomentar con sk_live_. Esta config es de modo live y falla con sk_test_.
            // 'payment_method_configuration' => 'pmc_1Rb6vZHPJjI8uN9YWC7gaznI',
            'mode'                         => 'payment',
            'customer_email'               => $customer_email,
            'line_items'     => [ [
                'price_data' => [
                    'currency'     => 'mxn',
                    'unit_amount'  => $amount_cents,
                    'product_data' => [
                        'name'        => $service_name,
                        'description' => 'Consulta médica en línea - VirtualMD',
                    ],
                ],
                'quantity' => 1,
            ] ],
            'metadata' => [
                'source'     => 'virtualmd_widget',
                'couponId'   => ! empty( $pricing['couponId'] ) ? (string) $pricing['couponId'] : '',
                'couponCode' => ! empty( $pricing['couponCode'] ) ? (string) $pricing['couponCode'] : '',
                'total'      => (string) $pricing['total'],
            ],
            'return_url' => $return_url,
        ] );

        // Guardar booking data en transient de WordPress (sin límite de tamaño)
        // El transient expira en 2 horas
        set_transient(
            'vm_stripe_booking_' . $checkout_session->id,
            [
                'bookingData' => $booking_data,
                'doctorMode'  => $doctor_mode,
                'pricing'     => $pricing,
            ],
            2 * HOUR_IN_SECONDS
        );

        wp_send_json_success( [
            'clientSecret' => $checkout_session->client_secret,
            'sessionId'    => $checkout_session->id,
        ] );

    } catch ( \Stripe\Exception\ApiErrorException $e ) {
        wp_send_json_error( [
            'message' => 'Error de Stripe: ' . $e->getMessage(),
        ] );
    } catch ( \Exception $e ) {
        wp_send_json_error( [
            'message' => 'Error inesperado: ' . $e->getMessage(),
        ] );
    }
}
