<?php
namespace VirtualMD\EmpresasVirtual\Portal;

use function VirtualMD\EmpresasVirtual\create_amelia_booking;
use function VirtualMD\EmpresasVirtual\current_company;
use function VirtualMD\EmpresasVirtual\get_slots_data;
use function VirtualMD\EmpresasVirtual\is_company_user;
use function VirtualMD\EmpresasVirtual\money;
use function VirtualMD\EmpresasVirtual\product_price_for_quantity;
use function VirtualMD\EmpresasVirtual\product_with_items;
use function VirtualMD\EmpresasVirtual\table_name;
use function VirtualMD\EmpresasVirtual\validate_slot;
use function VirtualMD\EmpresasVirtual\amelia_provider_name;
use function VirtualMD\EmpresasVirtual\available_consultations_count;
use function VirtualMD\EmpresasVirtual\clear_company_session;
use function VirtualMD\EmpresasVirtual\company_belongs_to_admin;
use function VirtualMD\EmpresasVirtual\set_company_session;
use function VirtualMD\EmpresasVirtual\insert_consultation_inventory;
use function VirtualMD\EmpresasVirtual\is_company_admin_account;
use function VirtualMD\EmpresasVirtual\return_unused_consultations;
use function VirtualMD\EmpresasVirtual\return_unused_consultations_for_company;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function init() {
    add_shortcode( 'vempresas_virtual_portal', __NAMESPACE__ . '\\shortcode' );
    add_action( 'admin_post_vev_login', __NAMESPACE__ . '\\login' );
    add_action( 'admin_post_nopriv_vev_login', __NAMESPACE__ . '\\login' );
    add_action( 'admin_post_vev_logout', __NAMESPACE__ . '\\logout' );
    add_action( 'admin_post_nopriv_vev_logout', __NAMESPACE__ . '\\logout' );
    add_action( 'admin_post_vev_start_purchase', __NAMESPACE__ . '\\start_purchase' );
    add_action( 'admin_post_nopriv_vev_start_purchase', __NAMESPACE__ . '\\start_purchase' );
    add_action( 'wp_ajax_vev_stripe_create_session', __NAMESPACE__ . '\\ajax_stripe_create_session' );
    add_action( 'wp_ajax_nopriv_vev_stripe_create_session', __NAMESPACE__ . '\\ajax_stripe_create_session' );
    add_action( 'wp_ajax_vev_paypal_create_order', __NAMESPACE__ . '\\ajax_paypal_create_order' );
    add_action( 'wp_ajax_nopriv_vev_paypal_create_order', __NAMESPACE__ . '\\ajax_paypal_create_order' );
    add_action( 'wp_ajax_vev_paypal_capture_order', __NAMESPACE__ . '\\ajax_paypal_capture_order' );
    add_action( 'wp_ajax_nopriv_vev_paypal_capture_order', __NAMESPACE__ . '\\ajax_paypal_capture_order' );
    add_action( 'wp_ajax_vev_get_slots', __NAMESPACE__ . '\\ajax_get_slots' );
    add_action( 'wp_ajax_nopriv_vev_get_slots', __NAMESPACE__ . '\\ajax_get_slots' );
    add_action( 'wp_ajax_vev_get_providers', __NAMESPACE__ . '\\ajax_get_providers' );
    add_action( 'wp_ajax_nopriv_vev_get_providers', __NAMESPACE__ . '\\ajax_get_providers' );
    add_action( 'wp_ajax_vev_schedule_bulk', __NAMESPACE__ . '\\ajax_schedule_bulk' );
    add_action( 'wp_ajax_nopriv_vev_schedule_bulk', __NAMESPACE__ . '\\ajax_schedule_bulk' );
    add_action( 'admin_post_vev_team_create_user', __NAMESPACE__ . '\\team_create_user' );
    add_action( 'admin_post_nopriv_vev_team_create_user', __NAMESPACE__ . '\\team_create_user' );
    add_action( 'admin_post_vev_team_assign_consultations', __NAMESPACE__ . '\\team_assign_consultations' );
    add_action( 'admin_post_nopriv_vev_team_assign_consultations', __NAMESPACE__ . '\\team_assign_consultations' );
    add_action( 'admin_post_vev_team_deassign_consultations', __NAMESPACE__ . '\\team_deassign_consultations' );
    add_action( 'admin_post_nopriv_vev_team_deassign_consultations', __NAMESPACE__ . '\\team_deassign_consultations' );
    add_action( 'admin_post_vev_team_user_status', __NAMESPACE__ . '\\team_user_status' );
    add_action( 'admin_post_nopriv_vev_team_user_status', __NAMESPACE__ . '\\team_user_status' );
}

function portal_url() {
    $url = wp_get_referer();
    if ( ! $url ) {
        $url = home_url( add_query_arg( [], $GLOBALS['wp']->request ?? '' ) );
    }

    return remove_query_arg( [ 'vev_stripe_return', 'session_id' ], $url );
}

function login() {
    check_admin_referer( 'vev_login' );

    global $wpdb;

    $email    = sanitize_email( $_POST['email'] ?? '' );
    $password = (string) ( $_POST['password'] ?? '' );
    $redirect = wp_get_referer() ?: home_url( '/' );

    $company = $email ? $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'companies' ) . ' WHERE email = %s AND status = %s',
        $email,
        'active'
    ), ARRAY_A ) : null;

    if ( ! $company || empty( $company['password_hash'] ) || ! wp_check_password( $password, $company['password_hash'] ) ) {
        $redirect = add_query_arg( 'vev_error', rawurlencode( 'Usuario o contraseña incorrectos.' ), $redirect );
    } else {
        set_company_session( (int) $company['id'] );
        $redirect = remove_query_arg( 'vev_error', $redirect );
    }

    wp_safe_redirect( $redirect );
    exit;
}

function logout() {
    check_admin_referer( 'vev_logout' );
    clear_company_session();

    $redirect = ! empty( $_GET['redirect'] ) ? esc_url_raw( wp_unslash( $_GET['redirect'] ) ) : home_url( '/' );
    wp_safe_redirect( $redirect );
    exit;
}

function stripe_vendor_path() {
    $local = VEV_PLUGIN_DIR . 'stripe/vendor/autoload.php';
    if ( file_exists( $local ) ) {
        return $local;
    }

    $shared = dirname( VEV_PLUGIN_DIR ) . '/virtualmd-hero-booking-widget/stripe/vendor/autoload.php';
    return file_exists( $shared ) ? $shared : '';
}

function clean_portal_url( $url = '' ) {
    if ( ! $url ) {
        foreach ( [ 'portal_url', 'redirect' ] as $key ) {
            if ( ! empty( $_REQUEST[ $key ] ) ) {
                $url = esc_url_raw( wp_unslash( $_REQUEST[ $key ] ) );
                break;
            }
        }
    }

    $url = $url ? esc_url_raw( $url ) : '';
    if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
        $url = wp_get_referer() ?: home_url( '/' );
    }

    if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
        $url = home_url( '/' );
    }

    return remove_query_arg( [ 'vev_error', 'vev_message', 'vev_stripe_return', 'session_id' ], $url );
}

function redirect_to_portal( $message = '', $error = '' ) {
    $url = clean_portal_url();
    $panel = ! empty( $_REQUEST['portal_panel'] ) ? sanitize_key( wp_unslash( $_REQUEST['portal_panel'] ) ) : '';

    if ( $message ) {
        $url = add_query_arg( 'vev_message', rawurlencode( $message ), $url );
    }

    if ( $error ) {
        $url = add_query_arg( 'vev_error', rawurlencode( $error ), $url );
    }

    if ( $panel ) {
        $url = add_query_arg( 'vev_panel', $panel, $url );
    }

    wp_safe_redirect( $url );
    exit;
}

function current_portal_admin_or_redirect() {
    $company = current_company();

    if ( ! $company || ! is_company_admin_account( $company ) ) {
        redirect_to_portal( '', 'Esta acción sólo está disponible para cuentas admin.' );
    }

    return $company;
}

function company_is_active_child_of( $child_id, $admin_id ) {
    return company_belongs_to_admin( (int) $child_id, (int) $admin_id );
}

function purchase_payload_for_product( $product, $quantity ) {
    $quantity = max( 1, (int) $quantity );
    $price = product_price_for_quantity( $product, $quantity );
    $payload_items = [];
    $validity_days = max( 0, (int) ( $product['validity_days'] ?? 0 ) );

    foreach ( $product['items'] as $item ) {
        $payload_items[] = [
            'service_id'   => (int) $item['service_id'],
            'service_name' => $item['service_name'],
            'quantity'     => (int) $item['quantity'] * $quantity,
            'validity_days'=> $validity_days,
        ];
    }

    return [
        'price'   => $price,
        'payload' => [
            'product' => [
                'id'       => (int) $product['id'],
                'name'     => $product['name'],
                'type'     => $product['type'],
                'discount' => $price['discount'],
                'validity_days' => $validity_days,
            ],
            'items'   => $payload_items,
        ],
    ];
}

function company_can_buy_product( $company_id, $product_id ) {
    global $wpdb;

    $assigned_count = (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT COUNT(*) FROM ' . table_name( 'product_companies' ) . ' WHERE product_id = %d',
        (int) $product_id
    ) );

    if ( ! $assigned_count ) {
        return true;
    }

    return (bool) $wpdb->get_var( $wpdb->prepare(
        'SELECT id FROM ' . table_name( 'product_companies' ) . ' WHERE product_id = %d AND company_id = %d LIMIT 1',
        (int) $product_id,
        (int) $company_id
    ) );
}

function normalize_cart_items_from_request( $source, $company ) {
    $raw_items = [];

    if ( ! empty( $source['items'] ) && is_array( $source['items'] ) ) {
        $raw_items = $source['items'];
    } elseif ( ! empty( $source['product_id'] ) ) {
        $raw_items[] = [
            'product_id' => $source['product_id'],
            'quantity'   => $source['quantity'] ?? 1,
        ];
    }

    if ( empty( $raw_items ) ) {
        return new \WP_Error( 'empty_cart', 'Agrega al menos un producto al carrito.' );
    }

    $merged = [];
    foreach ( $raw_items as $item ) {
        if ( ! is_array( $item ) ) {
            continue;
        }

        $product_id = (int) ( $item['product_id'] ?? $item['productId'] ?? 0 );
        $quantity   = max( 1, (int) ( $item['quantity'] ?? 1 ) );

        if ( ! $product_id ) {
            continue;
        }

        if ( ! isset( $merged[ $product_id ] ) ) {
            $merged[ $product_id ] = 0;
        }
        $merged[ $product_id ] += $quantity;
    }

    if ( empty( $merged ) ) {
        return new \WP_Error( 'empty_cart', 'Agrega al menos un producto al carrito.' );
    }

    $cart_items = [];
    foreach ( $merged as $product_id => $quantity ) {
        $product = product_with_items( $product_id );

        if ( ! $product || ! $product['active'] || empty( $product['items'] ) ) {
            return new \WP_Error( 'invalid_product', 'Uno de los productos del carrito ya no está disponible.' );
        }

        if ( ! company_can_buy_product( (int) $company['id'], $product_id ) ) {
            return new \WP_Error( 'product_not_assigned', 'Tu empresa no tiene asignado uno de los productos del carrito.' );
        }

        $price = product_price_for_quantity( $product, $quantity );
        if ( $price['total'] <= 0 ) {
            return new \WP_Error( 'invalid_price', 'Uno de los productos tiene precio inválido.' );
        }

        $cart_items[] = [
            'product'  => $product,
            'quantity' => $quantity,
            'price'    => $price,
        ];
    }

    return $cart_items;
}

function purchase_payload_for_cart_items( $cart_items ) {
    $total = 0;
    $products = [];
    $service_items = [];

    foreach ( $cart_items as $cart_item ) {
        $product  = $cart_item['product'];
        $quantity = max( 1, (int) $cart_item['quantity'] );
        $price    = $cart_item['price'];
        $validity_days = max( 0, (int) ( $product['validity_days'] ?? 0 ) );
        $total   += (float) $price['total'];

        $line_items = [];
        foreach ( $product['items'] as $item ) {
            $service_id = (int) $item['service_id'];
            $service_quantity = (int) $item['quantity'] * $quantity;
            $service_key = $service_id . '|' . $validity_days;

            $line_items[] = [
                'service_id'   => $service_id,
                'service_name' => $item['service_name'],
                'quantity'     => $service_quantity,
                'validity_days'=> $validity_days,
            ];

            if ( ! isset( $service_items[ $service_key ] ) ) {
                $service_items[ $service_key ] = [
                    'service_id'   => $service_id,
                    'service_name' => $item['service_name'],
                    'quantity'     => 0,
                    'validity_days'=> $validity_days,
                ];
            }

            $service_items[ $service_key ]['quantity'] += $service_quantity;
        }

        $products[] = [
            'id'       => (int) $product['id'],
            'name'     => $product['name'],
            'type'     => $product['type'],
            'quantity' => $quantity,
            'discount' => $price['discount'],
            'total'    => $price['total'],
            'validity_days' => $validity_days,
            'items'    => $line_items,
        ];
    }

    return [
        'price'   => [
            'unit_price' => 0,
            'discount'   => 0,
            'total'      => max( 0, $total ),
        ],
        'payload' => [
            'products' => $products,
            'items'    => array_values( $service_items ),
        ],
    ];
}

function create_pending_cart_purchase( $company, $cart_items, $gateway = '' ) {
    global $wpdb;

    $purchase_data = purchase_payload_for_cart_items( $cart_items );
    $price = $purchase_data['price'];

    if ( $price['total'] <= 0 ) {
        return new \WP_Error( 'invalid_price', 'Precio inválido.' );
    }

    $first_product_id = count( $cart_items ) === 1 ? (int) $cart_items[0]['product']['id'] : 0;
    $total_quantity = 0;
    foreach ( $cart_items as $cart_item ) {
        $total_quantity += max( 1, (int) $cart_item['quantity'] );
    }

    $wpdb->insert( table_name( 'purchases' ), [
        'company_id'        => (int) $company['id'],
        'product_id'        => $first_product_id,
        'payment_gateway'   => $gateway,
        'status'            => 'pending',
        'amount'            => $price['total'],
        'currency'          => 'MXN',
        'quantity'          => $total_quantity,
        'payload'           => wp_json_encode( $purchase_data['payload'] ),
        'created_at'        => current_time( 'mysql' ),
    ] );

    return [
        'purchase_id' => (int) $wpdb->insert_id,
        'price'       => $price,
        'payload'     => $purchase_data['payload'],
        'cart_items'  => $cart_items,
    ];
}

function create_pending_purchase( $company, $product, $quantity, $gateway = '' ) {
    global $wpdb;

    $purchase_data = purchase_payload_for_product( $product, $quantity );
    $price = $purchase_data['price'];

    if ( $price['total'] <= 0 ) {
        return new \WP_Error( 'invalid_price', 'Precio inválido.' );
    }

    $wpdb->insert( table_name( 'purchases' ), [
        'company_id'  => (int) $company['id'],
        'product_id'  => (int) $product['id'],
        'payment_gateway' => $gateway,
        'status'      => 'pending',
        'amount'      => $price['total'],
        'currency'    => 'MXN',
        'quantity'    => $quantity,
        'payload'     => wp_json_encode( $purchase_data['payload'] ),
        'created_at'  => current_time( 'mysql' ),
    ] );

    return [
        'purchase_id' => (int) $wpdb->insert_id,
        'price'       => $price,
        'payload'     => $purchase_data['payload'],
    ];
}

function load_product_from_request( $source ) {
    $product_id = (int) ( $source['product_id'] ?? 0 );
    $quantity   = max( 1, (int) ( $source['quantity'] ?? 1 ) );
    $product    = product_with_items( $product_id );

    if ( ! $product || ! $product['active'] || empty( $product['items'] ) ) {
        return new \WP_Error( 'invalid_product', 'Producto no disponible.' );
    }

    return [
        'product'  => $product,
        'quantity' => $quantity,
    ];
}

function grant_purchase_credits( $purchase ) {
    global $wpdb;

    if ( ! $purchase || $purchase['status'] === 'paid' ) {
        return false;
    }

    $payload = json_decode( $purchase['payload'], true );
    foreach ( $payload['items'] ?? [] as $item ) {
        $validity_days = max( 0, (int) ( $item['validity_days'] ?? 0 ) );
        $expires_at = $validity_days > 0
            ? date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $validity_days * DAY_IN_SECONDS ) )
            : null;

        insert_consultation_inventory( [
            'company_id'      => (int) $purchase['company_id'],
            'purchase_id'     => (int) $purchase['id'],
            'origin'          => 'purchase',
            'service_id'      => (int) $item['service_id'],
            'service_name'    => sanitize_text_field( $item['service_name'] ),
            'quantity'        => (int) $item['quantity'],
            'expires_at'      => $expires_at,
        ] );
    }

    $wpdb->update( table_name( 'purchases' ), [
        'status'  => 'paid',
        'paid_at' => current_time( 'mysql' ),
    ], [ 'id' => (int) $purchase['id'] ] );

    return true;
}

function ajax_stripe_create_session() {
    check_ajax_referer( 'vev_portal', 'nonce' );

    $company = current_company();
    if ( ! $company ) {
        wp_send_json_error( [ 'message' => 'Tu empresa no tiene una sesión activa.' ], 403 );
    }
    if ( ! is_company_admin_account( $company ) ) {
        wp_send_json_error( [ 'message' => 'Tu usuario puede agendar y pedir soporte, pero no comprar consultas.' ], 403 );
    }

    if ( ! defined( 'STRIPE_SECRET_KEY' ) || ! STRIPE_SECRET_KEY ) {
        wp_send_json_error( [ 'message' => 'STRIPE_SECRET_KEY no está definida.' ] );
    }

    $vendor = stripe_vendor_path();
    if ( ! $vendor ) {
        wp_send_json_error( [ 'message' => 'No se encontró la librería Stripe. Puedes reutilizar la carpeta stripe/vendor del plugin del hero.' ] );
    }
    require_once $vendor;

    $raw = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );
    $cart_items = normalize_cart_items_from_request( is_array( $data ) ? $data : [], $company );

    if ( is_wp_error( $cart_items ) ) {
        wp_send_json_error( [ 'message' => $cart_items->get_error_message() ] );
    }

    $pending = create_pending_cart_purchase( $company, $cart_items, 'stripe' );
    if ( is_wp_error( $pending ) ) {
        wp_send_json_error( [ 'message' => $pending->get_error_message() ] );
    }

    global $wpdb;

    $purchase_id = (int) $pending['purchase_id'];
    $line_items = [];
    foreach ( $pending['cart_items'] as $cart_item ) {
        $name = $cart_item['quantity'] . ' x ' . $cart_item['product']['name'];
        $line_items[] = [
            'price_data' => [
                'currency'     => 'mxn',
                'unit_amount'  => (int) round( $cart_item['price']['total'] * 100 ),
                'product_data' => [
                    'name'        => sanitize_text_field( $name ),
                    'description' => 'Compra empresarial VirtualMD',
                ],
            ],
            'quantity' => 1,
        ];
    }

    $base_url = clean_portal_url( $data['portalUrl'] ?? '' );
    $return_url = add_query_arg( [
        'vev_stripe_return' => '1',
        'session_id'        => '{CHECKOUT_SESSION_ID}',
    ], $base_url );
    $return_url = str_replace( [ '%7BCHECKOUT_SESSION_ID%7D', '%7bCHECKOUT_SESSION_ID%7d' ], '{CHECKOUT_SESSION_ID}', $return_url );

    try {
        $stripe = new \Stripe\StripeClient( STRIPE_SECRET_KEY );
        $session = $stripe->checkout->sessions->create( [
            'ui_mode'        => 'embedded_page',
            'mode'           => 'payment',
            'customer_email' => $company['email'],
            'line_items'     => $line_items,
            'metadata'       => [
                'source'      => 'vempresas_virtual',
                'purchase_id' => (string) $purchase_id,
                'company_id'  => (string) $company['id'],
            ],
            'return_url'     => $return_url,
        ] );

        $wpdb->update( table_name( 'purchases' ), [
            'stripe_session_id' => $session->id,
            'payment_reference' => $session->id,
        ], [ 'id' => $purchase_id ] );

        wp_send_json_success( [
            'clientSecret' => $session->client_secret,
            'sessionId'    => $session->id,
        ] );
    } catch ( \Exception $e ) {
        $wpdb->update( table_name( 'purchases' ), [ 'status' => 'failed' ], [ 'id' => $purchase_id ] );
        wp_send_json_error( [ 'message' => 'Stripe: ' . $e->getMessage() ] );
    }
}

function start_purchase() {
    if ( ! check_admin_referer( 'vev_start_purchase' ) ) {
        wp_die( 'No autorizado' );
    }

    $company = current_company();
    if ( ! $company ) {
        wp_die( 'Tu empresa no tiene una sesión activa.' );
    }
    if ( ! is_company_admin_account( $company ) ) {
        wp_die( 'Tu usuario puede agendar y pedir soporte, pero no comprar consultas.' );
    }

    if ( ! defined( 'STRIPE_SECRET_KEY' ) || ! STRIPE_SECRET_KEY ) {
        wp_die( 'STRIPE_SECRET_KEY no está definida.' );
    }

    $vendor = stripe_vendor_path();
    if ( ! $vendor ) {
        wp_die( 'No se encontró la librería Stripe. Puedes reutilizar la carpeta stripe/vendor del plugin del hero.' );
    }
    require_once $vendor;

    $request_product = load_product_from_request( $_POST );
    if ( is_wp_error( $request_product ) ) {
        wp_die( esc_html( $request_product->get_error_message() ) );
    }

    $pending = create_pending_purchase( $company, $request_product['product'], $request_product['quantity'], 'stripe' );
    if ( is_wp_error( $pending ) ) {
        wp_die( esc_html( $pending->get_error_message() ) );
    }

    global $wpdb;

    $purchase_id = (int) $pending['purchase_id'];
    $price = $pending['price'];
    $product = $request_product['product'];
    $base_url = clean_portal_url( $_POST['portal_url'] ?? '' );
    $success_url = add_query_arg( [
        'vev_stripe_return' => '1',
        'session_id'        => '{CHECKOUT_SESSION_ID}',
    ], $base_url );
    $success_url = str_replace( [ '%7BCHECKOUT_SESSION_ID%7D', '%7bCHECKOUT_SESSION_ID%7d' ], '{CHECKOUT_SESSION_ID}', $success_url );

    try {
        $stripe = new \Stripe\StripeClient( STRIPE_SECRET_KEY );
        $session = $stripe->checkout->sessions->create( [
            'mode'        => 'payment',
            'customer_email' => $company['email'],
            'line_items'  => [
                [
                    'price_data' => [
                        'currency'     => 'mxn',
                        'unit_amount'  => (int) round( $price['total'] * 100 ),
                        'product_data' => [
                            'name'        => $product['name'],
                            'description' => 'Compra empresarial VirtualMD',
                        ],
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata'    => [
                'source'      => 'vempresas_virtual',
                'purchase_id' => (string) $purchase_id,
                'company_id'  => (string) $company['id'],
            ],
            'success_url' => $success_url,
            'cancel_url'  => add_query_arg( 'vev_message', rawurlencode( 'Pago cancelado.' ), $base_url ),
        ] );

        $wpdb->update( table_name( 'purchases' ), [
            'stripe_session_id' => $session->id,
            'payment_reference' => $session->id,
        ], [ 'id' => $purchase_id ] );

        wp_redirect( esc_url_raw( $session->url ) );
        exit;
    } catch ( \Exception $e ) {
        $wpdb->update( table_name( 'purchases' ), [ 'status' => 'failed' ], [ 'id' => $purchase_id ] );
        wp_die( 'Stripe: ' . esc_html( $e->getMessage() ) );
    }
}

function paypal_api_base() {
    $mode = defined( 'PAYPAL_MODE' ) ? PAYPAL_MODE : 'sandbox';
    return $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

function paypal_access_token() {
    if ( ! defined( 'PAYPAL_CLIENT_ID' ) || ! PAYPAL_CLIENT_ID || ! defined( 'PAYPAL_CLIENT_SECRET' ) || ! PAYPAL_CLIENT_SECRET ) {
        return new \WP_Error( 'paypal_config', 'Credenciales de PayPal no configuradas.' );
    }

    $response = wp_remote_post( paypal_api_base() . '/v1/oauth2/token', [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode( PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET ),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body'    => 'grant_type=client_credentials',
        'timeout' => 15,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $body['access_token'] ) ) {
        return new \WP_Error( 'paypal_token', 'PayPal no devolvió access_token.' );
    }

    return $body['access_token'];
}

function ajax_paypal_create_order() {
    check_ajax_referer( 'vev_portal', 'nonce' );

    $company = current_company();
    if ( ! $company ) {
        wp_send_json_error( [ 'message' => 'Tu empresa no tiene una sesión activa.' ], 403 );
    }
    if ( ! is_company_admin_account( $company ) ) {
        wp_send_json_error( [ 'message' => 'Tu usuario puede agendar y pedir soporte, pero no comprar consultas.' ], 403 );
    }

    $raw = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );
    $cart_items = normalize_cart_items_from_request( is_array( $data ) ? $data : [], $company );

    if ( is_wp_error( $cart_items ) ) {
        wp_send_json_error( [ 'message' => $cart_items->get_error_message() ] );
    }

    $pending = create_pending_cart_purchase( $company, $cart_items, 'paypal' );
    if ( is_wp_error( $pending ) ) {
        wp_send_json_error( [ 'message' => $pending->get_error_message() ] );
    }

    $token = paypal_access_token();
    if ( is_wp_error( $token ) ) {
        wp_send_json_error( [ 'message' => $token->get_error_message() ] );
    }

    $description = count( $pending['cart_items'] ) === 1
        ? $pending['cart_items'][0]['quantity'] . ' x ' . $pending['cart_items'][0]['product']['name'] . ' - vEmpresas Virtual'
        : 'Carrito vEmpresas Virtual (' . count( $pending['cart_items'] ) . ' productos)';

    $response = wp_remote_post( paypal_api_base() . '/v2/checkout/orders', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( [
            'intent'         => 'CAPTURE',
            'purchase_units' => [
                [
                    'description' => substr( sanitize_text_field( $description ), 0, 120 ),
                    'amount'      => [
                        'currency_code' => 'MXN',
                        'value'         => number_format( (float) $pending['price']['total'], 2, '.', '' ),
                    ],
                ],
            ],
        ] ),
        'timeout' => 15,
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [ 'message' => $response->get_error_message() ] );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $body['id'] ) ) {
        wp_send_json_error( [ 'message' => 'PayPal no devolvió orderID.', 'detail' => $body ] );
    }

    global $wpdb;
    $wpdb->update( table_name( 'purchases' ), [
        'payment_reference' => sanitize_text_field( $body['id'] ),
    ], [ 'id' => (int) $pending['purchase_id'] ] );

    wp_send_json_success( [ 'orderID' => $body['id'] ] );
}

function ajax_paypal_capture_order() {
    check_ajax_referer( 'vev_portal', 'nonce' );

    $company = current_company();
    if ( ! $company ) {
        wp_send_json_error( [ 'message' => 'Tu empresa no tiene una sesión activa.' ], 403 );
    }
    if ( ! is_company_admin_account( $company ) ) {
        wp_send_json_error( [ 'message' => 'Tu usuario puede agendar y pedir soporte, pero no comprar consultas.' ], 403 );
    }

    $raw = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );
    $order_id = sanitize_text_field( $data['orderID'] ?? '' );

    if ( ! $order_id ) {
        wp_send_json_error( [ 'message' => 'orderID requerido.' ] );
    }

    $token = paypal_access_token();
    if ( is_wp_error( $token ) ) {
        wp_send_json_error( [ 'message' => $token->get_error_message() ] );
    }

    $response = wp_remote_post( paypal_api_base() . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => '{}',
        'timeout' => 15,
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [ 'message' => $response->get_error_message() ] );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ( $body['status'] ?? '' ) !== 'COMPLETED' ) {
        wp_send_json_error( [ 'message' => 'El pago PayPal no se completó.', 'detail' => $body ] );
    }

    global $wpdb;
    $purchase = $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'purchases' ) . ' WHERE payment_reference = %s AND company_id = %d AND payment_gateway = %s',
        $order_id,
        (int) $company['id'],
        'paypal'
    ), ARRAY_A );

    if ( ! $purchase ) {
        wp_send_json_error( [ 'message' => 'No encontramos la compra asociada al pago PayPal.' ] );
    }

    grant_purchase_credits( $purchase );
    wp_send_json_success( [ 'message' => 'Pago confirmado. Las consultas ya están disponibles.' ] );
}

function verify_stripe_return() {
    if ( empty( $_GET['vev_stripe_return'] ) || empty( $_GET['session_id'] ) || ! is_company_user() ) {
        return '';
    }

    if ( ! defined( 'STRIPE_SECRET_KEY' ) || ! STRIPE_SECRET_KEY ) {
        return 'Pago recibido, pero falta configurar STRIPE_SECRET_KEY para verificarlo.';
    }

    $vendor = stripe_vendor_path();
    if ( ! $vendor ) {
        return 'Pago recibido, pero falta la librería Stripe para verificarlo.';
    }
    require_once $vendor;

    global $wpdb;
    $company = current_company();
    $session_id = sanitize_text_field( wp_unslash( $_GET['session_id'] ) );

    $purchase = $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'purchases' ) . ' WHERE (stripe_session_id = %s OR payment_reference = %s) AND company_id = %d',
        $session_id,
        $session_id,
        (int) $company['id']
    ), ARRAY_A );

    if ( ! $purchase ) {
        return 'No encontramos la compra asociada a este pago.';
    }

    if ( $purchase['status'] === 'paid' ) {
        return 'La compra ya estaba guardada como consultas disponibles.';
    }

    try {
        $stripe = new \Stripe\StripeClient( STRIPE_SECRET_KEY );
        $session = $stripe->checkout->sessions->retrieve( $session_id );

        if ( $session->status !== 'complete' || $session->payment_status !== 'paid' ) {
            return 'El pago aún no aparece como completado.';
        }

        grant_purchase_credits( $purchase );

        return 'Pago confirmado. Las consultas ya están disponibles para agendar.';
    } catch ( \Exception $e ) {
        return 'No se pudo verificar Stripe: ' . $e->getMessage();
    }
}

function ajax_get_slots() {
    check_ajax_referer( 'vev_portal', 'nonce' );
    $company = current_company();
    if ( ! $company ) {
        wp_send_json_error( [ 'message' => 'No autorizado' ], 403 );
    }

    global $wpdb;
    $credit_id = (int) ( $_GET['creditId'] ?? 0 );
    $provider_id = max( 0, (int) ( $_GET['providerId'] ?? 0 ) );
    $date      = sanitize_text_field( $_GET['date'] ?? '' );

    $credit = $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'credits' ) . ' WHERE id = %d AND company_id = %d',
        $credit_id,
        (int) $company['id']
    ), ARRAY_A );

    if ( ! $credit || available_consultations_count( $credit ) <= 0 ) {
        wp_send_json_error( [ 'message' => 'No tienes consultas disponibles para este servicio.' ] );
    }

    if ( ! empty( $credit['expires_at'] ) && strtotime( $credit['expires_at'] ) < current_time( 'timestamp' ) ) {
        wp_send_json_error( [ 'message' => 'Esta consulta disponible ya venció.' ] );
    }

    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        wp_send_json_error( [ 'message' => 'Fecha inválida.' ] );
    }

    $availability = get_slots_data( (int) $credit['service_id'], $provider_id, $date, $date );
    $providers = [];
    if ( is_array( $availability['providerMap'] ?? null ) ) {
        foreach ( $availability['providerMap'][ $date ] ?? [] as $time => $ids ) {
            foreach ( (array) $ids as $id ) {
                $providers[ (int) $id ] = amelia_provider_name( (int) $id );
            }
        }
    }

    wp_send_json_success( [
        'availability' => $availability,
        'providers'    => $providers,
    ] );
}

function ajax_get_providers() {
    check_ajax_referer( 'vev_portal', 'nonce' );

    $company = current_company();
    if ( ! $company ) {
        wp_send_json_error( [ 'message' => 'No autorizado' ], 403 );
    }

    global $wpdb;
    $credit_id = (int) ( $_GET['creditId'] ?? 0 );
    $credit = $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'credits' ) . ' WHERE id = %d AND company_id = %d',
        $credit_id,
        (int) $company['id']
    ), ARRAY_A );

    if ( ! $credit || available_consultations_count( $credit ) <= 0 ) {
        wp_send_json_error( [ 'message' => 'No tienes consultas disponibles para este servicio.' ] );
    }

    $prefix = $wpdb->prefix;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT u.id, u.firstName, u.lastName
         FROM {$prefix}amelia_providers_to_services ps
         INNER JOIN {$prefix}amelia_users u ON u.id = ps.userId
         WHERE ps.serviceId = %d AND u.status = 'visible' AND u.type = 'provider'
         ORDER BY u.firstName ASC, u.lastName ASC",
        (int) $credit['service_id']
    ), ARRAY_A );

    $providers = array_map( static function ( $row ) {
        return [
            'id'   => (int) $row['id'],
            'name' => trim( sanitize_text_field( $row['firstName'] ?? '' ) . ' ' . sanitize_text_field( $row['lastName'] ?? '' ) ),
        ];
    }, $rows );

    wp_send_json_success( [ 'providers' => $providers ] );
}

function ajax_schedule_bulk() {
    check_ajax_referer( 'vev_portal', 'nonce' );
    $company = current_company();
    if ( ! $company ) {
        wp_send_json_error( [ 'message' => 'No autorizado' ], 403 );
    }

    $raw = file_get_contents( 'php://input' );
    $data = json_decode( $raw, true );
    $rows = $data['appointments'] ?? [];

    if ( empty( $rows ) || ! is_array( $rows ) ) {
        wp_send_json_error( [ 'message' => 'Agrega al menos una consulta.' ] );
    }

    global $wpdb;
    $results = [];

    foreach ( $rows as $index => $row ) {
        $credit_id = (int) ( $row['creditId'] ?? 0 );
        $provider_id = (int) ( $row['providerId'] ?? 0 );
        $doctor_mode = ( $row['doctorMode'] ?? 'auto' ) === 'manual' ? 'manual' : 'auto';
        $date = sanitize_text_field( $row['date'] ?? '' );
        $time = sanitize_text_field( $row['time'] ?? '' );
        $name = sanitize_text_field( $row['name'] ?? '' );
        $email = sanitize_email( $row['email'] ?? '' );
        $phone = sanitize_text_field( $row['phone'] ?? '' );
        $booking_start = $date . ' ' . $time;

        $credit = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . table_name( 'credits' ) . ' WHERE id = %d AND company_id = %d',
            $credit_id,
            (int) $company['id']
        ), ARRAY_A );

        if ( ! $credit || available_consultations_count( $credit ) <= 0 ) {
            $results[] = [ 'row' => $index, 'success' => false, 'message' => 'Sin consultas disponibles.' ];
            continue;
        }

        if ( ! empty( $credit['expires_at'] ) && strtotime( $credit['expires_at'] ) < current_time( 'timestamp' ) ) {
            $results[] = [ 'row' => $index, 'success' => false, 'message' => 'La consulta disponible seleccionada ya venció.' ];
            continue;
        }

        if ( ! $provider_id && $doctor_mode === 'auto' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
            $availability = get_slots_data( (int) $credit['service_id'], 0, $date, $date );
            $ids = $availability['providerMap'][ $date ][ $time ] ?? [];
            $ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
            $provider_id = ! empty( $ids ) ? (int) $ids[0] : 0;
        }

        if ( ! $provider_id || ! $name || ! is_email( $email ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
            $results[] = [ 'row' => $index, 'success' => false, 'message' => 'Datos incompletos.' ];
            continue;
        }

        if ( ! validate_slot( (int) $credit['service_id'], $provider_id, $booking_start ) ) {
            $results[] = [ 'row' => $index, 'success' => false, 'message' => 'El horario ya no está disponible.' ];
            continue;
        }

        $parts = preg_split( '/\s+/', trim( $name ) );
        $first = array_shift( $parts ) ?: 'Paciente';
        $last = trim( implode( ' ', $parts ) ) ?: 'Empresa';

        $booking = create_amelia_booking( [
            'service_id'     => (int) $credit['service_id'],
            'provider_id'    => $provider_id,
            'booking_start'  => $booking_start,
            'first_name'     => $first,
            'last_name'      => $last,
            'email'          => $email,
            'phone'          => $phone,
            'notes'          => 'Consulta empresarial: ' . $company['company_name'],
        ] );

        if ( empty( $booking['success'] ) ) {
            $results[] = [ 'row' => $index, 'success' => false, 'message' => $booking['message'] ?? 'No se pudo agendar.' ];
            continue;
        }

        $wpdb->insert( table_name( 'appointments' ), [
            'company_id'        => (int) $company['id'],
            'credit_id'         => (int) $credit['id'],
            'service_id'        => (int) $credit['service_id'],
            'service_name'      => $credit['service_name'],
            'provider_id'       => $provider_id,
            'provider_name'     => amelia_provider_name( $provider_id ),
            'booking_start'     => $booking_start . ':00',
            'customer_name'     => $name,
            'customer_email'    => $email,
            'customer_phone'    => $phone,
            'amelia_booking_id' => (int) ( $booking['booking_id'] ?? 0 ),
            'meeting_url'       => $booking['meeting_url'] ?? '',
            'status'            => 'booked',
            'amelia_response'   => wp_json_encode( $booking['payload'] ?? [] ),
            'created_at'        => current_time( 'mysql' ),
        ] );

        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . table_name( 'credits' ) . ' SET used_quantity = used_quantity + 1 WHERE id = %d AND used_quantity < total_quantity',
            (int) $credit['id']
        ) );

        $results[] = [
            'row'         => $index,
            'success'     => true,
            'message'     => 'Consulta agendada.',
            'meetingUrl'  => $booking['meeting_url'] ?? '',
        ];
    }

    wp_send_json_success( [ 'results' => $results ] );
}

function team_create_user() {
    check_admin_referer( 'vev_team_action' );

    $admin = current_portal_admin_or_redirect();

    $company_name = sanitize_text_field( $_POST['company_name'] ?? '' );
    $contact_name = sanitize_text_field( $_POST['contact_name'] ?? '' );
    $email        = sanitize_email( $_POST['email'] ?? '' );
    $phone        = sanitize_text_field( $_POST['phone'] ?? '' );
    $password     = (string) ( $_POST['password'] ?? '' );

    if ( ! $company_name || ! is_email( $email ) || strlen( $password ) < 8 ) {
        redirect_to_portal( '', 'Revisa nombre, email y contraseña mínima de 8 caracteres.' );
    }

    global $wpdb;
    $exists = $wpdb->get_var( $wpdb->prepare(
        'SELECT id FROM ' . table_name( 'companies' ) . ' WHERE email = %s AND status <> %s',
        $email,
        'deleted'
    ) );

    if ( $exists ) {
        redirect_to_portal( '', 'Ya existe una cuenta activa o inactiva con ese email.' );
    }

    $wpdb->insert( table_name( 'companies' ), [
        'user_id'           => 0,
        'account_role'      => 'user',
        'parent_company_id' => (int) $admin['id'],
        'company_name'      => $company_name,
        'contact_name'      => $contact_name,
        'email'             => $email,
        'phone'             => $phone,
        'password_hash'     => wp_hash_password( $password ),
        'status'            => 'active',
        'created_at'        => current_time( 'mysql' ),
        'updated_at'        => current_time( 'mysql' ),
    ] );

    redirect_to_portal( 'Usuario creado dentro de tu empresa.' );
}

function team_assign_consultations() {
    check_admin_referer( 'vev_team_action' );

    $admin = current_portal_admin_or_redirect();
    $child_id = (int) ( $_POST['child_company_id'] ?? 0 );
    $source_id = (int) ( $_POST['source_credit_id'] ?? 0 );
    $quantity = max( 0, (int) ( $_POST['quantity'] ?? 0 ) );
    $note = sanitize_textarea_field( $_POST['assignment_note'] ?? '' );

    if ( ! $child_id || ! $source_id || ! $quantity || ! company_is_active_child_of( $child_id, (int) $admin['id'] ) ) {
        redirect_to_portal( '', 'Selecciona usuario, consulta disponible y cantidad.' );
    }

    global $wpdb;
    $source = $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'credits' ) . ' WHERE id = %d AND company_id = %d',
        $source_id,
        (int) $admin['id']
    ), ARRAY_A );

    if ( ! $source || available_consultations_count( $source ) < $quantity ) {
        redirect_to_portal( '', 'No tienes suficientes consultas disponibles para asignar.' );
    }

    $updated = $wpdb->query( $wpdb->prepare(
        'UPDATE ' . table_name( 'credits' ) . ' SET total_quantity = total_quantity - %d WHERE id = %d AND company_id = %d AND total_quantity >= used_quantity + %d',
        $quantity,
        $source_id,
        (int) $admin['id'],
        $quantity
    ) );

    if ( ! $updated ) {
        redirect_to_portal( '', 'No se pudo apartar esa cantidad. Intenta de nuevo.' );
    }

    $new_inventory_id = insert_consultation_inventory( [
        'company_id'             => $child_id,
        'purchase_id'            => (int) $source['purchase_id'],
        'source_credit_id'       => $source_id,
        'assigned_by_company_id' => (int) $admin['id'],
        'origin'                 => 'assigned',
        'service_id'             => (int) $source['service_id'],
        'service_name'           => $source['service_name'],
        'quantity'               => $quantity,
        'expires_at'             => $source['expires_at'],
        'assignment_note'        => $note,
    ] );

    if ( ! $new_inventory_id ) {
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . table_name( 'credits' ) . ' SET total_quantity = total_quantity + %d WHERE id = %d',
            $quantity,
            $source_id
        ) );
        redirect_to_portal( '', 'No se pudo guardar la asignación. La cantidad fue devuelta a tu cuenta.' );
    }

    redirect_to_portal( 'Consultas asignadas al usuario.' );
}

function team_deassign_consultations() {
    check_admin_referer( 'vev_team_action' );

    $admin = current_portal_admin_or_redirect();
    $consultation_id = (int) ( $_POST['consultation_id'] ?? 0 );

    global $wpdb;
    $consultation = $wpdb->get_row( $wpdb->prepare(
        'SELECT cr.*, c.parent_company_id
         FROM ' . table_name( 'credits' ) . ' cr
         INNER JOIN ' . table_name( 'companies' ) . ' c ON c.id = cr.company_id
         WHERE cr.id = %d',
        $consultation_id
    ), ARRAY_A );

    if ( ! $consultation || (int) $consultation['parent_company_id'] !== (int) $admin['id'] ) {
        redirect_to_portal( '', 'No puedes desasignar consultas de este usuario.' );
    }

    $result = return_unused_consultations( $consultation_id, (int) $admin['id'] );
    redirect_to_portal(
        ! empty( $result['success'] )
            ? 'Consultas no usadas devueltas a tu cuenta admin.'
            : '',
        empty( $result['success'] ) ? ( $result['message'] ?? 'No se pudo desasignar.' ) : ''
    );
}

function team_user_status() {
    check_admin_referer( 'vev_team_action' );

    $admin = current_portal_admin_or_redirect();
    $child_id = (int) ( $_POST['child_company_id'] ?? 0 );
    $mode = sanitize_key( $_POST['mode'] ?? '' );

    global $wpdb;
    $child = $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'companies' ) . ' WHERE id = %d AND account_role = %s AND parent_company_id = %d',
        $child_id,
        'user',
        (int) $admin['id']
    ), ARRAY_A );

    if ( ! $child || ! in_array( $mode, [ 'active', 'inactive', 'deleted' ], true ) ) {
        redirect_to_portal( '', 'Usuario no válido.' );
    }

    if ( in_array( $mode, [ 'inactive', 'deleted' ], true ) ) {
        return_unused_consultations_for_company( $child_id, (int) $admin['id'] );
    }

    $wpdb->update( table_name( 'companies' ), [
        'status'     => $mode,
        'updated_at' => current_time( 'mysql' ),
    ], [ 'id' => $child_id ] );

    redirect_to_portal( $mode === 'active' ? 'Usuario activado.' : 'Usuario actualizado. Sus consultas no usadas volvieron a tu cuenta.' );
}

function shortcode() {
    wp_enqueue_style( 'vev-portal', VEV_PLUGIN_URL . 'assets/css/portal.css', [], VEV_VERSION );
    $script_deps = [];
    $stripe_public_key = defined( 'STRIPE_PUBLIC_KEY' ) ? STRIPE_PUBLIC_KEY : '';
    if ( $stripe_public_key ) {
        wp_enqueue_script( 'vev-stripe-js', 'https://js.stripe.com/v3/', [], null, true );
        $script_deps[] = 'vev-stripe-js';
    }
    $has_paypal = defined( 'PAYPAL_CLIENT_ID' ) && PAYPAL_CLIENT_ID;
    if ( $has_paypal ) {
        wp_enqueue_script(
            'vev-paypal-sdk',
            'https://www.paypal.com/sdk/js?client-id=' . rawurlencode( PAYPAL_CLIENT_ID ) . '&currency=MXN&intent=capture',
            [],
            null,
            true
        );
        $script_deps[] = 'vev-paypal-sdk';
    }
    wp_enqueue_script( 'vev-portal', VEV_PLUGIN_URL . 'assets/js/portal.js', $script_deps, VEV_VERSION, true );
    wp_localize_script( 'vev-portal', 'VEV_PORTAL', [
        'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
        'nonce'     => wp_create_nonce( 'vev_portal' ),
        'hasPayPal' => $has_paypal,
        'stripePublicKey' => $stripe_public_key,
    ] );

    $message = verify_stripe_return();

    $company = current_company();
    if ( ! $company ) {
        return login_view();
    }

    global $wpdb;
    $is_admin_account = is_company_admin_account( $company );
    $products = $is_admin_account
        ? $wpdb->get_results( $wpdb->prepare(
            'SELECT p.*
             FROM ' . table_name( 'products' ) . ' p
             WHERE p.active = 1
               AND (
                 NOT EXISTS (SELECT 1 FROM ' . table_name( 'product_companies' ) . ' pc_all WHERE pc_all.product_id = p.id)
                 OR EXISTS (SELECT 1 FROM ' . table_name( 'product_companies' ) . ' pc WHERE pc.product_id = p.id AND pc.company_id = %d)
               )
             ORDER BY p.sort_order ASC, p.created_at DESC',
            (int) $company['id']
        ), ARRAY_A )
        : [];
    $credits = $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'credits' ) . ' WHERE company_id = %d ORDER BY created_at DESC',
        (int) $company['id']
    ), ARRAY_A );
    $appointments = $wpdb->get_results( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'appointments' ) . ' WHERE company_id = %d ORDER BY booking_start DESC LIMIT 100',
        (int) $company['id']
    ), ARRAY_A );
    $child_accounts = [];
    $team_consultations = [];

    if ( $is_admin_account ) {
        $child_accounts = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . table_name( 'companies' ) . ' WHERE parent_company_id = %d AND account_role = %s AND status <> %s ORDER BY status ASC, company_name ASC',
            (int) $company['id'],
            'user',
            'deleted'
        ), ARRAY_A );

        $team_consultations = $wpdb->get_results( $wpdb->prepare(
            'SELECT cr.*, c.company_name, c.email, c.status AS company_status
             FROM ' . table_name( 'credits' ) . ' cr
             INNER JOIN ' . table_name( 'companies' ) . ' c ON c.id = cr.company_id
             WHERE c.parent_company_id = %d AND c.account_role = %s AND c.status <> %s
             ORDER BY cr.created_at DESC',
            (int) $company['id'],
            'user',
            'deleted'
        ), ARRAY_A );
    }

    ob_start();
    include VEV_PLUGIN_DIR . 'templates/portal.php';
    return ob_get_clean();
}

function login_view() {
    ob_start();
    ?>
    <div class="vev-portal vev-login">
        <div class="vev-login-card">
            <img class="vev-login-logo" src="https://virtualmd.mx/wp-content/uploads/2025/09/AUDIOS-1.png" alt="VirtualMD">
            <h2>Acceso corporativo</h2>
            <?php if ( ! empty( $_GET['vev_error'] ) ) : ?>
                <div class="vev-alert"><?php echo esc_html( wp_unslash( $_GET['vev_error'] ) ); ?></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'vev_login' ); ?>
                <input type="hidden" name="action" value="vev_login">
                <label><span>Usuario</span><input type="email" name="email" placeholder="empresa@dominio.com" required></label>
                <label><span>Contraseña</span><input type="password" name="password" placeholder="••••••••••••" required></label>
                <button type="submit">Ingresar</button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
