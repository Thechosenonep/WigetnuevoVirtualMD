<?php
namespace VirtualMD\EmpresasVirtual\Admin;

use function VirtualMD\EmpresasVirtual\amelia_services;
use function VirtualMD\EmpresasVirtual\amelia_service_name;
use function VirtualMD\EmpresasVirtual\available_consultations_count;
use function VirtualMD\EmpresasVirtual\company_available_consultations_count;
use function VirtualMD\EmpresasVirtual\insert_consultation_inventory;
use function VirtualMD\EmpresasVirtual\money;
use function VirtualMD\EmpresasVirtual\product_with_items;
use function VirtualMD\EmpresasVirtual\return_unused_consultations;
use function VirtualMD\EmpresasVirtual\return_unused_consultations_for_company;
use function VirtualMD\EmpresasVirtual\table_name;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function init() {
    add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );
    add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\assets' );
    add_action( 'admin_post_vev_create_company', __NAMESPACE__ . '\\create_company' );
    add_action( 'admin_post_vev_company_status', __NAMESPACE__ . '\\company_status' );
    add_action( 'admin_post_vev_manual_consultation_grant', __NAMESPACE__ . '\\manual_consultation_grant' );
    add_action( 'admin_post_vev_admin_deassign_consultations', __NAMESPACE__ . '\\admin_deassign_consultations' );
    add_action( 'admin_post_vev_save_product', __NAMESPACE__ . '\\save_product' );
}

function menu() {
    add_menu_page(
        'vEmpresas Virtual',
        'vEmpresas virtual',
        'manage_options',
        'vev-companies',
        __NAMESPACE__ . '\\companies_page',
        'dashicons-building',
        56
    );

    add_submenu_page(
        'vev-companies',
        'Empresas',
        'Empresas',
        'manage_options',
        'vev-companies',
        __NAMESPACE__ . '\\companies_page'
    );

    add_submenu_page(
        'vev-companies',
        'Paquetes y consultas',
        'Paquetes y consultas',
        'manage_options',
        'vev-products',
        __NAMESPACE__ . '\\products_page'
    );
}

function assets( $hook ) {
    if ( strpos( (string) $hook, 'vev-' ) === false ) {
        return;
    }

    wp_enqueue_style( 'vev-admin', VEV_PLUGIN_URL . 'assets/css/admin.css', [], VEV_VERSION );
}

function redirect_back( $page, $message = '' ) {
    $url = admin_url( 'admin.php?page=' . $page );
    if ( $message ) {
        $url = add_query_arg( 'vev_message', rawurlencode( $message ), $url );
    }
    wp_safe_redirect( $url );
    exit;
}

function create_company() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vev_create_company' ) ) {
        wp_die( 'No autorizado' );
    }

    global $wpdb;

    $company_name = sanitize_text_field( $_POST['company_name'] ?? '' );
    $contact_name = sanitize_text_field( $_POST['contact_name'] ?? '' );
    $email        = sanitize_email( $_POST['email'] ?? '' );
    $phone        = sanitize_text_field( $_POST['phone'] ?? '' );
    $password     = (string) ( $_POST['password'] ?? '' );
    $account_role = ( $_POST['account_role'] ?? 'admin' ) === 'user' ? 'user' : 'admin';
    $parent_company_id = $account_role === 'user' ? (int) ( $_POST['parent_company_id'] ?? 0 ) : 0;

    if ( ! $company_name || ! is_email( $email ) || strlen( $password ) < 8 ) {
        redirect_back( 'vev-companies', 'Revisa empresa, email y contraseña mínima de 8 caracteres.' );
    }

    if ( $account_role === 'user' ) {
        $parent_exists = (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . table_name( 'companies' ) . ' WHERE id = %d AND account_role = %s AND status = %s',
            $parent_company_id,
            'admin',
            'active'
        ) );

        if ( ! $parent_exists ) {
            redirect_back( 'vev-companies', 'Selecciona una cuenta admin válida para este usuario.' );
        }
    }

    $existing = $wpdb->get_var( $wpdb->prepare(
        'SELECT id FROM ' . table_name( 'companies' ) . ' WHERE email = %s',
        $email
    ) );

    $data = [
        'user_id'      => 0,
        'account_role' => $account_role,
        'parent_company_id' => $parent_company_id,
        'company_name' => $company_name,
        'contact_name' => $contact_name,
        'email'        => $email,
        'phone'        => $phone,
        'password_hash'=> wp_hash_password( $password ),
        'status'       => 'active',
        'updated_at'   => current_time( 'mysql' ),
    ];

    if ( $existing ) {
        $wpdb->update( table_name( 'companies' ), $data, [ 'id' => (int) $existing ] );
    } else {
        $data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( table_name( 'companies' ), $data );
    }

    redirect_back( 'vev-companies', 'Empresa guardada.' );
}

function company_status() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vev_company_status' ) ) {
        wp_die( 'No autorizado' );
    }

    global $wpdb;

    $company_id = (int) ( $_POST['company_id'] ?? 0 );
    $mode       = sanitize_key( $_POST['mode'] ?? '' );

    $company = $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . table_name( 'companies' ) . ' WHERE id = %d',
        $company_id
    ), ARRAY_A );

    if ( ! $company ) {
        redirect_back( 'vev-companies', 'No se encontró la cuenta.' );
    }

    if ( in_array( $mode, [ 'inactive', 'deleted' ], true ) && ( $company['account_role'] ?? 'admin' ) === 'user' ) {
        return_unused_consultations_for_company( $company_id );
    }

    if ( $mode === 'active' || $mode === 'inactive' || $mode === 'deleted' ) {
        $wpdb->update( table_name( 'companies' ), [
            'status'     => $mode === 'deleted' ? 'deleted' : $mode,
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $company_id ] );
    }

    redirect_back( 'vev-companies', 'Estado de cuenta actualizado.' );
}

function manual_consultation_grant() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vev_manual_consultation_grant' ) ) {
        wp_die( 'No autorizado' );
    }

    $company_id    = (int) ( $_POST['company_id'] ?? 0 );
    $service_id    = (int) ( $_POST['service_id'] ?? 0 );
    $quantity      = max( 0, (int) ( $_POST['quantity'] ?? 0 ) );
    $validity_days = max( 0, (int) ( $_POST['validity_days'] ?? 0 ) );
    $note          = sanitize_textarea_field( $_POST['assignment_note'] ?? '' );

    if ( ! $company_id || ! $service_id || ! $quantity ) {
        redirect_back( 'vev-companies', 'Selecciona cuenta, servicio y cantidad.' );
    }

    global $wpdb;
    $company_exists = (bool) $wpdb->get_var( $wpdb->prepare(
        'SELECT id FROM ' . table_name( 'companies' ) . ' WHERE id = %d AND status = %s',
        $company_id,
        'active'
    ) );

    if ( ! $company_exists ) {
        redirect_back( 'vev-companies', 'La cuenta seleccionada no está activa.' );
    }

    $expires_at = $validity_days > 0
        ? date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $validity_days * DAY_IN_SECONDS ) )
        : null;

    insert_consultation_inventory( [
        'company_id'       => $company_id,
        'origin'           => 'manual',
        'service_id'       => $service_id,
        'service_name'     => amelia_service_name( $service_id ),
        'quantity'         => $quantity,
        'expires_at'       => $expires_at,
        'assignment_note'  => $note,
    ] );

    redirect_back( 'vev-companies', 'Consultas asignadas manualmente.' );
}

function admin_deassign_consultations() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vev_admin_deassign_consultations' ) ) {
        wp_die( 'No autorizado' );
    }

    $consultation_id = (int) ( $_POST['consultation_id'] ?? 0 );
    $result = return_unused_consultations( $consultation_id );

    redirect_back(
        'vev-companies',
        ! empty( $result['success'] )
            ? 'Consultas no usadas desasignadas.'
            : ( $result['message'] ?? 'No se pudo desasignar.' )
    );
}

function save_product() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vev_save_product' ) ) {
        wp_die( 'No autorizado' );
    }

    global $wpdb;

    $type        = ( $_POST['type'] ?? 'service' ) === 'package' ? 'package' : 'service';
    $name        = sanitize_text_field( $_POST['name'] ?? '' );
    $description = sanitize_textarea_field( $_POST['description'] ?? '' );
    $price       = (float) ( $_POST['price'] ?? 0 );
    $validity_days = max( 0, (int) ( $_POST['validity_days'] ?? 0 ) );
    $active      = ! empty( $_POST['active'] ) ? 1 : 0;
    $product_id  = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;

    if ( ! $name || $price < 0 ) {
        redirect_back( 'vev-products', 'Revisa nombre y precio.' );
    }

    $product_data = [
        'name'        => $name,
        'description' => $description,
        'type'        => $type,
        'price'       => $price,
        'validity_days' => $validity_days,
        'active'      => $active,
        'updated_at'  => current_time( 'mysql' ),
    ];

    if ( $product_id ) {
        $wpdb->update( table_name( 'products' ), $product_data, [ 'id' => $product_id ] );
        $wpdb->delete( table_name( 'product_items' ), [ 'product_id' => $product_id ] );
        $wpdb->delete( table_name( 'price_rules' ), [ 'product_id' => $product_id ] );
        $wpdb->delete( table_name( 'product_companies' ), [ 'product_id' => $product_id ] );
    } else {
        $product_data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( table_name( 'products' ), $product_data );
        $product_id = (int) $wpdb->insert_id;
    }

    $services = amelia_services();
    $service_names = [];
    foreach ( $services as $service ) {
        $service_names[ (int) $service['id'] ] = trim( ( $service['category'] ? $service['category'] . ' - ' : '' ) . $service['name'] );
    }

    if ( $type === 'service' ) {
        $service_id = (int) ( $_POST['service_id'] ?? 0 );
        if ( $service_id ) {
            $wpdb->insert( table_name( 'product_items' ), [
                'product_id'   => $product_id,
                'service_id'   => $service_id,
                'service_name' => $service_names[ $service_id ] ?? ( 'Servicio #' . $service_id ),
                'quantity'     => 1,
                'unit_price'   => $price,
            ] );
        }

        $mins = $_POST['rule_min'] ?? [];
        $discounts = $_POST['rule_discount'] ?? [];
        foreach ( $mins as $index => $min ) {
            $min = (int) $min;
            $discount = (float) ( $discounts[ $index ] ?? 0 );
            if ( $min > 1 && $discount > 0 ) {
                $wpdb->insert( table_name( 'price_rules' ), [
                    'product_id'        => $product_id,
                    'min_quantity'      => $min,
                    'discount_percent'  => min( 100, $discount ),
                ] );
            }
        }
    } else {
        $item_services = $_POST['item_service_id'] ?? [];
        $item_qtys     = $_POST['item_quantity'] ?? [];
        foreach ( $item_services as $index => $service_id ) {
            $service_id = (int) $service_id;
            $qty = max( 0, (int) ( $item_qtys[ $index ] ?? 0 ) );
            if ( ! $service_id || ! $qty ) {
                continue;
            }

            $wpdb->insert( table_name( 'product_items' ), [
                'product_id'   => $product_id,
                'service_id'   => $service_id,
                'service_name' => $service_names[ $service_id ] ?? ( 'Servicio #' . $service_id ),
                'quantity'     => $qty,
                'unit_price'   => 0,
            ] );
        }
    }

    $assigned_companies = array_map( 'intval', (array) ( $_POST['company_ids'] ?? [] ) );
    foreach ( array_filter( $assigned_companies ) as $company_id ) {
        $wpdb->insert( table_name( 'product_companies' ), [
            'product_id' => $product_id,
            'company_id' => $company_id,
        ] );
    }

    redirect_back( 'vev-products', 'Paquete o consulta guardado.' );
}

function notice() {
    if ( empty( $_GET['vev_message'] ) ) {
        return;
    }
    echo '<div class="notice notice-success"><p>' . esc_html( wp_unslash( $_GET['vev_message'] ) ) . '</p></div>';
}

function companies_page() {
    global $wpdb;

    $companies = $wpdb->get_results( 'SELECT * FROM ' . table_name( 'companies' ) . ' WHERE status <> "deleted" ORDER BY account_role ASC, parent_company_id ASC, created_at DESC', ARRAY_A );
    $admins = $wpdb->get_results( 'SELECT * FROM ' . table_name( 'companies' ) . ' WHERE account_role = "admin" AND status = "active" ORDER BY company_name ASC', ARRAY_A );
    $active_accounts = $wpdb->get_results( 'SELECT * FROM ' . table_name( 'companies' ) . ' WHERE status = "active" ORDER BY account_role ASC, company_name ASC', ARRAY_A );
    $services = amelia_services();
    $consultations = $wpdb->get_results(
        'SELECT cr.*, c.company_name, c.account_role
         FROM ' . table_name( 'credits' ) . ' cr
         INNER JOIN ' . table_name( 'companies' ) . ' c ON c.id = cr.company_id
         WHERE c.status <> "deleted"
         ORDER BY cr.created_at DESC
         LIMIT 120',
        ARRAY_A
    );
    $admin_names = [];
    foreach ( $admins as $admin ) {
        $admin_names[ (int) $admin['id'] ] = $admin['company_name'];
    }
    ?>
    <div class="wrap vev-admin">
        <h1>vEmpresas virtual</h1>
        <?php notice(); ?>

        <div class="vev-admin-grid">
            <section class="vev-panel">
                <h2>Crear cuenta</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'vev_create_company' ); ?>
                    <input type="hidden" name="action" value="vev_create_company">
                    <label>Tipo de cuenta
                        <select name="account_role" id="vevAccountRole">
                            <option value="admin">Admin empresa</option>
                            <option value="user">Usuario empresa</option>
                        </select>
                    </label>
                    <label id="vevParentAdminWrap">Admin dueño
                        <select name="parent_company_id">
                            <option value="">Selecciona admin</option>
                            <?php foreach ( $admins as $admin ) : ?>
                                <option value="<?php echo esc_attr( $admin['id'] ); ?>"><?php echo esc_html( $admin['company_name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Empresa o usuario <input type="text" name="company_name" required></label>
                    <label>Contacto <input type="text" name="contact_name"></label>
                    <label>Email de acceso <input type="email" name="email" required></label>
                    <label>Teléfono <input type="text" name="phone"></label>
                    <label>Contraseña <input type="text" name="password" minlength="8" required></label>
                    <button class="button button-primary">Guardar cuenta</button>
                </form>
            </section>

            <section class="vev-panel">
                <h2>Asignar consultas manualmente</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'vev_manual_consultation_grant' ); ?>
                    <input type="hidden" name="action" value="vev_manual_consultation_grant">
                    <label>Cuenta
                        <select name="company_id" required>
                            <option value="">Selecciona cuenta</option>
                            <?php foreach ( $active_accounts as $account ) : ?>
                                <option value="<?php echo esc_attr( $account['id'] ); ?>">
                                    <?php echo esc_html( ( ( $account['account_role'] ?? 'admin' ) === 'user' ? 'Usuario - ' : 'Admin - ' ) . $account['company_name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Servicio Amelia
                        <select name="service_id" required>
                            <option value="">Selecciona servicio</option>
                            <?php foreach ( $services as $service ) : ?>
                                <option value="<?php echo esc_attr( $service['id'] ); ?>">
                                    <?php echo esc_html( trim( ( $service['category'] ? $service['category'] . ' - ' : '' ) . $service['name'] ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="vev-form-row vev-form-row--compact">
                        <label>Cantidad <input type="number" name="quantity" min="1" step="1" required></label>
                        <label>Vigencia en días <input type="number" name="validity_days" min="0" step="1" placeholder="0"></label>
                    </div>
                    <label>Nota administrativa <textarea name="assignment_note" rows="2"></textarea></label>
                    <button class="button button-primary">Asignar consultas</button>
                    <p class="description">Usa 0 en vigencia para consultas sin vencimiento.</p>
                </form>
            </section>
        </div>

        <section class="vev-panel">
            <h2>Cuentas registradas</h2>
            <table class="widefat striped">
                <thead><tr><th>Cuenta</th><th>Tipo</th><th>Admin dueño</th><th>Contacto</th><th>Email</th><th>Consultas disponibles</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ( $companies as $company ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $company['company_name'] ); ?></strong></td>
                        <td><?php echo esc_html( ( $company['account_role'] ?? 'admin' ) === 'user' ? 'Usuario empresa' : 'Admin empresa' ); ?></td>
                        <td><?php echo esc_html( ! empty( $company['parent_company_id'] ) ? ( $admin_names[ (int) $company['parent_company_id'] ] ?? 'Admin #' . (int) $company['parent_company_id'] ) : 'Principal' ); ?></td>
                        <td><?php echo esc_html( $company['contact_name'] ); ?></td>
                        <td><?php echo esc_html( $company['email'] ); ?></td>
                        <td><strong><?php echo esc_html( company_available_consultations_count( (int) $company['id'] ) ); ?></strong></td>
                        <td><?php echo esc_html( $company['status'] ); ?></td>
                        <td>
                            <div class="vev-row-actions">
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <?php wp_nonce_field( 'vev_company_status' ); ?>
                                    <input type="hidden" name="action" value="vev_company_status">
                                    <input type="hidden" name="company_id" value="<?php echo esc_attr( $company['id'] ); ?>">
                                    <input type="hidden" name="mode" value="<?php echo esc_attr( $company['status'] === 'active' ? 'inactive' : 'active' ); ?>">
                                    <button class="button"><?php echo esc_html( $company['status'] === 'active' ? 'Desactivar' : 'Activar' ); ?></button>
                                </form>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Esta cuenta se ocultará y sus consultas no usadas volverán a su admin si aplica.');">
                                    <?php wp_nonce_field( 'vev_company_status' ); ?>
                                    <input type="hidden" name="action" value="vev_company_status">
                                    <input type="hidden" name="company_id" value="<?php echo esc_attr( $company['id'] ); ?>">
                                    <input type="hidden" name="mode" value="deleted">
                                    <button class="button button-link-delete">Borrar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $companies ) ) : ?>
                    <tr><td colspan="8">Todavía no hay cuentas.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="vev-panel">
            <h2>Inventario de consultas</h2>
            <table class="widefat striped">
                <thead><tr><th>Cuenta</th><th>Servicio</th><th>Total</th><th>Usadas</th><th>Disponibles</th><th>Origen</th><th>Vigencia</th><th>Acción</th></tr></thead>
                <tbody>
                <?php foreach ( $consultations as $consultation ) :
                    $available = available_consultations_count( $consultation );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $consultation['company_name'] ); ?></strong><br>
                            <small><?php echo esc_html( ( $consultation['account_role'] ?? 'admin' ) === 'user' ? 'Usuario empresa' : 'Admin empresa' ); ?></small>
                        </td>
                        <td><?php echo esc_html( $consultation['service_name'] ); ?></td>
                        <td><?php echo esc_html( $consultation['total_quantity'] ); ?></td>
                        <td><?php echo esc_html( $consultation['used_quantity'] ); ?></td>
                        <td><strong><?php echo esc_html( $available ); ?></strong></td>
                        <td><?php echo esc_html( $consultation['origin'] ?: 'purchase' ); ?></td>
                        <td><?php echo ! empty( $consultation['expires_at'] ) ? esc_html( mysql2date( 'd/m/Y', $consultation['expires_at'] ) ) : 'Sin vencimiento'; ?></td>
                        <td>
                            <?php if ( $available > 0 ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Se retirarán sólo las consultas no usadas.');">
                                    <?php wp_nonce_field( 'vev_admin_deassign_consultations' ); ?>
                                    <input type="hidden" name="action" value="vev_admin_deassign_consultations">
                                    <input type="hidden" name="consultation_id" value="<?php echo esc_attr( $consultation['id'] ); ?>">
                                    <button class="button">Desasignar no usadas</button>
                                </form>
                            <?php else : ?>
                                <span>Sin disponibles</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $consultations ) ) : ?>
                    <tr><td colspan="8">Todavía no hay consultas guardadas.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>
    <script>
    (function() {
      var role = document.getElementById('vevAccountRole');
      var parent = document.getElementById('vevParentAdminWrap');
      function syncParent() {
        if (!role || !parent) return;
        parent.style.display = role.value === 'user' ? 'grid' : 'none';
      }
      if (role) {
        role.addEventListener('change', syncParent);
        syncParent();
      }
    })();
    </script>
    <?php
}

function products_page() {
    global $wpdb;
    $services = amelia_services();
    $companies = $wpdb->get_results( 'SELECT * FROM ' . table_name( 'companies' ) . ' WHERE status = "active" AND account_role = "admin" ORDER BY company_name ASC', ARRAY_A );
    $products = $wpdb->get_results( 'SELECT * FROM ' . table_name( 'products' ) . ' ORDER BY active DESC, created_at DESC', ARRAY_A );
    ?>
    <div class="wrap vev-admin">
        <h1>Paquetes y consultas</h1>
        <?php notice(); ?>

        <section class="vev-panel">
            <h2>Crear producto empresarial</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vev-product-form">
                <?php wp_nonce_field( 'vev_save_product' ); ?>
                <input type="hidden" name="action" value="vev_save_product">
                <div class="vev-form-row">
                    <label>Nombre <input type="text" name="name" required></label>
                    <label>Tipo de producto
                        <select name="type" id="vevProductType">
                            <option value="service">Consulta por volumen</option>
                            <option value="package">Paquete fijo</option>
                        </select>
                    </label>
                    <label>Precio <input type="number" name="price" min="0" step="0.01" required></label>
                    <label>Vigencia <input type="number" name="validity_days" min="0" step="1" placeholder="365"></label>
                    <label class="vev-check"><input type="checkbox" name="active" value="1" checked> Activo</label>
                </div>
                <p class="description">La vigencia se captura en días desde la fecha de compra. Usa 0 para consultas sin vencimiento.</p>
                <label>Descripción <textarea name="description" rows="2"></textarea></label>
                <label>Empresas asignadas
                    <select name="company_ids[]" multiple size="5">
                        <?php foreach ( $companies as $company ) : ?>
                            <option value="<?php echo esc_attr( $company['id'] ); ?>"><?php echo esc_html( $company['company_name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="description">Si no seleccionas empresas, el producto queda disponible para todas las cuentas admin.</span>
                </label>

                <div class="vev-product-type-switch" aria-label="Tipo de producto">
                    <button type="button" class="is-active" data-vev-admin-type="service">Consulta por volumen</button>
                    <button type="button" data-vev-admin-type="package">Paquete fijo</button>
                </div>

                <div class="vev-product-config">
                    <div class="vev-product-type-panel is-active" data-vev-type-panel="service">
                        <h3>Consulta por volumen</h3>
                        <p>La empresa escoge cuántas consultas compra. El precio es por consulta y puedes agregar descuentos por cantidad.</p>
                        <label>Servicio
                            <select name="service_id">
                                <?php foreach ( $services as $service ) : ?>
                                    <option value="<?php echo esc_attr( $service['id'] ); ?>">
                                        <?php echo esc_html( trim( ( $service['category'] ? $service['category'] . ' - ' : '' ) . $service['name'] ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="vev-rule-grid">
                            <label>Desde <input type="number" name="rule_min[]" min="2" placeholder="10"></label>
                            <label>Descuento % <input type="number" name="rule_discount[]" min="0" max="100" step="0.01" placeholder="15"></label>
                            <label>Desde <input type="number" name="rule_min[]" min="2" placeholder="25"></label>
                            <label>Descuento % <input type="number" name="rule_discount[]" min="0" max="100" step="0.01" placeholder="25"></label>
                        </div>
                    </div>
                    <div class="vev-product-type-panel" data-vev-type-panel="package">
                        <h3>Paquete fijo</h3>
                        <p>El precio cubre todas las consultas configuradas abajo.</p>
                        <?php for ( $i = 0; $i < 5; $i++ ) : ?>
                            <div class="vev-package-row">
                                <select name="item_service_id[]">
                                    <option value="">Servicio</option>
                                    <?php foreach ( $services as $service ) : ?>
                                        <option value="<?php echo esc_attr( $service['id'] ); ?>">
                                            <?php echo esc_html( trim( ( $service['category'] ? $service['category'] . ' - ' : '' ) . $service['name'] ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="item_quantity[]" min="0" placeholder="Cantidad">
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <button class="button button-primary">Guardar producto</button>
            </form>
        </section>

        <section class="vev-panel">
            <h2>Productos activos</h2>
            <table class="widefat striped">
                <thead><tr><th>Producto</th><th>Tipo</th><th>Precio</th><th>Vigencia</th><th>Incluye</th><th>Empresas</th><th>Descuentos</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ( $products as $product_row ) :
                    $product = product_with_items( $product_row['id'] );
                    $assigned = $wpdb->get_col( $wpdb->prepare(
                        'SELECT c.company_name FROM ' . table_name( 'product_companies' ) . ' pc INNER JOIN ' . table_name( 'companies' ) . ' c ON c.id = pc.company_id WHERE pc.product_id = %d ORDER BY c.company_name ASC',
                        (int) $product['id']
                    ) );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $product['name'] ); ?></strong><br><?php echo esc_html( $product['description'] ); ?></td>
                        <td><?php echo esc_html( $product['type'] === 'package' ? 'Paquete' : 'Consulta por volumen' ); ?></td>
                        <td><?php echo esc_html( money( $product['price'] ) ); ?></td>
                        <td><?php echo ! empty( $product['validity_days'] ) ? esc_html( (int) $product['validity_days'] . ' días' ) : 'Sin vencimiento'; ?></td>
                        <td>
                            <?php foreach ( $product['items'] as $item ) : ?>
                                <div><?php echo esc_html( $item['quantity'] . ' x ' . $item['service_name'] ); ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td><?php echo esc_html( $assigned ? implode( ', ', $assigned ) : 'Todas' ); ?></td>
                        <td>
                            <?php foreach ( $product['rules'] as $rule ) : ?>
                                <div><?php echo esc_html( 'Desde ' . $rule['min_quantity'] . ': ' . $rule['discount_percent'] . '%' ); ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td><?php echo $product['active'] ? 'Activo' : 'Inactivo'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $products ) ) : ?>
                    <tr><td colspan="8">Todavía no hay paquetes ni consultas.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>
    <script>
    (function() {
      var select = document.getElementById('vevProductType');
      var buttons = document.querySelectorAll('[data-vev-admin-type]');
      var panels = document.querySelectorAll('[data-vev-type-panel]');
      function setType(type) {
        if (select) select.value = type;
        buttons.forEach(function(button) {
          button.classList.toggle('is-active', button.getAttribute('data-vev-admin-type') === type);
        });
        panels.forEach(function(panel) {
          panel.classList.toggle('is-active', panel.getAttribute('data-vev-type-panel') === type);
        });
      }
      buttons.forEach(function(button) {
        button.addEventListener('click', function() {
          setType(button.getAttribute('data-vev-admin-type'));
        });
      });
      if (select) {
        select.addEventListener('change', function() {
          setType(select.value);
        });
        setType(select.value);
      }
    })();
    </script>
    <?php
}
