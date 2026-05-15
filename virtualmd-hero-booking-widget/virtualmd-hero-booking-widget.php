<?php
/**
 * Plugin Name: VirtualMD Hero Booking Widget
 * Description: Widget de agendamiento, pagos y notificaciones de VirtualMD mediante shortcode.
 * Version: 1.0.17
 * Author: VirtualMD
 */

namespace VirtualMD\HeroBooking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VMHB_PLUGIN_FILE', __FILE__ );
define( 'VMHB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VMHB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VMHB_VERSION', '1.0.17' );

require_once VMHB_PLUGIN_DIR . 'includes/amelia-notifications.php';
require_once VMHB_PLUGIN_DIR . 'includes/amelia-availability.php';
require_once VMHB_PLUGIN_DIR . 'includes/paypal-checkout.php';
require_once VMHB_PLUGIN_DIR . 'includes/package-purchases.php';
require_once VMHB_PLUGIN_DIR . 'includes/stripe-checkout.php';
require_once VMHB_PLUGIN_DIR . 'includes/stripe-verify.php';

function register_shortcodes() {
    add_shortcode( 'virtualmd_hero_booking', __NAMESPACE__ . '\\render_hero_booking_shortcode' );
    add_shortcode( 'vm_hero_booking', __NAMESPACE__ . '\\render_hero_booking_shortcode' );
}
add_action( 'init', __NAMESPACE__ . '\\register_shortcodes' );

function enqueue_widget_assets() {
    wp_enqueue_style(
        'vmhb-hero-booking',
        VMHB_PLUGIN_URL . 'assets/css/hero-booking.css',
        [],
        VMHB_VERSION
    );

    wp_enqueue_script(
        'vmhb-hero-booking',
        VMHB_PLUGIN_URL . 'assets/js/hero-booking.js',
        [],
        VMHB_VERSION,
        true
    );

    wp_localize_script( 'vmhb-hero-booking', 'VMHB_CONFIG', [
        'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
        'stripePublicKey' => defined( 'STRIPE_PUBLIC_KEY' ) ? STRIPE_PUBLIC_KEY : '',
        'actions'       => [
            'stripeCreateSession' => 'vmhb_stripe_create_session',
            'stripeVerifyPayment' => 'vmhb_stripe_verify_payment',
            'stripeCreatePackageSession' => 'vmhb_stripe_create_package_session',
            'stripeVerifyPackagePayment' => 'vmhb_stripe_verify_package_payment',
            'paypalCreateOrder'   => 'vmhb_paypal_create_order',
            'paypalCaptureOrder'  => 'vmhb_paypal_capture_order',
            'paypalCreatePackageOrder' => 'vmhb_paypal_create_package_order',
            'paypalCapturePackageOrder' => 'vmhb_paypal_capture_package_order',
            'completeFreePackagePurchase' => 'vmhb_complete_free_package_purchase',
            'catalog'             => 'vmhb_amelia_get_catalog',
            'packages'            => 'vmhb_amelia_get_packages',
            'validatePackageCoupon'=> 'vmhb_amelia_validate_package_coupon',
            'validateAppointmentCoupon'=> 'vmhb_amelia_validate_appointment_coupon',
            'completeFreeAppointmentBooking'=> 'vmhb_complete_free_appointment_booking',
            'providers'           => 'vmhb_amelia_get_providers',
            'slots'               => 'vmhb_amelia_get_slots',
        ],
    ] );

    if ( defined( 'STRIPE_PUBLIC_KEY' ) && STRIPE_PUBLIC_KEY ) {
        wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, true );
        wp_localize_script( 'stripe-js', 'vmStripeConfig', [
            'publicKey' => STRIPE_PUBLIC_KEY,
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
        ] );
    }

    if ( defined( 'PAYPAL_CLIENT_ID' ) && PAYPAL_CLIENT_ID ) {
        wp_enqueue_script(
            'paypal-sdk',
            'https://www.paypal.com/sdk/js?client-id=' . rawurlencode( PAYPAL_CLIENT_ID ) . '&currency=MXN&intent=capture',
            [],
            null,
            true
        );
    }
}

function render_hero_booking_shortcode( $atts = [] ) {
    enqueue_widget_assets();

    ob_start();
    if ( ! wp_style_is( 'vmhb-hero-booking', 'done' ) ) {
        wp_print_styles( [ 'vmhb-hero-booking' ] );
    }

    include VMHB_PLUGIN_DIR . 'templates/hero-widget.php';
    return ob_get_clean();
}

function register_ajax_handlers() {
    $handlers = [
        'vmhb_amelia_get_catalog'          => 'vm_amelia_get_catalog_handler',
        'vmhb_amelia_get_packages'         => 'vm_amelia_get_packages_handler',
        'vmhb_amelia_get_providers'        => 'vm_amelia_get_providers_handler',
        'vmhb_amelia_get_slots'            => 'vm_amelia_get_slots_handler',
        'vmhb_paypal_create_order'         => 'vm_paypal_create_order_handler',
        'vmhb_paypal_capture_order'        => 'vm_paypal_capture_order_handler',
        'vmhb_paypal_create_package_order' => 'vm_paypal_create_package_order_handler',
        'vmhb_paypal_capture_package_order'=> 'vm_paypal_capture_package_order_handler',
        'vmhb_complete_free_package_purchase'=> 'vm_package_complete_free_purchase_handler',
        'vmhb_paypal_process_async_booking'=> 'vm_paypal_process_async_booking_handler',
        'vmhb_stripe_create_session'       => 'vm_stripe_create_session_handler',
        'vmhb_stripe_verify_payment'       => 'vm_stripe_verify_payment_handler',
        'vmhb_stripe_create_package_session'=> 'vm_stripe_create_package_session_handler',
        'vmhb_stripe_verify_package_payment'=> 'vm_stripe_verify_package_payment_handler',
        'vmhb_amelia_validate_package_coupon'=> 'vm_amelia_validate_package_coupon_handler',
        'vmhb_amelia_validate_appointment_coupon'=> 'vm_amelia_validate_appointment_coupon_handler',
        'vmhb_complete_free_appointment_booking'=> 'vm_appointment_complete_free_booking_handler',
    ];

    foreach ( $handlers as $action => $callback ) {
        add_action( 'wp_ajax_' . $action, __NAMESPACE__ . '\\' . $callback );
        add_action( 'wp_ajax_nopriv_' . $action, __NAMESPACE__ . '\\' . $callback );
    }

    add_action( 'vmhb_paypal_process_async_booking_event', __NAMESPACE__ . '\\vm_paypal_process_async_booking_event_handler', 10, 2 );
}
add_action( 'init', __NAMESPACE__ . '\\register_ajax_handlers' );
