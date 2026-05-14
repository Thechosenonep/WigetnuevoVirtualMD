<?php
/**
 * Plugin Name: vEmpresas Virtual
 * Description: Portal empresarial para compra masiva de consultas VirtualMD y agendamiento contra disponibilidad de Amelia.
 * Version: 0.2.1
 * Author: VirtualMD
 */

namespace VirtualMD\EmpresasVirtual;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VEV_PLUGIN_FILE', __FILE__ );
define( 'VEV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VEV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VEV_VERSION', '0.2.1' );

require_once VEV_PLUGIN_DIR . 'includes/helpers.php';
require_once VEV_PLUGIN_DIR . 'includes/admin.php';
require_once VEV_PLUGIN_DIR . 'includes/portal.php';

function activate() {
    vev_create_tables();
    update_option( 'vev_plugin_version', VEV_VERSION );
    add_role( 'vev_company', 'Empresa VirtualMD', [
        'read' => true,
    ] );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

function init() {
    if ( get_option( 'vev_plugin_version' ) !== VEV_VERSION ) {
        vev_create_tables();
        update_option( 'vev_plugin_version', VEV_VERSION );
    }

    Admin\init();
    Portal\init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );
