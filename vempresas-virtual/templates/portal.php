<?php
use function VirtualMD\EmpresasVirtual\money;
use function VirtualMD\EmpresasVirtual\product_price_for_quantity;
use function VirtualMD\EmpresasVirtual\product_with_items;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_admin_account = ! empty( $is_admin_account );
$child_accounts = isset( $child_accounts ) && is_array( $child_accounts ) ? $child_accounts : [];
$team_consultations = isset( $team_consultations ) && is_array( $team_consultations ) ? $team_consultations : [];
$active_child_accounts = array_values( array_filter( $child_accounts, static function ( $account ) {
    return ( $account['status'] ?? '' ) === 'active';
} ) );

$available_credits = array_values( array_filter( $credits, function ( $credit ) {
    $not_expired = empty( $credit['expires_at'] ) || strtotime( $credit['expires_at'] ) >= current_time( 'timestamp' );
    return $not_expired && (int) $credit['used_quantity'] < (int) $credit['total_quantity'];
} ) );

$available_consultation_count = array_reduce( $available_credits, static function ( $carry, $credit ) {
    return $carry + max( 0, (int) $credit['total_quantity'] - (int) $credit['used_quantity'] );
}, 0 );

$portal_page_url = get_permalink() ?: home_url( '/' );
?>

<div class="vev-portal">
    <div class="vev-corp-shell">
        <header class="vev-corp-bar">
            <div class="vev-corp-bar__brand">
                <img class="vev-corp-bar__logo" src="https://virtualmd.mx/wp-content/uploads/2025/09/AUDIOS-1.png" alt="VirtualMD">
                <span>Panel corporativo</span>
            </div>
            <a class="vev-logout" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [
                'action'   => 'vev_logout',
                'redirect' => get_permalink(),
            ], admin_url( 'admin-post.php' ) ), 'vev_logout' ) ); ?>">Salir</a>
        </header>

        <main class="vev-corp-main">
            <?php if ( ! empty( $message ) ) : ?>
                <div class="vev-alert vev-alert--success"><?php echo esc_html( $message ); ?></div>
            <?php elseif ( ! empty( $_GET['vev_message'] ) ) : ?>
                <div class="vev-alert"><?php echo esc_html( wp_unslash( $_GET['vev_message'] ) ); ?></div>
            <?php elseif ( ! empty( $_GET['vev_error'] ) ) : ?>
                <div class="vev-alert vev-alert--error"><?php echo esc_html( wp_unslash( $_GET['vev_error'] ) ); ?></div>
            <?php endif; ?>

            <div class="vev-welcome">
                <span class="vev-kicker">Bienvenido, equipo</span>
                <h1>Tu plataforma corporativa</h1>
                <p><?php echo $is_admin_account ? 'Administra compras, usuarios, consultas disponibles y soporte desde un mismo panel.' : 'Agenda tus consultas disponibles y solicita soporte cuando lo necesites.'; ?></p>
            </div>

            <nav class="vev-menu" aria-label="Portal empresarial">
                <?php if ( $is_admin_account ) : ?>
                    <button type="button" data-vev-tab="buy">
                        <span class="vev-menu__icon vev-menu__icon--cyan" aria-hidden="true">+</span>
                        <span class="vev-menu__copy">
                            <strong>Adquiere nuestras consultas</strong>
                            <small>Compra paquetes y servicios para tu equipo</small>
                        </span>
                        <span class="vev-menu__dot" aria-hidden="true"></span>
                    </button>
                    <button type="button" data-vev-tab="team">
                        <span class="vev-menu__icon vev-menu__icon--green" aria-hidden="true">U</span>
                        <span class="vev-menu__copy">
                            <strong>Equipo</strong>
                            <small>Crea usuarios y asigna consultas disponibles</small>
                        </span>
                        <span class="vev-menu__dot" aria-hidden="true"></span>
                    </button>
                <?php endif; ?>
                <button type="button" data-vev-tab="schedule">
                    <span class="vev-menu__icon vev-menu__icon--blue" aria-hidden="true">▦</span>
                    <span class="vev-menu__copy">
                        <strong>Agenda</strong>
                        <small>Agenda tus servicios adquiridos</small>
                    </span>
                    <span class="vev-menu__dot" aria-hidden="true"></span>
                </button>
                <button type="button" data-vev-tab="support">
                    <span class="vev-menu__icon vev-menu__icon--green" aria-hidden="true">✉</span>
                    <span class="vev-menu__copy">
                        <strong>Soporte</strong>
                        <small>Soporte directo de clientes</small>
                    </span>
                    <span class="vev-menu__dot" aria-hidden="true"></span>
                </button>
            </nav>

    <?php if ( $is_admin_account ) : ?>
    <section class="vev-tab-panel" data-vev-panel="buy">
        <button type="button" class="vev-back-menu" data-vev-back-menu>Regresar al menú</button>
        <div class="vev-section-head">
            <h3>Paquetes y consultas asignadas</h3>
            <p>Compra consultas para tu empresa y después asígnalas a usuarios o agenda pacientes.</p>
        </div>
        <div class="vev-product-grid">
            <?php foreach ( $products as $product_row ) :
                $product = product_with_items( $product_row['id'] );
                if ( ! $product || empty( $product['items'] ) ) {
                    continue;
                }
                $base_price = product_price_for_quantity( $product, 1 );
                $product_card_data = [
                    'id'         => (int) $product['id'],
                    'name'       => $product['name'],
                    'type'       => $product['type'],
                    'typeLabel'  => $product['type'] === 'package' ? 'Paquete cerrado' : 'Compra por volumen',
                    'unitPrice'  => (float) $base_price['unit_price'],
                    'validityDays' => max( 0, (int) ( $product['validity_days'] ?? 0 ) ),
                    'priceLabel' => $product['type'] === 'service' ? money( $base_price['unit_price'] ) . ' c/u' : money( $base_price['total'] ) . ' por paquete',
                    'items'      => array_map( static function ( $item ) {
                        return [
                            'serviceName' => $item['service_name'],
                            'quantity'    => (int) $item['quantity'],
                        ];
                    }, $product['items'] ),
                    'rules'      => array_map( static function ( $rule ) {
                        return [
                            'minQuantity'     => (int) $rule['min_quantity'],
                            'discountPercent' => (float) $rule['discount_percent'],
                        ];
                    }, $product['rules'] ?? [] ),
                ];
                ?>
                <article class="vev-product">
                    <div class="vev-product__head">
                        <span class="vev-product-type"><?php echo esc_html( $product_card_data['typeLabel'] ); ?></span>
                        <h4><?php echo esc_html( $product['name'] ); ?></h4>
                        <?php if ( ! empty( $product['description'] ) ) : ?>
                            <p><?php echo esc_html( $product['description'] ); ?></p>
                        <?php else : ?>
                            <p>Consultas listas para comprar, guardar en tu cuenta y agendar cuando tu equipo las necesite.</p>
                        <?php endif; ?>
                    </div>
                    <div class="vev-product__includes">
                        <span>Incluye</span>
                        <ul>
                        <?php foreach ( $product['items'] as $item ) : ?>
                            <li>
                                <strong><?php echo esc_html( $item['quantity'] ); ?> x</strong>
                                <span><?php echo esc_html( $item['service_name'] ); ?></span>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if ( ! empty( $product['rules'] ) ) : ?>
                        <div class="vev-discounts">
                            <?php foreach ( $product['rules'] as $rule ) : ?>
                                <span>
                                    <strong><?php echo esc_html( (float) $rule['discount_percent'] + 0 ); ?>%</strong>
                                    de descuento desde <?php echo esc_html( $rule['min_quantity'] ); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="vev-validity-pill">
                        <?php echo ! empty( $product['validity_days'] ) ? esc_html( 'Vigencia: ' . (int) $product['validity_days'] . ' días' ) : 'Sin vencimiento'; ?>
                    </div>
                    <div class="vev-product-foot">
                        <span class="vev-price-label"><?php echo esc_html( $product['type'] === 'service' ? 'Precio unitario' : 'Precio' ); ?></span>
                        <strong><?php echo esc_html( $product_card_data['priceLabel'] ); ?></strong>
                    </div>
                    <div class="vev-card-cart">
                        <label>
                            <span><?php echo esc_html( $product['type'] === 'service' ? 'Consultas' : 'Paquetes' ); ?></span>
                            <input type="number" class="vev-card-qty" min="1" value="1" inputmode="numeric">
                        </label>
                        <button type="button" class="vev-add-cart" data-product="<?php echo esc_attr( wp_json_encode( $product_card_data ) ); ?>">
                            Añadir a carrito
                        </button>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if ( empty( $products ) ) : ?>
                <p>No hay paquetes empresariales activos.</p>
            <?php endif; ?>
        </div>

        <aside class="vev-cart" id="vevCart" data-portal-url="<?php echo esc_url( $portal_page_url ); ?>">
            <div class="vev-cart__head">
                <div>
                    <span class="vev-kicker">Carrito</span>
                    <h3>Resumen de compra</h3>
                </div>
                <button type="button" class="vev-cart__clear" id="vevCartClear">Vaciar</button>
            </div>
            <div class="vev-cart__empty" id="vevCartEmpty">Añade paquetes o consultas por volumen para continuar.</div>
            <div class="vev-cart__items" id="vevCartItems"></div>
            <div class="vev-cart__footer">
                <span>Total</span>
                <strong id="vevCartTotal">MX$0.00</strong>
                <button type="button" id="vevCartCheckout" disabled>Pagar carrito</button>
            </div>
        </aside>
    </section>
    <?php endif; ?>

    <?php if ( $is_admin_account ) : ?>
    <section class="vev-tab-panel" data-vev-panel="team">
        <button type="button" class="vev-back-menu" data-vev-back-menu>Regresar al menú</button>
        <div class="vev-section-head">
            <h3>Equipo y consultas disponibles</h3>
            <p>Crea usuarios para tu empresa y reparte únicamente las consultas que ya tienes disponibles.</p>
        </div>

        <div class="vev-team-grid">
            <article class="vev-team-card">
                <h4>Crear usuario</h4>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'vev_team_action' ); ?>
                    <input type="hidden" name="action" value="vev_team_create_user">
                    <input type="hidden" name="portal_url" value="<?php echo esc_url( $portal_page_url ); ?>">
                    <input type="hidden" name="portal_panel" value="team">
                    <label><span>Nombre de usuario o área</span><input type="text" name="company_name" required></label>
                    <label><span>Contacto</span><input type="text" name="contact_name"></label>
                    <label><span>Email de acceso</span><input type="email" name="email" required></label>
                    <label><span>Teléfono</span><input type="text" name="phone"></label>
                    <label><span>Contraseña</span><input type="text" name="password" minlength="8" required></label>
                    <button type="submit">Crear usuario</button>
                </form>
            </article>

            <article class="vev-team-card">
                <h4>Asignar consultas</h4>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'vev_team_action' ); ?>
                    <input type="hidden" name="action" value="vev_team_assign_consultations">
                    <input type="hidden" name="portal_url" value="<?php echo esc_url( $portal_page_url ); ?>">
                    <input type="hidden" name="portal_panel" value="team">
                    <label><span>Usuario</span>
                        <select name="child_company_id" required>
                            <option value="">Selecciona usuario</option>
                            <?php foreach ( $active_child_accounts as $child ) : ?>
                                <option value="<?php echo esc_attr( $child['id'] ); ?>"><?php echo esc_html( $child['company_name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span>Consulta disponible</span>
                        <select name="source_credit_id" required>
                            <option value="">Selecciona servicio disponible</option>
                            <?php foreach ( $available_credits as $credit ) :
                                $available = max( 0, (int) $credit['total_quantity'] - (int) $credit['used_quantity'] );
                                ?>
                                <option value="<?php echo esc_attr( $credit['id'] ); ?>">
                                    <?php echo esc_html( $credit['service_name'] . ' (' . $available . ' disponibles)' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span>Cantidad</span><input type="number" name="quantity" min="1" step="1" required></label>
                    <label><span>Nota opcional</span><textarea name="assignment_note" rows="2"></textarea></label>
                    <button type="submit">Asignar consultas</button>
                </form>
            </article>
        </div>

        <div class="vev-subsection">
            <div class="vev-subsection__head">
                <h4>Usuarios de tu empresa</h4>
                <p>Estos usuarios sólo pueden entrar a Agenda y Soporte.</p>
            </div>
            <div class="vev-table-wrap">
                <table>
                    <thead><tr><th>Usuario</th><th>Email</th><th>Estado</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ( $child_accounts as $child ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $child['company_name'] ); ?></strong><br><small><?php echo esc_html( $child['contact_name'] ); ?></small></td>
                            <td><?php echo esc_html( $child['email'] ); ?></td>
                            <td><?php echo esc_html( $child['status'] ); ?></td>
                            <td>
                                <div class="vev-team-actions">
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <?php wp_nonce_field( 'vev_team_action' ); ?>
                                        <input type="hidden" name="action" value="vev_team_user_status">
                                        <input type="hidden" name="portal_url" value="<?php echo esc_url( $portal_page_url ); ?>">
                                        <input type="hidden" name="portal_panel" value="team">
                                        <input type="hidden" name="child_company_id" value="<?php echo esc_attr( $child['id'] ); ?>">
                                        <input type="hidden" name="mode" value="<?php echo esc_attr( $child['status'] === 'active' ? 'inactive' : 'active' ); ?>">
                                        <button type="submit"><?php echo esc_html( $child['status'] === 'active' ? 'Desactivar' : 'Activar' ); ?></button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Se retirarán las consultas no usadas de este usuario.');">
                                        <?php wp_nonce_field( 'vev_team_action' ); ?>
                                        <input type="hidden" name="action" value="vev_team_user_status">
                                        <input type="hidden" name="portal_url" value="<?php echo esc_url( $portal_page_url ); ?>">
                                        <input type="hidden" name="portal_panel" value="team">
                                        <input type="hidden" name="child_company_id" value="<?php echo esc_attr( $child['id'] ); ?>">
                                        <input type="hidden" name="mode" value="deleted">
                                        <button type="submit">Borrar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $child_accounts ) ) : ?>
                        <tr><td colspan="4">Todavía no has creado usuarios.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="vev-subsection">
            <div class="vev-subsection__head">
                <h4>Consultas asignadas a usuarios</h4>
                <p>Puedes recuperar las consultas que todavía no han sido agendadas.</p>
            </div>
            <div class="vev-table-wrap">
                <table>
                    <thead><tr><th>Usuario</th><th>Servicio</th><th>Total</th><th>Usadas</th><th>Disponibles</th><th>Vigencia</th><th>Acción</th></tr></thead>
                    <tbody>
                    <?php foreach ( $team_consultations as $consultation ) :
                        $available = max( 0, (int) $consultation['total_quantity'] - (int) $consultation['used_quantity'] );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $consultation['company_name'] ); ?></strong><br><small><?php echo esc_html( $consultation['email'] ); ?></small></td>
                            <td><?php echo esc_html( $consultation['service_name'] ); ?></td>
                            <td><?php echo esc_html( $consultation['total_quantity'] ); ?></td>
                            <td><?php echo esc_html( $consultation['used_quantity'] ); ?></td>
                            <td><strong><?php echo esc_html( $available ); ?></strong></td>
                            <td><?php echo ! empty( $consultation['expires_at'] ) ? esc_html( mysql2date( 'd/m/Y', $consultation['expires_at'] ) ) : 'Sin vencimiento'; ?></td>
                            <td>
                                <?php if ( $available > 0 && (int) ( $consultation['assigned_by_company_id'] ?? 0 ) === (int) $company['id'] ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Se devolverán sólo las consultas no usadas.');">
                                        <?php wp_nonce_field( 'vev_team_action' ); ?>
                                        <input type="hidden" name="action" value="vev_team_deassign_consultations">
                                        <input type="hidden" name="portal_url" value="<?php echo esc_url( $portal_page_url ); ?>">
                                        <input type="hidden" name="portal_panel" value="team">
                                        <input type="hidden" name="consultation_id" value="<?php echo esc_attr( $consultation['id'] ); ?>">
                                        <button type="submit">Desasignar no usadas</button>
                                    </form>
                                <?php else : ?>
                                    <span>Sin disponibles</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $team_consultations ) ) : ?>
                        <tr><td colspan="7">Todavía no has asignado consultas a usuarios.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="vev-tab-panel" data-vev-panel="schedule">
        <button type="button" class="vev-back-menu" data-vev-back-menu>Regresar al menú</button>
        <div class="vev-section-head">
            <h3>Agenda tus servicios adquiridos</h3>
            <p>Consulta tus servicios disponibles, agrega pacientes y revisa las consultas confirmadas.</p>
        </div>

        <div class="vev-agenda-grid">
            <article class="vev-metric">
                <span>Consultas disponibles</span>
                <strong><?php echo esc_html( $available_consultation_count ); ?></strong>
                <small>Servicios listos para agendar</small>
            </article>
            <article class="vev-metric">
                <span>Consultas agendadas</span>
                <strong><?php echo esc_html( count( $appointments ) ); ?></strong>
                <small>Últimas 100 registradas</small>
            </article>
        </div>

        <div class="vev-subsection">
            <div class="vev-subsection__head">
                <h4>Consultas disponibles</h4>
                <p>Estos son los servicios pagados que tu empresa puede agendar.</p>
            </div>
            <div class="vev-table-wrap">
                <table>
                    <thead><tr><th>Servicio</th><th>Total</th><th>Usadas</th><th>Disponibles</th><th>Vigencia</th></tr></thead>
                    <tbody>
                    <?php foreach ( $credits as $credit ) : ?>
                        <tr>
                            <td><?php echo esc_html( $credit['service_name'] ); ?></td>
                            <td><?php echo esc_html( $credit['total_quantity'] ); ?></td>
                            <td><?php echo esc_html( $credit['used_quantity'] ); ?></td>
                            <td><strong><?php echo esc_html( (int) $credit['total_quantity'] - (int) $credit['used_quantity'] ); ?></strong></td>
                            <td><?php echo ! empty( $credit['expires_at'] ) ? esc_html( mysql2date( 'd/m/Y', $credit['expires_at'] ) ) : 'Sin vencimiento'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $credits ) ) : ?>
                        <tr><td colspan="5">Aún no tienes consultas compradas.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ( empty( $available_credits ) ) : ?>
            <div class="vev-alert">No tienes consultas disponibles para agendar.</div>
        <?php else : ?>
            <div class="vev-subsection">
                <div class="vev-subsection__head">
                    <h4>Agendar varias consultas</h4>
                    <p>Agrega una fila por paciente. Cada cita consume una consulta disponible cuando Amelia confirma la disponibilidad.</p>
                </div>
            <form class="vev-scheduler" id="vevScheduler">
                <div id="vevRows"></div>
                <div class="vev-scheduler-actions">
                    <button type="button" id="vevAddRow">Agregar consulta</button>
                    <button type="submit">Agendar seleccionadas</button>
                </div>
                <div class="vev-results" id="vevScheduleResults"></div>
            </form>

            <template id="vevRowTemplate">
                <div class="vev-schedule-row">
                    <label><span>Servicio pagado</span>
                        <select class="vev-credit" required>
                            <option value="">Selecciona</option>
                            <?php foreach ( $available_credits as $credit ) : ?>
                                <option value="<?php echo esc_attr( $credit['id'] ); ?>" data-service="<?php echo esc_attr( $credit['service_id'] ); ?>">
                                    <?php echo esc_html( $credit['service_name'] . ' (' . ( (int) $credit['total_quantity'] - (int) $credit['used_quantity'] ) . ' disponibles)' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="vev-doctor-pref" role="radiogroup" aria-label="Preferencia de doctor">
                        <button type="button" class="vev-doctor-mode is-active" data-vev-row-doctor-mode="auto" aria-pressed="true">Más horarios</button>
                        <button type="button" class="vev-doctor-mode" data-vev-row-doctor-mode="manual" aria-pressed="false">Yo elijo mi especialista</button>
                    </div>
                    <label class="vev-provider-wrap"><span>Especialista</span><select class="vev-provider" required><option value="">Primero elige horario</option></select></label>
                    <label><span>Fecha</span><input type="date" class="vev-date" required></label>
                    <label><span>Horario</span><select class="vev-time" required><option value="">Primero elige fecha</option></select></label>
                    <label><span>Paciente</span><input type="text" class="vev-name" required></label>
                    <label><span>Email paciente</span><input type="email" class="vev-email" required></label>
                    <label><span>Teléfono</span><input type="tel" class="vev-phone"></label>
                    <button type="button" class="vev-remove-row">Quitar</button>
                </div>
            </template>
            </div>
        <?php endif; ?>

        <div class="vev-subsection">
            <div class="vev-subsection__head">
                <h4>Consultas agendadas</h4>
                <p>Cuando Amelia regresa un link de videollamada, aparece aquí.</p>
            </div>
            <div class="vev-table-wrap">
                <table>
                    <thead><tr><th>Fecha</th><th>Paciente</th><th>Servicio</th><th>Doctor</th><th>Link</th></tr></thead>
                    <tbody>
                    <?php foreach ( $appointments as $appointment ) : ?>
                        <tr>
                            <td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $appointment['booking_start'] ) ); ?></td>
                            <td><?php echo esc_html( $appointment['customer_name'] ); ?><br><small><?php echo esc_html( $appointment['customer_email'] ); ?></small></td>
                            <td><?php echo esc_html( $appointment['service_name'] ); ?></td>
                            <td><?php echo esc_html( $appointment['provider_name'] ); ?></td>
                            <td>
                                <?php if ( $appointment['meeting_url'] ) : ?>
                                    <a href="<?php echo esc_url( $appointment['meeting_url'] ); ?>" target="_blank" rel="noopener">Abrir consulta</a>
                                <?php else : ?>
                                    <span>Link pendiente en Amelia</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $appointments ) ) : ?>
                        <tr><td colspan="5">Aún no hay consultas agendadas.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="vev-tab-panel" data-vev-panel="support">
        <button type="button" class="vev-back-menu" data-vev-back-menu>Regresar al menú</button>
        <div class="vev-section-head">
            <h3>Soporte directo de clientes</h3>
            <p>Estamos listos para ayudarte con compras, consultas disponibles, agenda o acceso empresarial.</p>
        </div>
        <div class="vev-support-grid">
            <a class="vev-support-card" href="mailto:hola@virtualmd.mx">
                <span class="vev-menu__icon vev-menu__icon--green" aria-hidden="true">✉</span>
                <strong>Correo de soporte</strong>
                <small>hola@virtualmd.mx</small>
            </a>
            <a class="vev-support-card" href="https://virtualmd.mx" target="_blank" rel="noopener">
                <span class="vev-menu__icon vev-menu__icon--cyan" aria-hidden="true">+</span>
                <strong>VirtualMD</strong>
                <small>Centro de atención y servicios</small>
            </a>
        </div>
    </section>

    <div class="vev-payment-modal" id="vevPaymentModal" hidden>
        <div class="vev-payment-modal__backdrop" data-vev-close-payment></div>
        <div class="vev-payment-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="vevPaymentTitle">
            <button type="button" class="vev-payment-modal__close" data-vev-close-payment aria-label="Cerrar pago">×</button>
            <div class="vev-section-head">
                <span class="vev-kicker">Pago empresarial</span>
                <h3 id="vevPaymentTitle">Completa tu compra</h3>
                <p>Elige tarjeta o PayPal. Al confirmarse el pago, las consultas quedan guardadas en la cuenta de tu empresa.</p>
            </div>
            <div class="vev-modal-summary" id="vevModalSummary"></div>
            <div class="vev-payment-box" id="vevPaymentBox">
                <div class="vev-payment-methods" aria-label="Método de pago">
                    <button type="button" class="vev-pay-option vev-pay-option--stripe">
                        <span>Tarjeta</span>
                        <small>Stripe</small>
                    </button>
                    <button type="button" class="vev-pay-option vev-pay-option--paypal">
                        <span>PayPal</span>
                        <small>Pago seguro</small>
                    </button>
                </div>
                <div class="vev-stripe-panel" hidden>
                    <div class="vev-stripe-checkout"></div>
                    <div class="vev-payment-status vev-payment-status--stripe" aria-live="polite"></div>
                </div>
                <div class="vev-paypal-panel" hidden>
                    <div class="vev-paypal-buttons"></div>
                    <div class="vev-payment-status vev-payment-status--paypal" aria-live="polite"></div>
                </div>
            </div>
        </div>
    </div>
        </main>
    </div>
</div>
