<?php
/**
 * Plugin Name: VM Buscador de Médicos (Team Members)
 * Description: Buscador estético basado en el CPT ctshowcase_member (Team Members). Incluye buscador de médicos y carrusel por especialidades.
 * Author: VirtualMD
 * Version: 1.2.0
 */

if (!defined('ABSPATH')) { exit; }

require_once plugin_dir_path(__FILE__) . 'vm-carrusel-psicologia.php';

class VM_BuscadorMedicos_TM {
  const CPT      = 'ctshowcase_member';
  const META_JOB = 'ctshowcase_job_title';
  const TAX_GROUP = 'ctshowcase_group';

  public function __construct() {
    add_action('rest_api_init', [$this, 'register_rest']);
    add_shortcode('vm_buscador_medicos', [$this, 'shortcode']);
    add_shortcode('vm_carrusel_doctores', [$this, 'shortcode_carrusel']);
    add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    add_action('admin_menu', [$this, 'add_admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);
  }

  public function register_assets() {
    wp_register_style('vm-medicos-style', false, [], '1.1.0');
    wp_register_script('vm-medicos-script', false, [], '1.1.0', true);
    wp_register_style('vm-carrusel-style', false, [], '1.0.0');
    wp_register_script('vm-carrusel-script', false, [], '1.0.0', true);
  }

  public function add_admin_menu() {
    add_options_page(
      'VM Filtros Buscador',
      'VM Filtros Buscador',
      'manage_options',
      'vm-buscador-filtros',
      [$this, 'admin_page']
    );
  }

  public function register_settings() {
    register_setting('vm_buscador_filtros', 'vm_excluded_employees', [
      'type' => 'array',
      'default' => []
    ]);
    register_setting('vm_buscador_filtros', 'vm_excluded_categories', [
      'type' => 'array',
      'default' => []
    ]);
    register_setting('vm_buscador_filtros', 'vm_excluded_parent_categories', [
      'type' => 'array',
      'default' => []
    ]);
  }

  public function admin_page() {
    if (!current_user_can('manage_options')) {
      return;
    }

    if (isset($_POST['vm_save_filters']) && check_admin_referer('vm_buscador_filtros_save')) {
      $excluded_employees = isset($_POST['vm_excluded_employees']) ? array_map('intval', (array)$_POST['vm_excluded_employees']) : [];
      $excluded_categories = isset($_POST['vm_excluded_categories']) ? array_map('sanitize_text_field', (array)$_POST['vm_excluded_categories']) : [];
      $excluded_parent_categories = isset($_POST['vm_excluded_parent_categories']) ? array_map('sanitize_text_field', (array)$_POST['vm_excluded_parent_categories']) : [];
      
      update_option('vm_excluded_employees', $excluded_employees);
      update_option('vm_excluded_categories', $excluded_categories);
      update_option('vm_excluded_parent_categories', $excluded_parent_categories);
      
      echo '<div class="notice notice-success is-dismissible"><p>Filtros guardados correctamente.</p></div>';
    }

    global $wpdb;
    
    $all_employees = $wpdb->get_results(
      "SELECT DISTINCT u.id, u.firstName, u.lastName
       FROM {$wpdb->prefix}amelia_users u
       WHERE u.type = 'provider' AND u.status = 'visible'
       ORDER BY u.firstName ASC, u.lastName ASC"
    );

    $all_categories = $wpdb->get_results(
      "SELECT DISTINCT s.name
       FROM {$wpdb->prefix}amelia_services s
       ORDER BY s.name ASC"
    );

    $all_parent_categories = $wpdb->get_results(
      "SELECT DISTINCT c.name
       FROM {$wpdb->prefix}amelia_categories c
       WHERE c.name IS NOT NULL AND c.name != ''
       ORDER BY c.name ASC"
    );

    $excluded_employees = get_option('vm_excluded_employees', []);
    $excluded_categories = get_option('vm_excluded_categories', []);
    $excluded_parent_categories = get_option('vm_excluded_parent_categories', []);

    ?>
    <div class="wrap">
      <h1>Filtros del Buscador de Médicos</h1>
      <p>Selecciona los empleados, servicios y/o categorías padre que deseas excluir del buscador.</p>
      
      <form method="post" action="">
        <?php wp_nonce_field('vm_buscador_filtros_save'); ?>
        
        <h2>Empleados a excluir</h2>
        <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">
          <?php if (!empty($all_employees)): ?>
            <?php foreach ($all_employees as $employee): ?>
              <label style="display: block; margin: 5px 0;">
                <input 
                  type="checkbox" 
                  name="vm_excluded_employees[]" 
                  value="<?php echo esc_attr($employee->id); ?>"
                  <?php checked(in_array($employee->id, $excluded_employees)); ?>
                >
                <?php echo esc_html($employee->firstName . ' ' . $employee->lastName); ?>
              </label>
            <?php endforeach; ?>
          <?php else: ?>
            <p>No se encontraron empleados.</p>
          <?php endif; ?>
        </div>

        <h2 style="margin-top: 30px;">Servicios a excluir</h2>
        <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">
          <?php if (!empty($all_categories)): ?>
            <?php foreach ($all_categories as $category): ?>
              <?php if ($category->name): ?>
                <label style="display: block; margin: 5px 0;">
                  <input 
                    type="checkbox" 
                    name="vm_excluded_categories[]" 
                    value="<?php echo esc_attr($category->name); ?>"
                    <?php checked(in_array($category->name, $excluded_categories)); ?>
                  >
                  <?php echo esc_html($category->name); ?>
                </label>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php else: ?>
            <p>No se encontraron categorías.</p>
          <?php endif; ?>
        </div>

        <h2 style="margin-top: 30px;">Categorías padre a excluir</h2>
        <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">
          <?php if (!empty($all_parent_categories)): ?>
            <?php foreach ($all_parent_categories as $parent_category): ?>
              <?php if ($parent_category->name): ?>
                <label style="display: block; margin: 5px 0;">
                  <input 
                    type="checkbox" 
                    name="vm_excluded_parent_categories[]" 
                    value="<?php echo esc_attr($parent_category->name); ?>"
                    <?php checked(in_array($parent_category->name, $excluded_parent_categories)); ?>
                  >
                  <?php echo esc_html($parent_category->name); ?>
                </label>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php else: ?>
            <p>No se encontraron categorías padre.</p>
          <?php endif; ?>
        </div>

        <p style="margin-top: 30px;">
          <input type="submit" name="vm_save_filters" class="button button-primary" value="Guardar Filtros">
        </p>
      </form>

      <hr style="margin: 40px 0;">

      <h2>Médicos en Amelia SIN Team Member asociado</h2>
      <p>Estos médicos existen en Amelia (y están visibles) pero su nombre no coincide con ningún Team Member publicado.</p>
      
      <?php
      // 1. Obtener todos los Team Members publicados
      $tm_query = new WP_Query([
          'post_type'      => self::CPT,
          'posts_per_page' => -1,
          'post_status'    => 'publish',
      ]);

      $tm_normalized_names = [];
      if ($tm_query->have_posts()) {
          foreach ($tm_query->get_posts() as $p) {
              // Usamos la misma normalización que en el REST API
              $tm_normalized_names[] = $this->normalize_name($p->post_title);
          }
      }

      // 2. Comparar con $all_employees (ya obtenido arriba desde Amelia)
      $missing_in_tm = [];
      if (!empty($all_employees)) {
          foreach ($all_employees as $emp) {
              $full_name = $emp->firstName . ' ' . $emp->lastName;
              $norm = $this->normalize_name($full_name);
              
              if (!in_array($norm, $tm_normalized_names)) {
                  $missing_in_tm[] = $emp;
              }
          }
      }
      ?>

      <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">
        <?php if (!empty($missing_in_tm)): ?>
          <table class="widefat fixed striped">
            <thead>
              <tr>
                <th>ID Amelia</th>
                <th>Nombre en Amelia</th>
                <th>Nombre Normalizado (para coincidencia)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($missing_in_tm as $missing): 
                  $f_name = $missing->firstName . ' ' . $missing->lastName;
                  $n_name = $this->normalize_name($f_name);
              ?>
                <tr>
                  <td><?php echo esc_html($missing->id); ?></td>
                  <td><strong><?php echo esc_html($f_name); ?></strong></td>
                  <td><code><?php echo esc_html($n_name); ?></code></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p>¡Genial! Todos los médicos de Amelia tienen su correspondiente Team Member.</p>
        <?php endif; ?>
      </div>

    </div>
    <?php
  }

  /** REST: /wp-json/vm/v1/doctores */
  public function register_rest() {
    // Endpoint principal del buscador
    register_rest_route('vm/v1', '/doctores', [
      'methods'  => 'GET',
      'callback' => function(\WP_REST_Request $req) {
        global $wpdb;

        $excluded_employees = get_option('vm_excluded_employees', []);
        $excluded_categories = get_option('vm_excluded_categories', []);
        $excluded_parent_categories = get_option('vm_excluded_parent_categories', []);

        $team_members_query = new WP_Query([
            'post_type'      => self::CPT,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ]);

        $team_members_map = [];
        if ($team_members_query->have_posts()) {
            foreach ($team_members_query->get_posts() as $post) {
                $normalized_title = $this->normalize_name($post->post_title);
                $team_members_map[$normalized_title] = $post->ID;
            }
        }
        wp_reset_postdata();

        $where_conditions = ["u.type = 'provider'", "u.status = 'visible'"];
        
        if (!empty($excluded_employees)) {
          $placeholders = implode(',', array_fill(0, count($excluded_employees), '%d'));
          $where_conditions[] = $wpdb->prepare("u.id NOT IN ($placeholders)", $excluded_employees);
        }

        $where_clause = implode(' AND ', $where_conditions);

        $provider_services = $wpdb->get_results(
            "
            SELECT u.id, u.firstName, u.lastName, u.email, s.name AS specialty, c.name AS category_name
            FROM {$wpdb->prefix}amelia_users u
            LEFT JOIN {$wpdb->prefix}amelia_providers_to_services pts ON u.id = pts.userId
            LEFT JOIN {$wpdb->prefix}amelia_services s ON pts.serviceId = s.id
            LEFT JOIN {$wpdb->prefix}amelia_categories c ON s.categoryId = c.id
            WHERE $where_clause
            ORDER BY u.firstName ASC, u.lastName ASC
            "
        );
        
        $providers_data = [];
        foreach ($provider_services as $service_row) {
          $category_name = isset($service_row->category_name) ? (string) $service_row->category_name : '';
          $specialty_name = isset($service_row->specialty) ? (string) $service_row->specialty : '';

          if (($specialty_name && in_array($specialty_name, $excluded_categories, true)) || ($category_name && in_array($category_name, $excluded_parent_categories, true))) {
            continue;
          }

            $provider_id = $service_row->id;
            if (!isset($providers_data[$provider_id])) {
                $providers_data[$provider_id] = [
                    'id'            => $provider_id,
                    'firstName'     => $service_row->firstName,
                    'lastName'      => $service_row->lastName,
                    'email'         => $service_row->email,
                    'especialidades' => [], // These are services
                    'categorias'     => [], // These are parent categories
                'servicios_por_categoria' => [],
                ];
            }
            if ($service_row->specialty) {
                $providers_data[$provider_id]['especialidades'][] = $service_row->specialty;
            }
            if ($service_row->category_name) {
                $providers_data[$provider_id]['categorias'][] = $service_row->category_name;
            }
            if ($service_row->specialty && $service_row->category_name) {
              $providers_data[$provider_id]['servicios_por_categoria'][] = [
                'servicio' => $service_row->specialty,
                'categoria' => $service_row->category_name,
              ];
            }
        }

        $rows = [];
        if (!empty($providers_data)) {
          foreach ($providers_data as $provider) {
            $nombre_completo_amelia = $provider['firstName'] . ' ' . $provider['lastName'];
            $nombre_normalizado_amelia = $this->normalize_name($nombre_completo_amelia);
            
            $img = '';
            $permalink = '#';
            $team_member = [
              'id'        => null,
              'cargo'     => '',
              'resumen'   => '',
              'contenido' => '',
              'enlace'    => '',
            ];

            if (isset($team_members_map[$nombre_normalizado_amelia])) {
                $post_id = $team_members_map[$nombre_normalizado_amelia];
                $img = get_the_post_thumbnail_url($post_id, 'medium') ?: '';
                $permalink = get_permalink($post_id);

                $post = get_post($post_id);
                if ($post) {
                    $job_title = get_post_meta($post_id, self::META_JOB, true);
                    $excerpt = has_excerpt($post_id) ? get_the_excerpt($post_id) : wp_trim_words(strip_tags($post->post_content), 32);
                    $content = apply_filters('the_content', $post->post_content);

                    $team_member = [
                      'id'        => $post_id,
                      'cargo'     => wp_strip_all_tags((string) $job_title),
                      'resumen'   => wp_strip_all_tags((string) $excerpt),
                      'contenido' => wp_kses_post($content),
                      'enlace'    => esc_url_raw($permalink),
                    ];
                }
            }

            $rows[] = [
              'id'               => $provider['id'],
              'nombre'           => $provider['firstName'],
              'primer_apellido'  => $provider['lastName'],
              'nombre_completo'  => trim($nombre_completo_amelia),
              'especialidades'   => array_values(array_unique($provider['especialidades'])),
              'categorias'       => array_values(array_unique($provider['categorias'])),
              'servicios_por_categoria' => array_values(array_map('unserialize', array_unique(array_map('serialize', $provider['servicios_por_categoria'])))),
              'imagen'           => esc_url_raw($img),
              'url'              => esc_url_raw($permalink),
              'email'            => sanitize_email($provider['email']),
              'team_member'      => $team_member,
            ];
          }
        }
        
        $q = sanitize_text_field($req->get_param('q'));
        if ($q) {
            $filtered_rows = [];
            $normalized_query = $this->normalize_text($q);
            foreach ($rows as $row) {
                $specialty_match = false;
                if (!empty($row['especialidades'])) {
                    foreach ($row['especialidades'] as $spec) {
                        if (strpos($this->normalize_text($spec), $normalized_query) !== false) {
                            $specialty_match = true;
                            break;
                        }
                    }
                }

                if (
                    strpos($this->normalize_text($row['nombre_completo']), $normalized_query) !== false ||
                    $specialty_match
                ) {
                    $filtered_rows[] = $row;
                }
            }
            return rest_ensure_response($filtered_rows);
        }

        return rest_ensure_response($rows);
      },
      'permission_callback' => '__return_true',
    ]);

    // Endpoint para el carrusel: /wp-json/vm/v1/especialidades
    register_rest_route('vm/v1', '/especialidades', [
      'methods'  => 'GET',
      'callback' => function(\WP_REST_Request $req) {
        global $wpdb;

        $excluded_employees = get_option('vm_excluded_employees', []);
        $excluded_categories = get_option('vm_excluded_categories', []);
        $excluded_parent_categories = get_option('vm_excluded_parent_categories', []);

        // Obtener Team Members
        $team_members_query = new WP_Query([
            'post_type'      => self::CPT,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ]);

        $team_members_map = [];
        if ($team_members_query->have_posts()) {
            foreach ($team_members_query->get_posts() as $post) {
                $normalized_title = $this->normalize_name($post->post_title);
                $team_members_map[$normalized_title] = $post->ID;
            }
        }
        wp_reset_postdata();

        // Preparar condiciones WHERE
        $where_conditions = ["u.type = 'provider'", "u.status = 'visible'"];
        
        if (!empty($excluded_employees)) {
          $placeholders = implode(',', array_fill(0, count($excluded_employees), '%d'));
          $where_conditions[] = $wpdb->prepare("u.id NOT IN ($placeholders)", $excluded_employees);
        }

        $where_clause = implode(' AND ', $where_conditions);

        // Obtener todos los proveedores y sus servicios
        $provider_services = $wpdb->get_results(
            "
          SELECT u.id, u.firstName, u.lastName, u.email, s.name AS specialty, c.name AS category_name
            FROM {$wpdb->prefix}amelia_users u
            LEFT JOIN {$wpdb->prefix}amelia_providers_to_services pts ON u.id = pts.userId
            LEFT JOIN {$wpdb->prefix}amelia_services s ON pts.serviceId = s.id
          LEFT JOIN {$wpdb->prefix}amelia_categories c ON s.categoryId = c.id
            WHERE $where_clause
            ORDER BY u.firstName ASC, u.lastName ASC
            "
        );

        // Agrupar por especialidad
        $especialidades_data = [];
        
        foreach ($provider_services as $service_row) {
            $specialty = $service_row->specialty;
          $parent_category = isset($service_row->category_name) ? (string) $service_row->category_name : '';
            
          // Saltar si no hay especialidad, si el servicio está excluido o si su categoría padre está excluida
          if (!$specialty || in_array($specialty, $excluded_categories, true) || ($parent_category && in_array($parent_category, $excluded_parent_categories, true))) {
                continue;
            }

            if (!isset($especialidades_data[$specialty])) {
                $especialidades_data[$specialty] = [
                    'nombre' => $specialty,
                    'doctores' => []
                ];
            }

            // Verificar si el doctor ya está en esta especialidad
            $provider_id = $service_row->id;
            $already_added = false;
            foreach ($especialidades_data[$specialty]['doctores'] as $doctor) {
                if ($doctor['id'] === $provider_id) {
                    $already_added = true;
                    break;
                }
            }

            if (!$already_added) {
                $nombre_completo = $service_row->firstName . ' ' . $service_row->lastName;
                $nombre_normalizado = $this->normalize_name($nombre_completo);
                
                $img = '';
                if (isset($team_members_map[$nombre_normalizado])) {
                    $post_id = $team_members_map[$nombre_normalizado];
                    $img = get_the_post_thumbnail_url($post_id, 'medium') ?: '';
                }

                $especialidades_data[$specialty]['doctores'][] = [
                    'id' => $provider_id,
                    'nombre' => $service_row->firstName,
                    'apellido' => $service_row->lastName,
                    'nombre_completo' => trim($nombre_completo),
                    'imagen' => esc_url_raw($img),
                ];
            }
        }

        // Filtrar por especialidad si se proporciona
        $especialidad_param = sanitize_text_field($req->get_param('especialidad'));
        if ($especialidad_param && isset($especialidades_data[$especialidad_param])) {
            return rest_ensure_response($especialidades_data[$especialidad_param]);
        }

        // Retornar todas las especialidades con sus doctores
        return rest_ensure_response(array_values($especialidades_data));
      },
      'permission_callback' => '__return_true',
    ]);
  }

  /** Shortcode [vm_buscador_medicos] */
  public function shortcode($atts) {
    wp_enqueue_style('vm-medicos-style');
    wp_enqueue_script('vm-medicos-script');

    $css = <<<'CSS'
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
:root {
    --vm-dark-blue: #05038C;
    --vm-icon-blue: #60C3E8;
    --vm-text-blue: #1D1B79;
    --vm-body-text: #26304B;
    --anim-speed: 0.34s;
    --anim-ease: cubic-bezier(0.4, 0, 0.2, 1);
    --hover-ease: cubic-bezier(0.22, 0.68, 0.35, 1);
    --anim-collapse-speed: 0.45s;
    --results-height: clamp(420px, 62vh, 640px);
    --vm-surface: rgba(255,255,255,0.86);
}

/* ============================= */
/* CLASE REUTILIZABLE - FORMATO IDÉNTICO A "Agendar con nosotros..." */
/* ============================= */
.vm-text-format {
  white-space: nowrap !important;
  display: inline-block;
  font-family: "dm-sans", sans-serif !important;
  font-weight: 600 !important;
  color: #05038C !important;
  line-height: 1.2;
}

/* Desktop */
@media (min-width: 768px) {
  .vm-text-format {
    font-size: 3.5rem !important;
  }
}

/* Móvil */
@media (max-width: 767px) {
  .vm-text-format {
    font-size: 2.8rem !important;
  }
}

.vm-mdc { --gap: 1.5rem; --card-radius: 1rem; --shadow: 0 8px 24px rgba(0,0,0,.08); --results-height: 720px; --vm-card-width: 280px; margin: clamp(2.5rem, 5vw, 4rem) auto 4rem; }
.vm-mdc .vm-wrap { max-width: 1200px; margin: 0 auto; padding: 1rem; text-align: center; font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: var(--vm-body-text); display: flex; flex-direction: column; align-items: center; box-sizing: border-box; }
.vm-mdc * { box-sizing: border-box; }
.vm-mdc .vm-main-title { font-size: clamp(2rem, 5vw, 2.45rem); color: var(--vm-dark-blue); font-weight: 800; margin-bottom: 2.1rem; letter-spacing: -0.01em; }

.vm-mdc.is-expanded .vm-search-assembly { max-width: 660px; }
.vm-search-assembly { display: flex; align-items: center; justify-content: center; gap: 4px; cursor: pointer; width: 100%; max-width: 500px; margin: 0 auto; transition: max-width var(--anim-speed) var(--anim-ease), transform var(--anim-speed) var(--anim-ease); }
.vm-mdc:not(.vm-ready) .vm-wrap { opacity: 0; visibility: hidden; }
.vm-mdc.vm-ready .vm-wrap { opacity: 1; visibility: visible; transition: opacity 0.24s ease; }
.vm-mdc:not(.vm-ready) .vm-icon-circle { background: transparent; box-shadow: none; }
.vm-search-assembly:hover { transform: translateY(-2px); }
.vm-central-group { display: flex; align-items: center; flex-grow: 1; gap: 10px; }
.vm-text-button { display: flex; align-items: center; justify-content: center; flex-grow: 1; height: 76px; border: 2.5px solid rgba(5,3,140,0.45); border-radius: 999px; position: relative; background: rgba(255,255,255,0.95); padding: 0 1.25rem; transition: height var(--anim-speed) var(--anim-ease), border-color var(--anim-speed) var(--anim-ease), box-shadow var(--anim-speed) var(--anim-ease), transform 0.45s var(--hover-ease); overflow: hidden; box-shadow: 0 15px 35px rgba(5,3,140,0.12); }
.vm-mdc.is-expanded .vm-text-button { height: 76px; box-shadow: 0 10px 24px rgba(5,3,140,0.14); border-color: rgba(5,3,140,0.32); }
.vm-search-assembly:hover .vm-text-button { transform: translateY(-3px); box-shadow: 0 20px 44px rgba(5,3,140,0.18); border-color: rgba(5,3,140,0.35); }
.vm-text-button > * { position: absolute; top: 50%; left: 50%; width: 95%; transform: translate(-50%, -50%); transition: opacity var(--anim-speed) var(--anim-ease), transform var(--anim-speed) var(--anim-ease); }
.vm-initial-text { color: var(--vm-text-blue); font-size: 1.12rem; font-weight: 600; line-height: 1.35; text-align: center; opacity: 1; transform: translate(-50%, -50%) scale(1); white-space: normal; }
.vm-mdc.is-expanded .vm-initial-text { opacity: 0; transform: translate(-50%, -50%) scale(0.84); pointer-events: none; }
.vm-search-input { opacity: 0; transform: translate(-50%, 90%); pointer-events: none; border: none; background: none; outline: none; font-size: 1.35rem; font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-weight: 600; color: var(--vm-dark-blue); text-align: center; width: 100%; letter-spacing: 0.01em; }
.vm-search-input::placeholder { color: rgba(29,27,121,0.65); font-size: 1.35rem; }
.vm-mdc.is-expanded .vm-search-input { opacity: 1; transform: translate(-50%, -50%); pointer-events: auto; }
.vm-deco-wrapper { width: 44px; height: 96px; flex-shrink: 0; transition: transform var(--anim-speed) var(--anim-ease), opacity var(--anim-speed) var(--anim-ease); }
.vm-search-assembly:hover .vm-deco-wrapper { transform: scale(0.94); opacity: 0.92; }
.vm-mdc.is-expanded .vm-deco-wrapper { transform: scale(0.9); opacity: 0.78; }
.vm-icon-circle img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; }
.vm-mdc.is-expanded .vm-icon-circle { transform: scale(0.88); }
.vm-search-assembly:hover .vm-icon-circle { transform: scale(0.94); }
.vm-arc-deco { width: 100%; height: 100%; stroke: rgba(5,3,140,0.55); stroke-width: 10; fill: none; stroke-linecap: round; }
.vm-arrow-shape { width: 24px; height: 24px; flex-shrink: 0; position: relative; transition: transform var(--anim-speed) var(--anim-ease); }
.vm-mdc.is-expanded .vm-arrow-shape { transform: scale(0.9); }
.vm-arrow-shape svg { position: absolute; top: 0; left: 0; fill: var(--vm-dark-blue); transition: opacity var(--anim-speed) var,--anim-ease), transform var(--anim-speed) var,--anim-ease); }
.vm-arrow-right { opacity: 1; transform: rotate(0deg); }
.vm-arrow-down { opacity: 0; transform: rotate(-90deg); }
.vm-mdc.is-expanded .vm-arrow-right { opacity: 0; transform: rotate(90deg); }
.vm-mdc.is-expanded .vm-arrow-down { opacity: 1; transform: rotate(0deg); }

.vm-collapsible-content { max-height: 0; overflow: hidden; opacity: 0; transform: translateY(-20px) scale(0.97); transition: max-height var(--anim-collapse-speed) var(--anim-ease), margin-top var(--anim-collapse-speed) var(--anim-ease), opacity 0.4s var(--anim-ease), transform 0.4s var(--anim-ease); margin-top: 0; display: flex; flex-direction: column; align-items: center; gap: .6rem; width: 100%; }
.vm-mdc.is-expanded .vm-collapsible-content { max-height: 1200px; margin-top: clamp(1.05rem, 3vw, 1.65rem); overflow: visible; opacity: 1; transform: translateY(0) scale(1); }

.vm-results-panel {
    width: 100%;
    max-width: min(100%, 980px);
    background: linear-gradient(145deg, rgba(255,255,255,0.94) 0%, rgba(246,249,255,0.98) 100%);
    border-radius: calc(var(--card-radius) + 1.1rem);
    box-shadow: 0 30px 80px rgba(5,3,140,0.18);
    min-height: 0 !important;
    height: auto !important;
    max-height: none !important;
    margin-bottom: 0;
    padding: 1.5rem 1.4rem;
    display: flex;
    flex-direction: column;
    gap: 1.35rem;
    transform: scale(0.98);
    transform-origin: top center;
    opacity: 0;
    transition: opacity var(--anim-speed) var(--anim-ease), transform var(--anim-speed) var,--anim-ease), box-shadow var(--anim-speed) var,--anim-ease), border-radius var(--anim-speed) var,--anim-ease);
    position: relative;
    overflow: visible;
    box-sizing: border-box;
}

.vm-results-panel::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 110px;
    background: linear-gradient(180deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.92) 70%, #ffffff 100%);
    pointer-events: none;
    border-bottom-left-radius: inherit;
    border-bottom-right-radius: inherit;
    z-index: -1;
}
.vm-mdc.is-expanded .vm-results-panel { opacity: 1; transform: scale(1); }
.vm-mdc.show-detail .vm-results-panel {
    box-shadow: 0 40px 90px rgba(5,3,140,0.22);
    border-radius: 2.4rem;
}

.vm-categories-wrapper { width: 100%; max-width: min(100%, 980px); margin: 0 auto; display: flex; gap: .5rem; justify-content: flex-start; flex-wrap: wrap; }
.vm-categories-bar { position: relative; display: flex; align-items: center; gap: .45rem; justify-content: flex-start; box-sizing: border-box; flex-wrap: wrap; margin-bottom: .5rem; }
.vm-services-bar { position: relative; display: none; align-items: center; gap: .45rem; justify-content: flex-start; box-sizing: border-box; flex-wrap: wrap; margin-bottom: .5rem; }
.vm-services-bar.is-visible { display: flex; }
.vm-categories-inner { display: inline-flex; align-items: center; gap: .45rem; color: var(--vm-dark-blue); min-width: 0; flex-shrink: 1; max-width: 100%; }
.vm-services-inner { display: inline-flex; align-items: center; gap: .45rem; color: var(--vm-dark-blue); min-width: 0; flex-shrink: 1; max-width: 100%; }
.vm-categories-bar[hidden], .vm-services-bar[hidden] { display: none !important; }
.vm-categories-toggle { position: relative; display: inline-flex; align-items: center; gap: .55rem; border-radius: 999px; border: 2px solid rgba(5,3,140,0.28); background: rgba(255,255,255,0.94); color: var(--vm-dark-blue); font-family: 'DM Sans', 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-weight: 700; font-size: 1rem; padding: .55rem 1.3rem; cursor: pointer; transition: background var(--anim-speed) var(--anim-ease), color var(--anim-speed) var,--anim-ease), border-color var(--anim-speed) var,--anim-ease), box-shadow var(--anim-speed) var,--anim-ease); box-shadow: 0 12px 28px rgba(5,3,140,0.12); max-width: 100%; min-width: 0; }
.vm-toggle-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; min-width: 0; flex-shrink: 1; }
.vm-categories-caret { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; cursor: pointer; position: relative; flex-shrink: 0; }
.vm-categories-caret::before { content: ''; width: 0; height: 0; border-top: 7px solid transparent; border-bottom: 7px solid transparent; border-left: 9px solid currentColor; transition: transform var(--anim-speed) var,--anim-ease); transform-origin: center; }
.vm-categories-bar.is-open .vm-categories-caret::before { transform: rotate(90deg); }
.vm-services-bar.is-open .vm-services-caret::before { transform: rotate(90deg); }
.vm-categories-toggle:hover { box-shadow: 0 18px 36px rgba(5,3,140,0.16); border-color: rgba(5,3,140,0.45); }
.vm-categories-toggle:focus-visible { outline: 3px solid var(--vm-icon-blue); outline-offset: 3px; }
.vm-categories-toggle.is-active { background: var(--vm-dark-blue); color: #fff; border-color: var(--vm-dark-blue); box-shadow: 0 16px 36px rgba(5,3,140,0.24); }
.vm-categories-clear { display: none; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; border: 2px solid rgba(5,3,140,0.28); background: rgba(255,255,255,0.94); color: var(--vm-dark-blue); font-size: 1.1rem; font-weight: 700; cursor: pointer; transition: background var(--anim-speed) var,--anim-ease), color var(--anim-speed) var,--anim-ease), border-color var(--anim-speed) var,--anim-ease); box-shadow: 0 12px 28px rgba(5,3,140,0.12); flex-shrink: 0; }
.vm-categories-clear:hover { box-shadow: 0 18px 36px rgba(5,3,140,0.16); border-color: rgba(5,3,140,0.45); }
.vm-categories-clear:focus-visible { outline: 3px solid var(--vm-icon-blue); outline-offset: 3px; }
.vm-categories-bar.has-selection .vm-categories-clear { display: inline-flex; }
.vm-services-bar.has-selection .vm-services-clear { display: inline-flex; }
.vm-categories-copy { display: none; align-items: center; justify-content: center; border-radius: 999px; border: 2px solid rgba(5,3,140,0.28); background: rgba(255,255,255,0.94); color: var(--vm-dark-blue); font-family: 'DM Sans', 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-weight: 700; font-size: .92rem; padding: .5rem 1rem; cursor: pointer; transition: background var(--anim-speed) var(--anim-ease), color var(--anim-speed) var(--anim-ease), border-color var(--anim-speed) var(--anim-ease), box-shadow var(--anim-speed) var(--anim-ease); box-shadow: 0 12px 28px rgba(5,3,140,0.12); white-space: nowrap; flex-shrink: 0; margin: 0 0 .5rem; flex-basis: 100%; width: fit-content; }
.vm-categories-copy:hover { box-shadow: 0 18px 36px rgba(5,3,140,0.16); border-color: rgba(5,3,140,0.45); }
.vm-categories-copy:focus-visible { outline: 3px solid var(--vm-icon-blue); outline-offset: 3px; }
.vm-categories-copy:not([hidden]) { display: inline-flex; }
.vm-categories-menu { list-style: none; margin: .6rem 0 0; padding: .55rem; position: absolute; left: 0; top: calc(100% + .45rem); min-width: 220px; background: rgba(255,255,255,0.97); border-radius: 1rem; border: 1px solid rgba(5,3,140,0.14); box-shadow: 0 20px 44px rgba(5,3,140,0.18); display: none; flex-direction: column; gap: .3rem; z-index: 6; max-height: 280px; overflow-y: auto; }
.vm-categories-bar.is-open .vm-categories-menu { display: flex; }
.vm-services-bar.is-open .vm-services-menu { display: flex; }
.vm-category-option { width: 100%; border: none; background: none; text-align: left; font: inherit; color: rgba(38,48,75,0.92); padding: .55rem .75rem; border-radius: .85rem; cursor: pointer; transition: background .22s var(--anim-ease), color .22s var(--anim-ease); white-space: normal; word-wrap: break-word; }
.vm-category-option:hover, .vm-category-option:focus-visible { outline: none; background: rgba(96,195,232,0.22); color: var(--vm-dark-blue); }
.vm-category-option.is-selected { background: rgba(96,195,232,0.32); color: var(--vm-dark-blue); font-weight: 700; }

.vm-results-scroll {
    flex: 1 1 auto;
    overflow-y: auto;
    padding-right: .85rem;
    margin-right: 0;
    transition: max-height var(--anim-speed) var,--anim-ease), padding var(--anim-speed) var,--anim-ease);
    width: 100%;
    min-height: 0;
    height: auto;
    max-height: var(--results-height, 640px) !important;
}
.vm-results-scroll::-webkit-scrollbar { width: 10px; }
.vm-results-scroll::-webkit-scrollbar-thumb { background: rgba(5,3,140,0.18); border-radius: 999px; }
.vm-results-scroll::-webkit-scrollbar-track { background: rgba(5,3,140,0.05); border-radius: 999px; }
.vm-mdc.show-detail .vm-results-scroll {
    overflow-y: auto;
    padding-right: .85rem;
    margin-right: 0;
    max-height: none !important;
}

.vm-no-scroll .vm-results-scroll {
    max-height: none !important;
    overflow-y: visible;
    padding-right: 0;
}

.vm-results-panel .vm-empty { display: none; align-items: center; justify-content: center; font-size: 1rem; font-weight: 600; color: rgba(38,48,75,0.88); background: rgba(255,255,255,0.88); border-radius: calc(var(--card-radius) + .35rem); box-shadow: inset 0 0 0 1px rgba(5,3,140,0.08); padding: 1.5rem; min-height: 100%; flex: 1 0 auto; width: 100%; }

.vm-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(var(--vm-card-width, 280px), 1fr));
    gap: var(--gap);
    align-items: stretch;
    text-align: left;
    transition: opacity var(--anim-speed) var,--anim-ease), transform var(--anim-speed) var,--anim-ease);
    min-height: 100%;
    font-family: 'DM Sans', 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    width: 100%;
    align-content: flex-start;
}
.vm-card {
    position: relative;
    display: block;
    border-radius: var(--card-radius);
    transition: transform var(--anim-speed) var,--anim-ease), box-shadow var(--anim-speed) var,--anim-ease);
    width: 100%;
}
.vm-card.is-expanded { width: 100%; }
.vm-card-summary { display: flex; flex-direction: column; justify-content: flex-start; align-items: center; gap: .7rem; width: 100%; padding: 1.35rem 1.1rem; border: 1px solid rgba(5,3,140,0.08); border-radius: calc(var(--card-radius) + .25rem); background: rgba(255,255,255,0.98); box-shadow: 0 18px 40px rgba(5,3,140,0.12); cursor: pointer; text-decoration: none; color: inherit; font: inherit; position: relative; z-index: 1; transition: transform 0.48s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.48s cubic-bezier(0.16, 1, 0.3, 1); will-change: transform, box-shadow; }
.vm-card-summary:hover { transform: translateY(-6px); box-shadow: 0 26px 52px rgba(5,3,140,0.18); }
.vm-card-summary:focus-visible { outline: 3px solid var(--vm-icon-blue); outline-offset: 4px; }
.vm-card .vm-avatar { width: 90px; height: 90px; border-radius: 32px; overflow: hidden; margin: 0 auto .3rem; background: linear-gradient(135deg, rgba(96,195,232,0.18), rgba(5,3,140,0.16)); display: grid; place-items: center; font-weight: 700; color: rgba(5,3,140,0.7); font-size: 1.6rem; letter-spacing: .04em; }
.vm-card .vm-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.vm-card .vm-name { font-size: 1.1rem; font-weight: 700; line-height: 1.25; margin: 0; color: #0d1240; text-align: center; letter-spacing: -0.01em; }
.vm-card .vm-pills-container { display: flex; flex-wrap: wrap; justify-content: center; gap: .35rem; margin-top: auto; padding-top: .55rem; }
.vm-card .vm-pills-container.is-few { align-self: center; justify-content: center; text-align: center; width: auto; max-width: 100%; margin-top: .15rem; }
.vm-card .vm-pill { display: inline-block !important; padding: .28rem .65rem !important; background: rgba(5,3,140,.08) !important; color: var(--vm-dark-blue) !important; border-radius: 999px !important; font-size: .78rem !important; font-weight: 600 !important; line-height: 1.2 !important; border: none !important; text-decoration: none !important; }
.vm-mdc .vm-empty { text-align: center; color: rgba(38,48,75,0.85); padding: 1.35rem; }
.vm-card.is-hidden { opacity: 0; transform: scale(0.9); pointer-events: none; filter: blur(1px); transition: opacity var(--anim-speed) var,--anim-ease), transform var(--anim-speed) var,--anim-ease), filter var(--anim-speed) var,--anim-ease); }
.vm-card.is-returning { animation: vm-card-return 0.48s var(--anim-ease); }

@keyframes vm-card-return {
  0% { opacity: 0; transform: translateY(14px) scale(0.94); }
  100% { opacity: 1; transform: translateY(0) scale(1); }
}

.vm-card-detail { position: relative; margin-top: 0; opacity: 0; pointer-events: none; transform: translateY(24px); max-height: 0; overflow: hidden; transition: opacity var(--anim-speed) var,--anim-ease), transform var(--anim-speed) var,--anim-ease), max-height var(--anim-collapse-speed) var,--anim-ease); }
.vm-card.is-expanded { grid-column: 1 / -1; z-index: 3; }
.vm-card.is-animating { z-index: 4; }
.vm-card.is-expanded .vm-card-summary { position: absolute; inset: 0; opacity: 0; transform: translateY(-18px) scale(0.94); pointer-events: none; }
.vm-card.is-expanded .vm-card-detail { opacity: 1; pointer-events: auto; transform: translateY(0); max-height: 9999px; }
.vm-card.is-opening .vm-card-detail { opacity: 0; transform: translateY(18px); }
.vm-card.is-opening .vm-detail-card { opacity: 0; transform: translateY(12px); }
.vm-detail-card { background: linear-gradient(145deg, #ffffff 0%, #f6f8ff 100%); border-radius: calc(var(--card-radius) + .9rem); box-shadow: 0 32px 70px rgba(5,3,140,0.18); padding: clamp(1.7rem, 2.9vw, 2.65rem); display: flex; flex-direction: column; gap: 1.7rem; position: relative; overflow: hidden; will-change: transform, opacity; font-family: 'DM Sans', 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
.vm-detail-card::after { content: ''; position: absolute; inset: 0; pointer-events: none; background: radial-gradient(circle at top right, rgba(96,195,232,0.24), transparent 55%), radial-gradient(circle at bottom left, rgba(5,3,140,0.16), transparent 60%); opacity: .85; }
.vm-detail-content { display: flex; flex-direction: column; gap: 1.45rem; }
.vm-detail-top { display: flex; flex-wrap: wrap; gap: 1.6rem; align-items: center; }
.vm-detail-avatar { width: clamp(124px, 18vw, 168px); height: clamp(124px, 18vw, 168px); border-radius: 40px; overflow: hidden; background: rgba(255,255,255,0.9); display: grid; place-items: center; font-weight: 700; font-size: clamp(2.1rem, 3vw, 2.7rem); color: var(--vm-dark-blue); box-shadow: inset 0 0 0 1px rgba(5,3,140,0.08), 0 18px 32px rgba(5,3,140,0.12); }
.vm-detail-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.vm-detail-texts { flex: 1 1 260px; display: flex; flex-direction: column; gap: .5rem; text-align: left; }
.vm-detail-name { font-size: clamp(1.7rem, 3.4vw, 2.35rem); font-weight: 800; color: #06083B; margin: 0; letter-spacing: -0.01em; }
.vm-detail-job { font-size: clamp(1rem, 2.1vw, 1.24rem); font-weight: 600; color: rgba(5,3,140,0.68); margin: 0; letter-spacing: .02em; text-transform: uppercase; line-height: 1.35; }
.vm-detail-specialties { display: flex; flex-wrap: wrap; gap: .45rem; margin-top: .65rem; }
.vm-detail-specialties .vm-pill { font-size: .86rem !important; padding: .32rem .72rem !important; }
.vm-detail-body { font-size: 1.05rem; line-height: 1.72; color: rgba(38,48,75,0.95); background: rgba(255,255,255,0.72); padding: 1.15rem 1.35rem; border-radius: 1.2rem; box-shadow: inset 0 0 0 1px rgba(5,3,140,0.06); text-align: left; }
.vm-detail-body p { margin: 0 0 .95rem; }
.vm-detail-body p:last-child { margin-bottom: 0; }
.vm-detail-actions { display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; }
.vm-detail-link { padding: .72rem 1.8rem; border-radius: 999px; font-weight: 600; text-decoration: none; background: var(--vm-dark-blue); color: #fff; box-shadow: 0 16px 34px rgba(5,3,140,0.24); transition: transform var(--anim-speed) var,--anim-ease), box-shadow var(--anim-speed) var,--anim-ease); }
.vm-detail-link:hover { transform: translateY(-2px); box-shadow: 0 20px 40px rgba(5,3,140,0.3); }
.vm-agenda-link { padding: .72rem 1.8rem; border-radius: 999px; font-weight: 600; text-decoration: none; background: #D7A9E3; color: #fff; box-shadow: 0 16px 34px rgba(215,169,227,0.28); transition: transform var(--anim-speed) var,--anim-ease), box-shadow var(--anim-speed) var,--anim-ease); }
.vm-agenda-link:hover { transform: translateY(-2px); box-shadow: 0 20px 40px rgba(215,169,227,0.36); background: #C997DF; }
.vm-detail-close { position: absolute; top: 1.3rem; right: 1.3rem; display: inline-flex; align-items: center; gap: .45rem; padding: .6rem 1.6rem; border-radius: 999px; background: rgba(255,255,255,0.92); color: var(--vm-dark-blue); font-weight: 600; border: 2px solid rgba(5,3,140,0.2); cursor: pointer; transition: transform var(--anim-speed) var,--anim-ease), box-shadow var(--anim-speed) var,--anim-ease), border-color var(--anim-speed) var,--anim-ease); z-index: 3; }
.vm-detail-close::before { content: '\2190'; font-size: 1.1rem; }
.vm-detail-close:hover { transform: translateX(-3px); box-shadow: 0 12px 26px rgba(5,3,140,0.2); border-color: rgba(5,3,140,0.34); }
.vm-detail-close:focus,
.vm-detail-close:focus-visible { outline: none; box-shadow: 0 12px 26px rgba(5,3,140,0.2); }

.vm-mdc.show-detail .vm-search-assembly { opacity: 0; pointer-events: none; transform: translateY(-16px); transition: opacity var(--anim-speed) var,--anim-ease), transform var(--anim-speed) var,--anim-ease); }
.vm-mdc.show-detail .vm-main-title { opacity: .85; transition: opacity var(--anim-speed) var,--anim-ease); }

.vm-card.is-expanded .vm-detail-card { animation: vm-surface-in 0.44s cubic-bezier(0.24, 0.82, 0.25, 1); }

@keyframes vm-surface-in {
  from { opacity: 0; transform: translateY(18px) scale(0.95); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}

.vm-mdc.show-detail .vm-results-scroll { max-height: none !important; }

/* ========================================= */
/* BOTÓN COPIAR ENLACE Y TOAST               */
/* ========================================= */
.vm-copy-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .55rem;
    padding: .72rem 1.4rem;
    border-radius: 999px;
    font-weight: 600;
    text-decoration: none;
    background: #ffffff;
    color: var(--vm-dark-blue);
    border: 2px solid rgba(5,3,140,0.15);
    box-shadow: 0 12px 28px rgba(5,3,140,0.1);
    cursor: pointer;
    transition: transform var(--anim-speed) var(--anim-ease), box-shadow var(--anim-speed) var(--anim-ease), border-color var(--anim-speed) var(--anim-ease), background-color var(--anim-speed) var(--anim-ease);
    font-family: inherit;
    font-size: 1rem;
}
.vm-copy-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 18px 36px rgba(5,3,140,0.16);
    border-color: rgba(5,3,140,0.35);
    background-color: #fcfdff;
}
.vm-copy-link:active {
    transform: translateY(0);
}
.vm-copy-link svg {
    width: 18px;
    height: 18px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2.2;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.vm-detail-card > .vm-copy-link {
  position: absolute;
  top: 4.9rem;
  right: 1.3rem;
  z-index: 3;
}

.vm-toast {
    position: fixed;
    bottom: 32px;
    left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: rgba(38, 48, 75, 0.95);
    color: #fff;
    padding: 14px 28px;
    border-radius: 999px;
    font-size: 0.95rem;
    font-weight: 600;
    box-shadow: 0 16px 40px rgba(0,0,0,0.22);
    opacity: 0;
    pointer-events: none;
    transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275), opacity 0.4s ease;
    z-index: 99999;
}
.vm-toast.is-visible {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
}

@media (max-width: 720px) {
  .vm-detail-actions { flex-direction: column; width: 100%; }
  .vm-detail-link, .vm-agenda-link { width: 100%; text-align: center; justify-content: center; }
}

@media (max-width: 900px) {
  .vm-categories-bar, .vm-services-bar { width: 100%; padding: 0 1rem; margin: 0 0 .5rem 0; justify-content: center; flex: 1 1 100%; }
  .vm-categories-inner, .vm-services-inner { width: 100%; display: flex; align-items: center; gap: .45rem; justify-content: flex-start; overflow-x: visible; flex-wrap: wrap; }
  .vm-categories-menu, .vm-services-menu { position: static; width: 100%; margin: .65rem 0 0; box-shadow: 0 18px 40px rgba(5,3,140,0.16); }
  .vm-categories-toggle, .vm-services-toggle { max-width: calc(100vw - 6rem); }
  .vm-categories-copy { max-width: calc(100vw - 6rem); }
  .vm-toggle-text, .vm-service-toggle-text { display: block; }
}

@media (max-width: 720px) {
  .vm-results-panel { padding: 1.25rem 1.1rem; border-radius: 2rem; }
  .vm-mdc.show-detail .vm-results-panel { border-radius: 2.6rem; }
  .vm-results-scroll { padding-right: .55rem; }
  .vm-mdc.show-detail .vm-results-scroll { padding-right: .55rem; }
  .vm-no-scroll .vm-results-scroll { padding-right: 0; }
  .vm-detail-card { padding: 1.55rem; border-radius: 1.9rem; }
  .vm-detail-avatar { width: 120px; height: 120px; border-radius: 32px; font-size: 2.2rem; }
  .vm-detail-actions { justify-content: center; }
  .vm-detail-link { width: 100%; text-align: center; }
  .vm-agenda-link { width: 100%; text-align: center; }
  .vm-detail-close { top: 1rem; right: 1rem; }
  .vm-detail-card > .vm-copy-link { top: 4.5rem; right: 1rem; }
}

@media (max-width: 540px) {
  .vm-search-assembly { max-width: none; }
  .vm-text-button { height: 70px; }
  .vm-mdc.is-expanded .vm-text-button { height: 70px; }
  .vm-categories-bar, .vm-services-bar { padding: 0 .85rem; }
  .vm-categories-toggle, .vm-services-toggle { font-size: .95rem; padding: .5rem 1.1rem; max-width: calc(100vw - 5rem); }
  .vm-categories-copy { font-size: .88rem; padding: .45rem .95rem; max-width: calc(100vw - 5rem); }
  .vm-results-panel { padding: 1.2rem 1.1rem; }
  .vm-icon-circle { width: 56px; height: 56px; }
  .vm-icon-circle img { width: 52px; height: 52px; }
  .vm-deco-wrapper { width: 36px; height: 80px; }
  .vm-arc-deco { stroke-width: 8; }
}

@media (max-width: 420px) {
  .vm-initial-text { font-size: 1rem; line-height: 1.3; padding: 0 .5rem; }
  .vm-text-button { height: 68px; padding: 0 1rem; }
  .vm-mdc.is-expanded .vm-text-button { height: 68px; }
  .vm-icon-circle { width: 52px; height: 52px; }
  .vm-icon-circle img { width: 48px; height: 48px; }
  .vm-deco-wrapper { width: 32px; height: 72px; }
  .vm-categories-toggle, .vm-services-toggle { font-size: .9rem; padding: .45rem .95rem; max-width: calc(100vw - 4.5rem); }
  .vm-categories-copy { font-size: .84rem; padding: .4rem .85rem; max-width: calc(100vw - 4.5rem); }
}

@media (max-width: 390px) {
  .vm-initial-text { font-size: .95rem; line-height: 1.25; }
  .vm-mdc .vm-wrap { padding: .75rem; }
  .vm-results-panel { padding: 1rem .85rem; }
  .vm-mdc { --vm-card-width: 240px; }
  .vm-grid { gap: 1rem; }
  .vm-categories-toggle, .vm-services-toggle { max-width: calc(100vw - 4rem); font-size: .88rem; padding: .4rem .85rem; }
  .vm-categories-copy { max-width: calc(100vw - 4rem); font-size: .82rem; padding: .36rem .75rem; }
  .vm-text-button { padding: 0 .85rem; }
  .vm-card-summary { padding: 1.1rem .9rem; }
  .vm-categories-bar, .vm-services-bar { padding: 0 .75rem; }
}

@media (max-width: 360px) {
  .vm-mdc .vm-wrap { padding: .6rem; }
  .vm-results-panel { padding: .9rem .7rem; }
  .vm-mdc { --vm-card-width: 220px; --gap: .9rem; }
  .vm-grid { gap: .9rem; }
  .vm-categories-toggle, .vm-services-toggle { max-width: calc(100vw - 3.5rem); font-size: .85rem; padding: .38rem .75rem; }
  .vm-categories-copy { max-width: calc(100vw - 3.5rem); font-size: .8rem; padding: .34rem .65rem; }
  .vm-text-button { padding: 0 .75rem; height: 66px; }
  .vm-mdc.is-expanded .vm-text-button { height: 66px; }
  .vm-card-summary { padding: 1rem .8rem; }
  .vm-categories-bar, .vm-services-bar { padding: 0 .6rem; }
  .vm-icon-circle { width: 50px; height: 50px; }
  .vm-icon-circle img { width: 46px; height: 46px; }
  .vm-deco-wrapper { width: 30px; height: 68px; }
  .vm-initial-text { font-size: .9rem; padding: 0 .4rem; }
}
CSS;
    wp_add_inline_style('vm-medicos-style', $css);

    $uid = 'vm-mdc-' . wp_generate_uuid4();
    $rest_url = rest_url('vm/v1/doctores');

    $js = <<<'JS'
(function(){
  const endpoint = '__ENDPOINT__';
  const root = document.getElementById('__ROOT_ID__');
  if(!root) return;
  root.classList.add('vm-ready');

  const wrapper = root.querySelector('.vm-wrap');
  if(wrapper){
    wrapper.removeAttribute('hidden');
    wrapper.removeAttribute('aria-hidden');
  }

  const componentId = root.id || 'vm-mdc';
  const searchInput = root.querySelector('.vm-search-input');
  const grid  = root.querySelector('.vm-grid');
  const empty = root.querySelector('.vm-empty');
  const resultsScroll = root.querySelector('.vm-results-scroll');
  const searchTrigger = root.querySelector('.vm-search-assembly');
  const categoriesWrapper = root.querySelector('.vm-categories-wrapper');
  const categoriesBar = root.querySelector('.vm-categories-bar');
  const categoriesToggle = categoriesBar ? categoriesBar.querySelector('.vm-categories-toggle') : null;
  const categoriesCaret = categoriesBar ? categoriesBar.querySelector('.vm-categories-caret') : null;
  const categoriesClear = categoriesBar ? categoriesBar.querySelector('.vm-categories-clear') : null;
  const categoriesCopy = categoriesWrapper ? categoriesWrapper.querySelector('.vm-categories-copy') : null;
  const categoriesMenu = categoriesBar ? categoriesBar.querySelector('.vm-categories-menu') : null;
  const categoriesLabel = categoriesToggle ? categoriesToggle.querySelector('.vm-toggle-text') : null;
  const defaultToggleLabel = categoriesLabel ? categoriesLabel.textContent : 'Categorías';

  const servicesBar = root.querySelector('.vm-services-bar');
  const servicesToggle = servicesBar ? servicesBar.querySelector('.vm-services-toggle') : null;
  const servicesCaret = servicesBar ? servicesBar.querySelector('.vm-services-caret') : null;
  const servicesClear = servicesBar ? servicesBar.querySelector('.vm-services-clear') : null;
  const servicesMenu = servicesBar ? servicesBar.querySelector('.vm-services-menu') : null;
  const servicesLabel = servicesToggle ? servicesToggle.querySelector('.vm-service-toggle-text') : null;
  const defaultServiceLabel = servicesLabel ? servicesLabel.textContent : 'Servicios';

  const defaultEmptyText = empty ? empty.textContent : '';

  let data = [];
  let dataLoaded = false;
  const dataMap = new Map();
  let activeCard = null;
  const hideTimers = new Map();
  let ghostCounter = 0;
  let activeCategory = 'all';
  let activeCategoryLabel = '';
  let activeService = 'all';
  let activeServiceLabel = '';
  let categoriesList = [];
  let servicesList = [];
  let lastScrollSnapshot = null;
  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function escapeHTML(str){
    return (str || '').toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function escapeAttr(str){
    return escapeHTML(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function initials(nombre, apellido){
    const n = (nombre||'').trim().charAt(0).toUpperCase();
    const a = (apellido||'').trim().charAt(0).toUpperCase();
    return (n + a) || '—';
  }

  function specialtiesDetail(especialidades){
    if(!especialidades || !especialidades.length) return '';
    return especialidades.map(espec => '<span class="vm-pill">'+escapeHTML(espec)+'</span>').join('');
  }

  function pruneRatings(container){
    if(!container) return;
    const ratingSelectors = ['[class*="rating" i]', '.ct-rating', '.ct-ratings', '.team-ratings', '.ratings', '[data-rating]'];
    ratingSelectors.forEach(sel => {
      container.querySelectorAll(sel).forEach(node => node.remove());
    });
    container.querySelectorAll('h1,h2,h3,h4,h5,h6,p,div,span,strong').forEach(node => {
      const text = (node.textContent || '').trim().toLowerCase();
      if(!text) return;
      if(text === 'valoraciones' || text === 'valoración' || text.startsWith('valoraciones ')){
        const next = node.nextElementSibling;
        node.remove();
        if(next && /rating/i.test(next.className || '')){
          next.remove();
        }
      }
    });
  }

  function showAllCards(){
    hideTimers.forEach(timer => clearTimeout(timer));
    hideTimers.clear();
    const cards = Array.from(grid.querySelectorAll('.vm-card'));
    cards.forEach(card => {
      const wasHidden = card.classList.contains('is-hidden') || card.style.display === 'none';
      card.style.display = '';
      card.classList.remove('is-hidden');
      if(wasHidden && !reduceMotion){
        requestAnimationFrame(() => {
          card.classList.add('is-returning');
          setTimeout(() => card.classList.remove('is-returning'), 520);
        });
      }
    });
  }

  function fillCardDetail(card, item){
    const detail = card.querySelector('.vm-card-detail');
    if(!detail) return;

    const avatarNode = detail.querySelector('.vm-detail-avatar');
    const nameNode = detail.querySelector('.vm-detail-name');
    const jobNode = detail.querySelector('.vm-detail-job');
    const specsNode = detail.querySelector('.vm-detail-specialties');
    const bodyNode = detail.querySelector('.vm-detail-body');
    const linkNode = detail.querySelector('.vm-detail-link');

    const nombre = (item.nombre||'').trim();
    const apellido = (item.primer_apellido||'').trim();
    const fullRaw = (item.nombre_completo||'').trim();
    const full = fullRaw || [nombre, apellido].filter(Boolean).join(' ');
    const displayFull = full || 'Médico sin nombre';
    const image = (item.imagen||'').trim();
    const avatarHTML = image ? '<img src="'+escapeAttr(image)+'" alt="'+escapeAttr(displayFull)+'" loading="lazy">' : '<span>'+initials(nombre, apellido)+'</span>';

    if(avatarNode){
      avatarNode.innerHTML = avatarHTML;
    }

    if(nameNode){
      nameNode.textContent = displayFull;
    }

    if(jobNode){
      const cargo = item.team_member && item.team_member.cargo ? item.team_member.cargo : '';
      const cargoRaw = typeof cargo === 'string' ? cargo.trim() : '';
      let cargoText = cargoRaw;
      if(cargoText){
        if(typeof cargoText.toLocaleLowerCase === 'function'){
          cargoText = cargoText.toLocaleLowerCase('es-MX');
        } else {
          cargoText = cargoText.toLowerCase();
        }
      }
      jobNode.textContent = cargoText;
      jobNode.style.display = cargoText ? '' : 'none';
    }

    if(specsNode){
      specsNode.innerHTML = specialtiesDetail(item.especialidades);
      specsNode.style.display = specsNode.innerHTML ? '' : 'none';
    }

    if(bodyNode){
      const contenido = item.team_member && item.team_member.contenido ? item.team_member.contenido : '';
      if(contenido){
        bodyNode.innerHTML = contenido;
        bodyNode.style.display = '';
      } else if(item.team_member && item.team_member.resumen){
        bodyNode.textContent = item.team_member.resumen;
        bodyNode.style.display = '';
      } else {
        bodyNode.innerHTML = '';
        bodyNode.style.display = 'none';
      }
      pruneRatings(bodyNode);
    }

    if(linkNode){
      const enlace = item.team_member && item.team_member.enlace ? item.team_member.enlace : (item.url||'');
      if(enlace && enlace !== '#'){
        linkNode.setAttribute('href', enlace);
        linkNode.style.display = '';
      } else {
        linkNode.removeAttribute('href');
        linkNode.style.display = 'none';
      }
    }

    const agendaNode = detail.querySelector('.vm-agenda-link');
    if(agendaNode){
      const ameliaId = item.id;
      if(ameliaId){
        agendaNode.setAttribute('href', 'https://virtualmd.mx/?ameliaEmployeeId=' + ameliaId + '#agendar-consulta-widget');
        agendaNode.style.display = '';
      } else {
        agendaNode.removeAttribute('href');
        agendaNode.style.display = 'none';
      }
    }
  }

  function hideOthersExcept(selected){
    const cards = Array.from(grid.querySelectorAll('.vm-card'));
    cards.forEach(card => {
      if(card === selected) return;
      card.classList.remove('is-returning');
      card.classList.add('is-hidden');
      const timer = setTimeout(() => {
        card.style.display = 'none';
        hideTimers.delete(card);
      }, 260);
      hideTimers.set(card, timer);
    });
  }

  function playOpenAnimation(card, summaryRect){
    if(reduceMotion) return null;
    if(!card) return null;
    const detailWrap = card.querySelector('.vm-card-detail');
    if(!detailWrap) return null;
    const surface = detailWrap.querySelector('.vm-detail-card');
    if(!surface) return null;
    if(typeof surface.animate !== 'function') return null;
    surface.style.animation = 'none';
    return new Promise(resolve => {
      requestAnimationFrame(() => {
        const detailRect = surface.getBoundingClientRect();
        let translateX = 0;
        let translateY = 22;
        let scaleX = 0.94;
        let scaleY = 0.94;
        if(summaryRect){
          translateX = (summaryRect.left - detailRect.left) * 0.45;
          translateY = (summaryRect.top - detailRect.top) * 0.45;
          const widthRatio = summaryRect.width / detailRect.width;
          const heightRatio = summaryRect.height / detailRect.height;
          scaleX = Math.min(Math.max(widthRatio + 0.08, 0.86), 1.02);
          scaleY = Math.min(Math.max(heightRatio + 0.08, 0.86), 1.02);
        }
        const radius = window.getComputedStyle(surface).borderRadius;
        card.classList.add('is-animating');
        const animation = surface.animate([
          { transform: `translate(${translateX}px, ${translateY}px) scale(${scaleX}, ${scaleY})`, opacity: 0, borderRadius: 'var(--card-radius)' },
          { transform: 'translate(0,0) scale(1,1)', opacity: 1, borderRadius: radius }
        ], {
          duration: 420,
          easing: 'cubic-bezier(0.24, 0.82, 0.25, 1)'
        });
        const finish = () => {
          card.classList.remove('is-animating');
          surface.style.animation = '';
          resolve();
        };
        if(animation){
          animation.onfinish = animation.oncancel = finish;
        } else {
          finish();
        }
      });
    });
  }

  function playCloseAnimation(card, targetRect){
    if(reduceMotion) return null;
    if(!card || !targetRect) return null;
    const detailWrap = card.querySelector('.vm-card-detail');
    if(!detailWrap) return null;
    const surface = detailWrap.querySelector('.vm-detail-card');
    if(!surface) return null;
    if(typeof surface.animate !== 'function') return null;
    const detailRect = surface.getBoundingClientRect();
    const translateX = targetRect.left - detailRect.left;
    const translateY = targetRect.top - detailRect.top;
    const scaleX = Math.max(targetRect.width / detailRect.width, 0.05);
    const scaleY = Math.max(targetRect.height / detailRect.height, 0.05);
    const radius = window.getComputedStyle(surface).borderRadius;
    card.classList.add('is-animating');
    surface.style.animation = 'none';
    const animation = surface.animate([
      { transform: 'translate(0,0) scale(1,1)', opacity: 1, borderRadius: radius },
      { transform: `translate(${translateX * 0.65}px, ${translateY * 0.65}px) scale(${Math.max(scaleX, 0.88)}, ${Math.max(scaleY, 0.88)})`, opacity: 0.6, borderRadius: 'calc(var(--card-radius) + .4rem)' },
      { transform: `translate(${translateX}px, ${translateY}px) scale(${scaleX}, ${scaleY})`, opacity: 0, borderRadius: 'var(--card-radius)' }
    ], {
      duration: 360,
      easing: 'cubic-bezier(0.4, 0, 0.2, 1)'
    });
    try {
      if(animation && animation.finished && typeof animation.finished.then === 'function'){
        animation.finished.then(() => { surface.style.animation = ''; }).catch(() => { surface.style.animation = ''; });
      } else {
        setTimeout(() => { surface.style.animation = ''; }, 360);
      }
    } catch(e) {
      surface.style.animation = '';
    }
    return animation;
  }

  function openDetail(item, card, options){
    options = options || {};
    if(!card || !item) return;
    if(activeCard === card) return;

    closeDetail({ focus: 'none', skipAnimation: true });
    const currentWindowScroll = (typeof window !== 'undefined' && typeof document !== 'undefined')
      ? (window.pageYOffset || document.documentElement.scrollTop || 0)
      : 0;
    lastScrollSnapshot = {
      panel: resultsScroll ? resultsScroll.scrollTop : null,
      window: currentWindowScroll
    };
    fillCardDetail(card, item);

    const detail = card.querySelector('.vm-card-detail');
    const summary = card.querySelector('.vm-card-summary');

    if(detail){
      detail.setAttribute('aria-hidden', 'false');
    }
    if(summary){
      summary.setAttribute('aria-expanded', 'true');
    }

    const summaryRect = summary ? summary.getBoundingClientRect() : null;

    card.classList.add('is-opening', 'is-expanded');
    root.classList.add('show-detail');
    hideOthersExcept(card);
    activeCard = card;

    const openAnimation = playOpenAnimation(card, summaryRect);
    if(openAnimation && typeof openAnimation.then === 'function'){
      openAnimation.then(() => {
        card.classList.remove('is-opening');
      }).catch(() => {
        card.classList.remove('is-opening');
      });
    } else {
      requestAnimationFrame(() => card.classList.remove('is-opening'));
    }

    const closeBtn = card.querySelector('.vm-detail-close');
    if(closeBtn && !options.skipCloseFocus){
      setTimeout(() => closeBtn.focus(), 200);
    }
  }

  function closeDetail(options){
    options = options || {};
    if(!activeCard){
      root.classList.remove('show-detail');
      showAllCards();
      if(options.focus === 'search' && searchInput){
        searchInput.focus();
      }
      return;
    }

    const closingCard = activeCard;
    const detail = closingCard.querySelector('.vm-card-detail');
    const summary = closingCard.querySelector('.vm-card-summary');
    const summaryRect = summary ? summary.getBoundingClientRect() : null;
    const fallbackRect = searchTrigger ? searchTrigger.getBoundingClientRect() : null;
    const targetRect = summaryRect || fallbackRect;

    activeCard = null;

    const finish = () => {
      closingCard.classList.remove('is-animating');
      closingCard.classList.remove('is-opening');
      closingCard.classList.remove('is-expanded');
      if(detail){
        detail.setAttribute('aria-hidden', 'true');
      }
      if(summary){
        summary.setAttribute('aria-expanded', 'false');
      }
      root.classList.remove('show-detail');
      showAllCards();

      const snapshot = lastScrollSnapshot;
      if(snapshot){
        if(resultsScroll && typeof snapshot.panel === 'number' && !Number.isNaN(snapshot.panel)){
          resultsScroll.scrollTop = snapshot.panel;
        }
        if(typeof snapshot.window === 'number' && !Number.isNaN(snapshot.window) && typeof window !== 'undefined' && typeof window.scrollTo === 'function'){
          window.scrollTo(0, snapshot.window);
        }
      }
      lastScrollSnapshot = null;

      if(options.focus === 'search' && searchInput){
        searchInput.focus();
      } else if(summary && options.focus !== 'none'){
        summary.focus();
      }
    };

    const shouldAnimate = !options.skipAnimation;
    let animation = null;
    try {
      animation = shouldAnimate ? playCloseAnimation(closingCard, targetRect) : null;
    } catch(e) {
      animation = null;
    }
    if(animation){
      const safeFinish = () => {
        try {
          closingCard.classList.remove('is-animating');
        } catch(e) {}
        finish();
      };
      animation.onfinish = safeFinish;
      animation.oncancel = safeFinish;
      // Fallback timeout en caso de que los eventos no se disparen (Safari bug)
      setTimeout(safeFinish, 400);
    } else {
      finish();
    }
  }

  function normalize(text){
    return (text || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim();
  }

  function debounce(fn, delay){
    let timeout;
    return function(...args){
      clearTimeout(timeout);
      timeout = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  function ensureKey(item){
    if(!item) return;
    if(item.__vm_key != null) return;
    const normalized = item.nombre_completo ? normalize(item.nombre_completo) : '';
    if(normalized && dataMap.has(normalized)){
      const existing = dataMap.get(normalized);
      item.__vm_key = existing.__vm_key;
      return;
    }
    item.__vm_key = 'vmdk-' + (++ghostCounter);
    if(normalized){
      dataMap.set(normalized, item);
    }
  }

  function fillCardSummary(card, item){
    if(!card || !item) return;
    const summary = card.querySelector('.vm-card-summary');
    if(!summary) return;
    summary.innerHTML = '';

    const nombre = (item.nombre||'').trim();
    const apellido = (item.primer_apellido||'').trim();
    const fullRaw = (item.nombre_completo||'').trim();
    const full = fullRaw || [nombre, apellido].filter(Boolean).join(' ');
    const displayFull = full || 'Médico sin nombre';
    const image = (item.imagen||'').trim();
    const avatarHTML = image ? '<img src="'+escapeAttr(image)+'" alt="'+escapeAttr(displayFull)+'" loading="lazy">' : '<span>'+initials(nombre, apellido)+'</span>';

    const especsHTML = Array.isArray(item.especialidades) && item.especialidades.length > 0
      ? item.especialidades.map(espec => {
          const cleanSpec = (espec || '').replace(/^Consulta\s+de\s+/i, '');
          return '<span class="vm-pill">'+escapeHTML(cleanSpec)+'</span>';
        }).join('')
      : '<span class="vm-pill" style="opacity:0.5;">Sin especialidad</span>';

    const especsCount = (Array.isArray(item.especialidades) ? item.especialidades.length : 0);
    const especsClasses = especsCount <= 2 ? 'vm-pills-container is-few' : 'vm-pills-container';

    summary.innerHTML =
      '<div class="vm-avatar">'+avatarHTML+'</div>'+
      '<h3 class="vm-name">'+escapeHTML(displayFull)+'</h3>'+
      '<div class="'+especsClasses+'">'+especsHTML+'</div>';
    summary.tabIndex = 0;
    summary.setAttribute('role', 'button');
    summary.setAttribute('aria-expanded', 'false');
    summary.setAttribute('aria-label', 'Ver información de '+displayFull);
  }

  function showToast(message) {
    let toast = document.getElementById('vm-toast-notification');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'vm-toast-notification';
      toast.className = 'vm-toast';
      document.body.appendChild(toast);
    }
    toast.textContent = message;
    requestAnimationFrame(() => {
      toast.classList.add('is-visible');
      setTimeout(() => {
        toast.classList.remove('is-visible');
      }, 3000);
    });
  }

  // Obtiene la URL base sin parámetros ni fragmento para construir enlaces limpios
  function getBaseUrl() {
    const u = new URL(window.location.href);
    // eliminar búsqueda y cualquier hash (ej. #agendar-consulta-widget)
    u.search = '';
    u.hash = '';
    return u.toString();
  }

  function copyShareLink(item) {
    const url = new URL(getBaseUrl());
    url.searchParams.delete('vm_cat');
    url.searchParams.delete('vm_srv');
    url.searchParams.delete('vm_doc');
    
    if (activeCategory && activeCategory !== 'all') {
      url.searchParams.set('vm_cat', activeCategory);
    }
    if (activeService && activeService !== 'all') {
      url.searchParams.set('vm_srv', activeService);
    }
    
    if (item && item.id) {
      url.searchParams.set('vm_doc', item.id);
    }

    const shareUrl = url.toString();

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(shareUrl).then(() => {
        showToast('Enlace copiado al portapapeles');
      }).catch(err => {
        console.error('Error al copiar: ', err);
        fallbackCopy(shareUrl);
      });
    } else {
      fallbackCopy(shareUrl);
    }
  }

  function copyCategoryLink() {
    if(!activeCategory || activeCategory === 'all') return;

    const url = new URL(getBaseUrl());
    url.searchParams.delete('vm_doc');
    url.searchParams.set('vm_cat', activeCategory);
    if (activeService && activeService !== 'all') {
      url.searchParams.set('vm_srv', activeService);
    } else {
      url.searchParams.delete('vm_srv');
    }
    
    const shareUrl = url.toString();

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(shareUrl).then(() => {
        showToast('Link de categoría copiado');
      }).catch(() => {
        fallbackCopy(shareUrl);
      });
    } else {
      fallbackCopy(shareUrl);
    }
  }

  function fallbackCopy(text) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-9999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
      document.execCommand('copy');
      showToast('Enlace copiado al portapapeles');
    } catch (err) {
      console.error('Fallback copy failed', err);
      showToast('No se pudo copiar el enlace automatically');
    }
    document.body.removeChild(textArea);
  }

  function render(rows){
    if(!grid || !empty) return;
    grid.innerHTML = '';
    if(!Array.isArray(rows) || !rows.length){
      empty.style.display = 'flex';
      grid.style.display = 'none';
      root.classList.remove('vm-no-scroll');
      if(resultsScroll){
        resultsScroll.style.display = 'flex';
      }
      return;
    }
    empty.style.display = 'none';
    grid.style.display = 'grid';
    root.classList.remove('vm-no-scroll');
    if(resultsScroll){
      resultsScroll.style.display = 'flex';
    }

    rows.forEach((item, idx) => {
      ensureKey(item);
      const key = item.__vm_key || ('vm-fallback-'+idx);
      const card = document.createElement('article');
      card.className = 'vm-card';
      card.setAttribute('data-key', key);

      const summary = document.createElement('div');
      summary.className = 'vm-card-summary';
      card.appendChild(summary);

      const detail = document.createElement('div');
      detail.className = 'vm-card-detail';
      detail.setAttribute('aria-hidden', 'true');
      detail.innerHTML =
        '<div class="vm-detail-card">'+
          '<button type="button" class="vm-detail-close">Regresar</button>'+
          '<button type="button" class="vm-copy-link" aria-label="Copiar enlace directo">'+
            '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>'+
            '<span>Copiar enlace</span>'+
          '</button>'+
          '<div class="vm-detail-content">'+
            '<div class="vm-detail-top">'+
              '<div class="vm-detail-avatar"></div>'+
              '<div class="vm-detail-texts">'+
                '<h3 class="vm-detail-name"></h3>'+
                '<p class="vm-detail-job"></p>'+
                '<div class="vm-detail-specialties"></div>'+
              '</div>'+
            '</div>'+
            '<div class="vm-detail-body"></div>'+
            '<div class="vm-detail-actions">'+
              '<a class="vm-agenda-link" href="#" target="_blank" rel="noopener noreferrer">Agendar con este especialista</a>'+
              '<a class="vm-detail-link" href="#">Ver perfil completo</a>'+
            '</div>'+
          '</div>'+
        '</div>';
      card.appendChild(detail);

      fillCardSummary(card, item);

      const copyBtn = detail.querySelector('.vm-copy-link');
      if(copyBtn){
        copyBtn.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          copyShareLink(item);
        });
      }

      summary.addEventListener('click', () => {
        if(card.classList.contains('is-expanded')){
          closeDetail({ focus: 'summary' });
        } else {
          openDetail(item, card);
        }
      });
      summary.addEventListener('keydown', ev => {
        if(ev.key === 'Enter' || ev.key === ' '){
          ev.preventDefault();
          if(card.classList.contains('is-expanded')){
            closeDetail({ focus: 'summary' });
          } else {
            openDetail(item, card);
          }
        }
      });
      const closeBtn = detail.querySelector('.vm-detail-close');
      if(closeBtn){
        closeBtn.addEventListener('click', () => {
          closeDetail({ focus: 'none' });
        });
      }

      grid.appendChild(card);
    });
  }

  function applyFilter(){
    const query = searchInput ? searchInput.value.trim() : '';
    const lowerQuery = normalize(query);
    let filtered = data;
    if(activeCategory !== 'all'){
      filtered = filtered.filter(item => {
        if(!Array.isArray(item.categorias)) return false;
        return item.categorias.some(cat => normalize(cat) === normalize(activeCategory));
      });
    }
    if(activeService !== 'all'){
      filtered = filtered.filter(item => {
        if(!Array.isArray(item.especialidades)) return false;
        return item.especialidades.some(spec => normalize(spec) === normalize(activeService));
      });
    }
    if(lowerQuery){
      filtered = filtered.filter(item => {
        const normalizedName = normalize(item.nombre_completo || '');
        if(normalizedName.indexOf(lowerQuery) !== -1) return true;
        if(Array.isArray(item.especialidades)){
          if(item.especialidades.some(spec => normalize(spec).indexOf(lowerQuery) !== -1)) return true;
        }
        if(Array.isArray(item.categorias)){
          if(item.categorias.some(cat => normalize(cat).indexOf(lowerQuery) !== -1)) return true;
        }
        return false;
      });
    }
    render(filtered);
  }

  function openCategoryMenu() {
    if(!categoriesBar || !categoriesMenu) return;
    categoriesBar.classList.add('is-open');
    if(categoriesMenu) {
      categoriesMenu.hidden = false;
      categoriesMenu.setAttribute('aria-hidden', 'false');
    }
    if(categoriesToggle) {
      categoriesToggle.setAttribute('aria-expanded', 'true');
    }
  }

  function closeCategoryMenu() {
    if(!categoriesBar || !categoriesMenu) return;
    categoriesBar.classList.remove('is-open');
    if(categoriesMenu) {
      categoriesMenu.hidden = true;
      categoriesMenu.setAttribute('aria-hidden', 'true');
    }
    if(categoriesToggle) {
      categoriesToggle.setAttribute('aria-expanded', 'false');
    }
  }

  function toggleCategoryMenu() {
    if(categoriesBar && categoriesBar.classList.contains('is-open')) closeCategoryMenu();
    else openCategoryMenu();
  }

  function openServiceMenu() {
    if(!servicesBar || !servicesMenu) return;
    servicesBar.classList.add('is-open');
    if(servicesMenu) {
      servicesMenu.hidden = false;
      servicesMenu.setAttribute('aria-hidden', 'false');
    }
    if(servicesToggle) {
      servicesToggle.setAttribute('aria-expanded', 'true');
    }
  }

  function closeServiceMenu() {
    if(!servicesBar || !servicesMenu) return;
    servicesBar.classList.remove('is-open');
    if(servicesMenu) {
      servicesMenu.hidden = true;
      servicesMenu.setAttribute('aria-hidden', 'true');
    }
    if(servicesToggle) {
      servicesToggle.setAttribute('aria-expanded', 'false');
    }
  }

  function toggleServiceMenu() {
    if(servicesBar && servicesBar.classList.contains('is-open')) closeServiceMenu();
    else openServiceMenu();
  }

  function updateCategoryUI() {
    const label = activeCategoryLabel || defaultToggleLabel;
    const hasSelection = activeCategory !== 'all';
    if(categoriesLabel) categoriesLabel.textContent = label;
    if(categoriesToggle) categoriesToggle.classList.toggle('is-active', hasSelection);
    if(categoriesClear) categoriesClear.hidden = !hasSelection;
    if(categoriesCopy) categoriesCopy.hidden = !hasSelection;
    if(categoriesBar) categoriesBar.classList.toggle('has-selection', hasSelection);

    if(servicesBar) {
      if(hasSelection) {
        servicesBar.classList.add('is-visible');
        servicesBar.hidden = false;
        servicesBar.setAttribute('aria-hidden', 'false');
      } else {
        servicesBar.classList.remove('is-visible');
        servicesBar.hidden = true;
        servicesBar.setAttribute('aria-hidden', 'true');
        selectService('all', { label: 'Todos', silent: true, closeMenu: true });
      }
    }

    if(categoriesMenu) {
        const options = categoriesMenu.querySelectorAll('.vm-category-option');
        options.forEach(opt => {
          const val = opt.getAttribute('data-value') || 'all';
          const isSelected = val === activeCategory;
          opt.classList.toggle('is-selected', isSelected);
          opt.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }
  }

  function updateServiceUI() {
    const label = activeServiceLabel || defaultServiceLabel;
    const hasSelection = activeService !== 'all';
    if(servicesLabel) servicesLabel.textContent = label;
    if(servicesToggle) servicesToggle.classList.toggle('is-active', hasSelection);
    if(servicesClear) servicesClear.hidden = !hasSelection;
    if(servicesBar) servicesBar.classList.toggle('has-selection', hasSelection);

    if(servicesMenu) {
        const options = servicesMenu.querySelectorAll('.vm-category-option');
        options.forEach(opt => {
          const val = opt.getAttribute('data-value') || 'all';
          const isSelected = val === activeService;
          opt.classList.toggle('is-selected', isSelected);
          opt.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }
  }

  function selectCategory(value, options) {
    options = options || {};
    const targetValue = value || 'all';
    const label = options.label != null ? options.label : targetValue;
    const previousCategory = activeCategory;
    activeCategory = targetValue;
    activeCategoryLabel = targetValue === 'all' ? '' : (label || targetValue);
    
    // Si cambia de categoría, resetear servicio
    if (previousCategory !== targetValue) {
        activeService = 'all';
        activeServiceLabel = '';
        updateCategoryUI();
        updateServiceUI();
        if(targetValue !== 'all') {
            renderServices(data, targetValue);
        }
    } else {
        updateCategoryUI();
    }
    
    if(options.closeMenu !== false) closeCategoryMenu();
    
    const shouldApply = !options.silent && (targetValue !== previousCategory || options.force);
    if(shouldApply) applyFilter();
    
    if(options.focusToggle && categoriesToggle) categoriesToggle.focus();
  }

  function selectService(value, options) {
    options = options || {};
    const targetValue = value || 'all';
    const label = options.label != null ? options.label : targetValue;
    const previousService = activeService;
    activeService = targetValue;
    activeServiceLabel = targetValue === 'all' ? '' : (label || targetValue);
    updateServiceUI();
    if(options.closeMenu !== false) closeServiceMenu();
    
    const shouldApply = !options.silent && (targetValue !== previousService || options.force);
    if(shouldApply) applyFilter();
    
    if(options.focusToggle && servicesToggle) servicesToggle.focus();
  }

  function renderCategories(source){
    if(!categoriesBar || !categoriesMenu || !categoriesToggle) return;
    const unique = new Map();
    source.forEach(item => {
      const list = Array.isArray(item.categorias) ? item.categorias : [];
      list.forEach(cat => {
        const trimmed = (cat || '').trim();
        if(!trimmed) return;
        const key = normalize(trimmed);
        if(!unique.has(key)){
          unique.set(key, trimmed);
        }
      });
    });
    const values = Array.from(unique.values()).sort((a, b) => a.localeCompare(b, 'es', { sensitivity: 'base' }));
    categoriesList = values;
    if(!values.length){
      activeCategory = 'all';
      activeCategoryLabel = '';
      if(categoriesMenu){
        categoriesMenu.innerHTML = '';
        categoriesMenu.hidden = true;
      }
      if(categoriesBar){
        categoriesBar.hidden = true;
        categoriesBar.setAttribute('aria-hidden', 'true');
      }
      closeCategoryMenu();
      updateCategoryUI();
      return;
    }
    const items = [{ label: 'Todos', value: 'all' }];
    values.forEach(cat => {
      items.push({ label: cat, value: cat });
    });
    const html = items.map(item => {
      return '<li><button type="button" role="option" class="vm-category-option" data-value="' + escapeAttr(item.value) + '" data-label="' + escapeAttr(item.label) + '">' + escapeHTML(item.label) + '</button></li>';
    }).join('');
    categoriesMenu.innerHTML = html;
    categoriesMenu.hidden = true;
    categoriesMenu.scrollTop = 0;
    categoriesMenu.querySelectorAll('.vm-category-option').forEach(button => {
      button.addEventListener('click', () => {
        const value = button.getAttribute('data-value') || 'all';
        const label = button.getAttribute('data-label') || value;
        selectCategory(value, { label });
      });
    });
    categoriesBar.hidden = false;
    categoriesBar.setAttribute('aria-hidden', 'false');
    closeCategoryMenu();
    selectCategory('all', { label: 'Todos', silent: true, closeMenu: true });
  }

  function renderServices(source, selectedCategory) {
    if(!servicesMenu || !servicesBar) return;
    const unique = new Map();
    source.forEach(item => {
      const pairs = Array.isArray(item.servicios_por_categoria) ? item.servicios_por_categoria : [];
      pairs.forEach(pair => {
        const pairCategory = pair && pair.categoria ? String(pair.categoria) : '';
        if(selectedCategory !== 'all' && normalize(pairCategory) !== normalize(selectedCategory)) return;
        const trimmed = pair && pair.servicio ? String(pair.servicio).trim() : '';
        if(!trimmed) return;
        const key = normalize(trimmed);
        if(!unique.has(key)){
          unique.set(key, trimmed);
        }
      });
    });
    const values = Array.from(unique.values()).sort((a, b) => a.localeCompare(b, 'es', { sensitivity: 'base' }));
    servicesList = values;
    if(!values.length){
      activeService = 'all';
      activeServiceLabel = '';
      if(servicesMenu) {
        servicesMenu.innerHTML = '';
        servicesMenu.hidden = true;
      }
      closeServiceMenu();
      updateServiceUI();
      return;
    }
    const items = [{ label: 'Todos', value: 'all' }];
    values.forEach(spec => {
      const cleanLabel = spec.replace(/^Consulta\s+de\s+/i, '');
      items.push({ label: cleanLabel, value: spec });
    });
    const html = items.map(item => {
      return '<li><button type="button" role="option" class="vm-category-option" data-value="' + escapeAttr(item.value) + '" data-label="' + escapeAttr(item.label) + '">' + escapeHTML(item.label) + '</button></li>';
    }).join('');
    servicesMenu.innerHTML = html;
    servicesMenu.hidden = true;
    servicesMenu.scrollTop = 0;
    servicesMenu.querySelectorAll('.vm-category-option').forEach(button => {
      button.addEventListener('click', () => {
        const value = button.getAttribute('data-value') || 'all';
        const label = button.getAttribute('data-label') || value;
        selectService(value, { label });
      });
    });
    closeServiceMenu();
    selectService('all', { label: 'Todos', silent: true, closeMenu: true });
  }

  function loadData(){
    if(dataLoaded) return;
    if(empty){
      empty.textContent = 'Cargando médicos...';
      empty.style.display = 'flex';
    }
    if(grid){
      grid.innerHTML = '';
      grid.style.display = 'none';
    }
    if(resultsScroll){
      resultsScroll.style.display = 'none';
    }
    root.classList.add('vm-no-scroll');
    if(categoriesBar){
      categoriesBar.hidden = true;
      categoriesBar.setAttribute('aria-hidden', 'true');
    }
    closeCategoryMenu();
    activeCategory = 'all';
    activeCategoryLabel = '';
    activeService = 'all';
    activeServiceLabel = '';
    updateCategoryUI();
    updateServiceUI();
    categoriesList = [];
    servicesList = [];
    grid.innerHTML = '';

    let retryCount = 0;
    const maxRetries = 2;
    
    function doFetch() {
      fetch(endpoint).then(r => {
        if(!r.ok) throw new Error('Network response was not ok');
        return r.json();
      }).then(json => {
        data = Array.isArray(json) ? json : [];
        dataMap.clear();
        ghostCounter = 0;
        data.forEach(item => {
          ensureKey(item);
        });
        dataLoaded = true;
        renderCategories(data);
        render(data);
        
        // Check for URL parameters to pre-select category and open doctor
        const urlParams = new URLSearchParams(window.location.search);
        const urlCat = urlParams.get('vm_cat');
        const urlSrv = urlParams.get('vm_srv');
        const urlDoc = urlParams.get('vm_doc');
        
        if (urlCat || urlSrv || urlDoc) {
             // Open everything
             if(searchTrigger && !root.classList.contains('is-expanded')){
                root.classList.add('is-expanded');
             }

             if (urlCat && urlCat !== 'all') {
                 // Try to find label
                 let labelToUse = urlCat;
                 const opt = categoriesMenu ? categoriesMenu.querySelector('[data-value="'+escapeAttr(urlCat)+'"]') : null;
                 if (opt) {
                     labelToUse = opt.getAttribute('data-label') || urlCat;
                 }
                 selectCategory(urlCat, { label: labelToUse });
             }

             if (urlSrv && urlSrv !== 'all') {
                 let labelToUse = urlSrv;
                 const opt = servicesMenu ? servicesMenu.querySelector('[data-value="'+escapeAttr(urlSrv)+'"]') : null;
                 if (opt) {
                     labelToUse = opt.getAttribute('data-label') || urlSrv;
                 }
                 selectService(urlSrv, { label: labelToUse });
             }
             
             if (urlDoc) {
                 const found = data.find(d => String(d.id) === String(urlDoc));
                 if (found) {
                     // Need to find the card
                     const key = found.__vm_key;
                     const card = grid.querySelector('.vm-card[data-key="'+key+'"]');
                     if (card) {
                       openDetail(found, card, { skipCloseFocus: true });
                         // Scroll to it
                         setTimeout(() => {
                            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                         }, 500);
                     }
                 }
             }
        }

      }).catch(() => {
        retryCount++;
        if(retryCount <= maxRetries) {
          setTimeout(doFetch, 1000);
          return;
        }
        if(empty){
          empty.textContent = 'No se pudieron cargar los médicos.';
          empty.style.display = 'flex';
        }
        grid.innerHTML = '';
        if(resultsScroll){
          resultsScroll.style.display = 'none';
        }
        root.classList.add('vm-no-scroll');
        if(categoriesBar){
          categoriesBar.hidden = true;
          categoriesBar.setAttribute('aria-hidden', 'true');
        }
        if(servicesBar){
          servicesBar.classList.remove('is-visible');
          servicesBar.hidden = true;
          servicesBar.setAttribute('aria-hidden', 'true');
        }
        categoriesList = [];
        servicesList = [];
        activeCategory = 'all';
        activeCategoryLabel = '';
        activeService = 'all';
        activeServiceLabel = '';
        closeCategoryMenu();
        closeServiceMenu();
        updateCategoryUI();
        updateServiceUI();
      });
    }
    
    doFetch();
  }

  if(searchInput){
    searchInput.addEventListener('input', debounce(applyFilter, 160));
  }

  if(categoriesClear){
    categoriesClear.hidden = true;
    categoriesClear.addEventListener('click', ev => {
      ev.preventDefault();
      if(activeCategory !== 'all'){
        selectCategory('all', { label: 'Todos', focusToggle: true });
      } else {
        closeCategoryMenu();
        if(categoriesToggle){
          categoriesToggle.focus();
        }
      }
    });
  }

  if(categoriesCopy){
    categoriesCopy.hidden = true;
    categoriesCopy.addEventListener('click', ev => {
      ev.preventDefault();
      copyCategoryLink();
    });
  }

  if(categoriesToggle){
    categoriesToggle.addEventListener('click', () => {
      if(!dataLoaded){
        loadData();
      }
      toggleCategoryMenu();
    });
    categoriesToggle.addEventListener('keydown', ev => {
      if(ev.key === 'Enter' || ev.key === ' '){
        ev.preventDefault();
        if(!dataLoaded) loadData();
        toggleCategoryMenu();
        return;
      }
      if(ev.key === 'ArrowDown'){
        ev.preventDefault();
        if(!dataLoaded) loadData();
        openCategoryMenu();
        const firstOption = categoriesMenu ? categoriesMenu.querySelector('.vm-category-option') : null;
        if(firstOption) firstOption.focus();
      }
    });
  }

  if(categoriesCaret){
    categoriesCaret.addEventListener('click', ev => {
      ev.preventDefault();
      if(!dataLoaded) loadData();
      toggleCategoryMenu();
    });
  }

  if(categoriesMenu){
    categoriesMenu.addEventListener('keydown', ev => {
      if(ev.key === 'Escape'){
        ev.preventDefault();
        closeCategoryMenu();
        if(categoriesToggle) categoriesToggle.focus();
      }
    });
  }

  if(servicesClear){
    servicesClear.hidden = true;
    servicesClear.addEventListener('click', ev => {
      ev.preventDefault();
      if(activeService !== 'all'){
        selectService('all', { label: 'Todos', focusToggle: true });
      } else {
        closeServiceMenu();
        if(servicesToggle) servicesToggle.focus();
      }
    });
  }

  if(servicesToggle){
    servicesToggle.addEventListener('click', () => {
      if(!dataLoaded) loadData();
      toggleServiceMenu();
    });
    servicesToggle.addEventListener('keydown', ev => {
      if(ev.key === 'Enter' || ev.key === ' '){
        ev.preventDefault();
        if(!dataLoaded) loadData();
        toggleServiceMenu();
        return;
      }
      if(ev.key === 'ArrowDown'){
        ev.preventDefault();
        if(!dataLoaded) loadData();
        openServiceMenu();
        const firstOption = servicesMenu ? servicesMenu.querySelector('.vm-category-option') : null;
        if(firstOption) firstOption.focus();
      }
    });
  }

  if(servicesCaret){
    servicesCaret.addEventListener('click', ev => {
      ev.preventDefault();
      if(!dataLoaded) loadData();
      toggleServiceMenu();
    });
  }

  if(servicesMenu){
    servicesMenu.addEventListener('keydown', ev => {
      if(ev.key === 'Escape'){
        ev.preventDefault();
        closeServiceMenu();
        if(servicesToggle) servicesToggle.focus();
      }
    });
  }

  if(categoriesBar || servicesBar){
    root.addEventListener('click', ev => {
      if(categoriesBar && categoriesBar.contains(ev.target)) return;
      if(servicesBar && servicesBar.contains(ev.target)) return;
      closeCategoryMenu();
      closeServiceMenu();
    });
  }

  let isTransitioning = false;

  if(searchTrigger){
    searchTrigger.addEventListener('click', function(event){
      if(event.target === searchInput){
        return;
      }
      // Prevenir clicks rápidos que causan errores en Safari
      if(isTransitioning) return;
      isTransitioning = true;
      setTimeout(() => { isTransitioning = false; }, 350);
      
      root.classList.toggle('is-expanded');
      if(root.classList.contains('is-expanded')){
        loadData();
        setTimeout(() => {
          if(searchInput){
            searchInput.focus();
          }
        }, 120);
      } else {
        try {
          closeDetail({ focus: 'search', skipAnimation: true });
        } catch(e) {}
        closeCategoryMenu();
      }
    });
  }

  const initialUrlParams = new URLSearchParams(window.location.search);
  const shouldOpenFromLink = initialUrlParams.has('vm_cat') || initialUrlParams.has('vm_srv') || initialUrlParams.has('vm_doc');
  if(shouldOpenFromLink && !root.classList.contains('is-expanded')){
    if(typeof root.scrollIntoView === 'function'){
      root.scrollIntoView({ behavior: 'auto', block: 'start' });
    }
    root.classList.add('is-expanded');
    loadData();
  }

  root.addEventListener('keydown', ev => {
    if(ev.key === 'Escape'){
      if(categoriesBar && categoriesBar.classList.contains('is-open')){
        ev.preventDefault();
        closeCategoryMenu();
        if(categoriesToggle){
          categoriesToggle.focus();
        }
        return;
      }
      if(activeCard){
        ev.preventDefault();
        closeDetail({ focus: 'summary' });
      }
    }
  });
})();
JS;
    $js = str_replace(
      ['__ENDPOINT__','__ROOT_ID__'],
      [esc_url_raw($rest_url), esc_js($uid)],
      $js
    );
    wp_add_inline_script('vm-medicos-script', $js);

    $results_height = apply_filters('vm_buscador_results_height', '720px');

    ob_start();
    ?>
    <section id="<?php echo esc_attr($uid); ?>" class="vm-mdc" role="region" aria-label="Buscador de médicos" style="--results-height: <?php echo esc_attr($results_height); ?>;">
        <div class="vm-wrap" hidden aria-hidden="true">
            <h2 class="vm-main-title vm-text-format">Buscador de médicos</h2>

            <div class="vm-search-assembly">
                
                <div class="vm-deco-wrapper">
                    <svg class="vm-arc-deco" viewBox="0 0 45 90">
                        <path d="M 40 10 A 40 40, 0, 0, 0, 40 80" fill="none" stroke="rgba(5,3,140,0.55)" stroke-width="10" stroke-linecap="round" />
                    </svg>
                </div>

                <div class="vm-central-group">
                    <div class="vm-icon-circle">
                        <!-- Logo actualizado -->
                        <img src="https://virtualmd.mx/wp-content/uploads/2025/08/Artboard-3-copy@3x-2.png" alt="Icono Buscador">
                    </div>

                    <div class="vm-text-button" role="button" tabindex="0">
                        <span class="vm-initial-text">Presiona aquí para ver a<br>nuestros médicos</span>
                        <input type="search" class="vm-search-input" placeholder="Nombre o especialidad">
                    </div>
                </div>

                <div class="vm-deco-wrapper">
                     <svg class="vm-arc-deco" viewBox="0 0 45 90">
                        <path d="M 5 10 A 40 40, 0, 0, 1, 5 80" fill="none" stroke="rgba(5,3,140,0.55)" stroke-width="10" stroke-linecap="round" />
                    </svg>
                </div>

                <div class="vm-arrow-shape">
                    <svg class="vm-arrow-right" viewBox="0 0 20 20"><path d="M 0 0 L 20 10 L 0 20 Z"/></svg>
                    <svg class="vm-arrow-down" viewBox="0 0 22 18"><path d="M 2 2 L 11 16 L 20 2 Z"/></svg>
                </div>

            </div>

            <div class="vm-collapsible-content" id="vm-search-content-<?php echo esc_attr($uid); ?>">
                <div class="vm-categories-wrapper">
                    <div class="vm-categories-bar" aria-hidden="true" hidden>
                        <div class="vm-categories-inner">
                            <button type="button" class="vm-categories-toggle" aria-haspopup="listbox" aria-expanded="false" aria-controls="vm-category-menu-<?php echo esc_attr($uid); ?>">
                                <span class="vm-toggle-text">Categorías</span>
                            </button>
                            <span class="vm-categories-caret" aria-hidden="true"></span>
                            <button type="button" class="vm-categories-clear" aria-label="Quitar filtro de categoría">&times;</button>
                        </div>
                        <ul class="vm-categories-menu" id="vm-category-menu-<?php echo esc_attr($uid); ?>" role="listbox" hidden></ul>
                    </div>

                    <div class="vm-services-bar" aria-hidden="true" hidden>
                      <div class="vm-services-inner">
                            <button type="button" class="vm-services-toggle vm-categories-toggle" aria-haspopup="listbox" aria-expanded="false" aria-controls="vm-service-menu-<?php echo esc_attr($uid); ?>">
                                <span class="vm-service-toggle-text vm-toggle-text">Servicios</span>
                            </button>
                            <span class="vm-services-caret vm-categories-caret" aria-hidden="true"></span>
                            <button type="button" class="vm-services-clear vm-categories-clear" aria-label="Quitar filtro de servicio">&times;</button>
                        </div>
                        <ul class="vm-services-menu vm-categories-menu" id="vm-service-menu-<?php echo esc_attr($uid); ?>" role="listbox" hidden></ul>
                    </div>

                    <button type="button" class="vm-categories-copy" aria-label="Copiar link de categoría" hidden>Copiar link de categoría</button>
                </div>

                <div class="vm-results-panel">
                    <div class="vm-results-scroll">
                        <div class="vm-grid" aria-live="polite"></div>
                    </div>
                    <div class="vm-empty" style="display:none">No hay resultados para tu búsqueda.</div>
                </div>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
  }

  /** Shortcode [vm_carrusel_doctores] */
  public function shortcode_carrusel($atts) {
    wp_enqueue_style('vm-carrusel-style');
    wp_enqueue_script('vm-carrusel-script');

    $uid = 'vm-carrusel-' . uniqid();
    $rest_url = rest_url('vm/v1/especialidades');

  $css = <<<'CSS'
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

.vm-carrusel-container {
  --vm-dark-blue: #05038C;
  --vm-icon-blue: #60C3E8;
  --vm-text-blue: #1D1B79;
  --vm-body-text: #26304B;
  --vm-soft-white: rgba(255, 255, 255, 0.94);
  font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  max-width: 1280px;
  margin: clamp(30px, 6vw, 72px) auto;
  padding: clamp(40px, 5vw, 70px);
  background: linear-gradient(145deg, #ffffff 0%, #f6f8ff 100%);
  border-radius: 36px;
  box-shadow: 0 32px 70px rgba(5, 3, 140, 0.18);
  position: relative;
  overflow: hidden;
}

.vm-carrusel-container::before {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(circle at top right, rgba(96, 195, 232, 0.24), transparent 55%), radial-gradient(circle at bottom left, rgba(5, 3, 140, 0.16), transparent 60%);
  opacity: 0.85;
  pointer-events: none;
}

.vm-carrusel-container > * {
  position: relative;
}

.vm-carrusel-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: clamp(20px, 4vw, 56px);
  flex-wrap: wrap;
  position: relative;
}

.vm-carrusel-title {
  margin: 0;
  font-size: clamp(2.1rem, 4.2vw, 3rem);
  font-weight: 800;
  color: var(--vm-dark-blue);
  letter-spacing: -0.03em;
  line-height: 1.05;
  text-transform: uppercase;
  text-shadow: 0 12px 28px rgba(5, 3, 140, 0.18);
}

.vm-carrusel-title::after {
  content: '';
  display: block;
  width: clamp(80px, 15vw, 160px);
  height: 6px;
  margin-top: 18px;
  border-radius: 999px;
  background: linear-gradient(90deg, #D7A9E3, rgba(215, 169, 227, 0));
}

.vm-specialty-selector {
  position: relative;
  min-width: clamp(300px, 38vw, 420px);
}

.vm-specialty-toggle {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 22px 32px;
  background: linear-gradient(135deg, rgba(255,255,255,0.98) 0%, rgba(246,249,255,0.95) 100%);
  border-radius: 999px;
  border: 3px solid rgba(5, 3, 140, 0.15);
  cursor: pointer;
  box-shadow: 0 20px 50px rgba(5, 3, 140, 0.22), 0 8px 20px rgba(96, 195, 232, 0.15);
  color: var(--vm-dark-blue);
  font-size: 1.15rem;
  font-weight: 700;
  transition: all 0.3s ease;
}

.vm-specialty-selector.is-auto .vm-specialty-toggle {
  color: rgba(5, 3, 140, 0.7);
  background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(246,249,255,0.92) 100%);
  box-shadow: 0 16px 38px rgba(5, 3, 140, 0.18), 0 6px 16px rgba(96, 195, 232, 0.12);
}

.vm-specialty-selector.is-open .vm-specialty-toggle {
  box-shadow: 0 28px 65px rgba(5, 3, 140, 0.28), 0 10px 25px rgba(96, 195, 232, 0.18);
  transform: translateY(-3px);
  border-color: rgba(96, 195, 232, 0.5);
}

.vm-specialty-toggle:focus-visible {
  outline: 3px solid rgba(96, 195, 232, 0.8);
  outline-offset: 4px;
}

.vm-specialty-label {
  flex: 1;
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  letter-spacing: 0.01em;
}

.vm-specialty-label.is-muted {
  color: rgba(5, 3, 140, 0.6);
  font-style: italic;
  font-weight: 600;
}

.vm-specialty-arrow {
  width: 16px;
  height: 16px;
  border-right: 3px solid currentColor;
  border-bottom: 3px solid currentColor;
  transform: rotate(45deg);
  transition: transform 0.3s ease;
  flex-shrink: 0;
}

.vm-specialty-selector.is-open .vm-specialty-arrow {
  transform: rotate(-135deg);
}

.vm-specialty-menu {
  z-index: 100;
  position: absolute;
  top: calc(100% + 12px);
  left: 0;
  right: 0;
  background: #ffffff;
  border-radius: 24px;
  box-shadow: 0 32px 70px rgba(5, 3, 140, 0.24);
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-height: 320px;
  overflow-y: auto;
  opacity: 0;
  visibility: hidden;
  transform: translateY(6px);
  transition: all 0.26s ease;
  pointer-events: none;
}

.vm-specialty-selector.is-open .vm-specialty-menu {
  z-index: 100;
  opacity: 1;
  visibility: visible;
  transform: translateY(0);
  pointer-events: auto;
}

.vm-specialty-option {
  display: flex;
  align-items: center;
  width: 100%;
  padding: 12px 16px;
  border: none;
  border-radius: 16px;
  background: transparent;
  font-size: 0.98rem;
  font-weight: 600;
  color: var(--vm-body-text);
  cursor: pointer;
  transition: all 0.24s ease;
  text-align: left;
}

.vm-specialty-option:hover,
.vm-specialty-option:focus {
  background: rgba(96, 195, 232, 0.16);
  color: var(--vm-dark-blue);
}

.vm-specialty-option.is-active {
  background: linear-gradient(90deg, rgba(96, 195, 232, 0.24), rgba(96, 195, 232, 0.08));
  color: var(--vm-dark-blue);
}

.vm-specialty-menu::-webkit-scrollbar {
  width: 6px;
}

.vm-specialty-menu::-webkit-scrollbar-thumb {
  background: rgba(96, 195, 232, 0.7);
  border-radius: 999px;
}

.vm-carrusel-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: clamp(24px, 5vw, 48px);
  margin-top: clamp(32px, 6vw, 64px);
  position: relative;
}

.vm-carrusel-nav {
  background: linear-gradient(135deg, var(--vm-dark-blue) 0%, #D7A9E3 100%);
  border: none;
  width: clamp(54px, 6vw, 72px);
  height: clamp(54px, 6vw, 72px);
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  font-size: clamp(1.6rem, 2.5vw, 2.1rem);
  flex-shrink: 0;
  box-shadow: 0 16px 30px rgba(5, 3, 140, 0.28);
  transition: transform 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease;
}

.vm-carrusel-nav:hover {
  transform: scale(1.08);
  box-shadow: 0 24px 45px rgba(5, 3, 140, 0.35);
}

.vm-carrusel-nav:active {
  transform: scale(0.95);
}

.vm-carrusel-nav:disabled {
  opacity: 0.3;
  cursor: not-allowed;
  transform: scale(1);
  box-shadow: none;
}

.vm-carrusel-track {
  display: flex;
  justify-content: center;
  align-items: stretch;
  gap: clamp(28px, 5vw, 52px);
  max-width: min(960px, 100%);
  width: 100%;
  min-height: auto;
  position: relative;
}

.vm-doctor-item {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 48px;
  padding-bottom: 32px;
  opacity: 0;
  transform: translateY(24px);
  animation: vmDoctorFade 0.5s ease forwards;
}

@keyframes vmDoctorFade {
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.vm-doctor-circle {
  position: relative;
  width: clamp(170px, 22vw, 210px);
  height: clamp(170px, 22vw, 210px);
  border-radius: 50%;
  overflow: hidden;
  border: 6px solid rgba(215, 169, 227, 0.65);
  transition: transform 0.32s ease, border-color 0.32s ease, box-shadow 0.32s ease;
  cursor: pointer;
  box-shadow: 0 24px 50px rgba(5, 3, 140, 0.22);
  background: linear-gradient(135deg, rgba(215, 169, 227, 0.45) 0%, rgba(5, 3, 140, 0.45) 100%);
}

.vm-doctor-circle::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(180deg, rgba(0, 0, 0, 0) 55%, rgba(0, 0, 0, 0.28) 100%);
  opacity: 0;
  transition: opacity 0.3s ease;
}

.vm-doctor-circle img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.4s ease;
}

.vm-doctor-circle:hover img {
  transform: scale(1.08);
}

.vm-doctor-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: clamp(3.5rem, 5vw, 4.4rem);
  font-weight: 700;
  color: #ffffff;
  background: linear-gradient(135deg, var(--vm-icon-blue) 0%, var(--vm-dark-blue) 100%);
}

.vm-doctor-name {
  position: absolute;
  left: 50%;
  bottom: 0;
  transform: translate(-50%, 70px) scale(0.95);
  padding: 10px 20px;
  background: rgba(255, 255, 255, 0.97);
  color: var(--vm-text-blue);
  font-weight: 700;
  font-size: 0.95rem;
  line-height: 1.2;
  text-align: center;
  border-radius: 999px;
  box-shadow: 0 12px 28px rgba(5, 3, 140, 0.24);
  opacity: 0;
  pointer-events: none;
  transition: all 0.32s cubic-bezier(0.4, 0, 0.2, 1);
  min-width: 160px;
  max-width: 240px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Hover solo en desktop (con mouse/pointer) */
@media (hover: hover) and (pointer: fine) {
  .vm-doctor-item:hover .vm-doctor-name {
    opacity: 1;
    transform: translate(-50%, 10px) scale(1.08);
    font-size: 1.05rem;
  }

  .vm-doctor-circle:hover {
    transform: scale(1.08) translateY(-6px);
    border-color: var(--vm-dark-blue);
    box-shadow: 0 30px 60px rgba(5, 3, 140, 0.32);
  }

  .vm-doctor-circle:hover::after {
    opacity: 1;
  }
}

.vm-carrusel-loading,
.vm-carrusel-empty {
  width: 100%;
  text-align: center;
  padding: 72px 24px;
  color: var(--vm-body-text);
  font-size: 1.05rem;
  font-weight: 600;
  background: rgba(255, 255, 255, 0.82);
  border-radius: 28px;
  box-shadow: inset 0 0 0 1px rgba(5, 3, 140, 0.1);
}

.vm-sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}

.vm-carousel-container {
  position: relative;
  min-height: auto;
  transition: min-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.vm-carrusel-wrapper {
  transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.vm-carrusel-wrapper.is-hiding {
  opacity: 0;
  pointer-events: none;
  position: absolute;
  visibility: hidden;
  width: 100%;
  top: 0;
  left: 0;
}

.vm-carrusel-detail {
  display: none;
  flex-direction: column;
  align-items: center;
  gap: clamp(24px, 5vw, 36px);
  margin-top: clamp(32px, 6vw, 64px);
  opacity: 0;
  transform: scale(0.95);
  transition: opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1) 0.15s, transform 0.35s cubic-bezier(0.4, 0, 0.2, 1) 0.15s;
}

.vm-carrusel-detail.is-active {
  display: flex;
}

.vm-carrusel-detail.is-active.is-visible {
  opacity: 1;
  transform: scale(1);
}

.vm-carrusel-detail .vm-detail-card {
  margin: 0;
  max-width: min(760px, 100%);
  width: 100%;
  margin-left: auto;
  margin-right: auto;
  background: linear-gradient(145deg, #ffffff 0%, #f6f8ff 100%);
  border-radius: 32px;
  box-shadow: 0 32px 70px rgba(5, 3, 140, 0.18);
  padding: clamp(1.7rem, 2.9vw, 2.65rem);
  display: flex;
  flex-direction: column;
  gap: 1.7rem;
  position: relative;
  overflow: hidden;
  font-family: 'DM Sans', 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

.vm-carrusel-detail .vm-detail-card::after {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: radial-gradient(circle at top right, rgba(96, 195, 232, 0.24), transparent 55%), radial-gradient(circle at bottom left, rgba(5, 3, 140, 0.16), transparent 60%);
  opacity: 0.85;
}

.vm-carrusel-detail .vm-detail-card > * {
  position: relative;
}

.vm-carrusel-detail .vm-detail-content {
  display: flex;
  flex-direction: column;
  gap: 1.45rem;
}

.vm-carrusel-detail .vm-detail-top {
  display: flex;
  flex-wrap: wrap;
  gap: 1.6rem;
  align-items: center;
}

.vm-carrusel-detail .vm-detail-avatar {
  width: clamp(124px, 18vw, 168px);
  height: clamp(124px, 18vw, 168px);
  border-radius: 40px;
  overflow: hidden;
  background: rgba(255, 255, 255, 0.9);
  display: grid;
  place-items: center;
  font-weight: 700;
  font-size: clamp(2.1rem, 3vw, 2.7rem);
  color: var(--vm-dark-blue);
  box-shadow: inset 0 0 0 1px rgba(5, 3, 140, 0.08), 0 18px 32px rgba(5, 3, 140, 0.12);
}

.vm-carrusel-detail .vm-detail-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.vm-carrusel-detail .vm-detail-texts {
  flex: 1 1 260px;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  text-align: left;
}

.vm-carrusel-detail .vm-detail-name {
  font-size: clamp(1.7rem, 3.4vw, 2.35rem);
  font-weight: 800;
  color: #06083B;
  margin: 0;
  letter-spacing: -0.01em;
}

.vm-carrusel-detail .vm-detail-job {
  font-size: clamp(1rem, 2.1vw, 1.24rem);
  font-weight: 600;
  color: rgba(5, 3, 140, 0.68);
  margin: 0;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  line-height: 1.35;
}

.vm-carrusel-detail .vm-detail-specialties {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-top: 0.65rem;
}

.vm-carrusel-detail .vm-pill {
  display: inline-block !important;
  padding: 0.28rem 0.65rem !important;
  background: rgba(5, 3, 140, 0.08) !important;
  color: var(--vm-dark-blue) !important;
  border-radius: 999px !important;
  font-size: 0.78rem !important;
  font-weight: 600 !important;
  line-height: 1.2 !important;
  border: none !important;
  text-decoration: none !important;
}

.vm-carrusel-detail .vm-detail-specialties .vm-pill {
  font-size: 0.86rem !important;
  padding: 0.32rem 0.72rem !important;
}

.vm-carrusel-detail .vm-detail-body {
  font-size: 1.05rem;
  line-height: 1.72;
  color: rgba(38, 48, 75, 0.95);
  background: rgba(255, 255, 255, 0.72);
  padding: 1.15rem 1.35rem;
  border-radius: 1.2rem;
  box-shadow: inset 0 0 0 1px rgba(5, 3, 140, 0.06);
  text-align: left;
}

.vm-carrusel-detail .vm-detail-body p {
  margin: 0 0 0.95rem;
}

.vm-carrusel-detail .vm-detail-body p:last-child {
  margin-bottom: 0;
}

.vm-carrusel-detail .vm-detail-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.vm-carrusel-detail .vm-detail-link {
  padding: 0.72rem 1.8rem;
  border-radius: 999px;
  font-weight: 600;
  text-decoration: none;
  background: var(--vm-dark-blue);
  color: #fff;
  box-shadow: 0 16px 34px rgba(5, 3, 140, 0.24);
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.vm-carrusel-detail .vm-detail-link:hover {
  transform: translateY(-2px);
  box-shadow: 0 20px 40px rgba(5, 3, 140, 0.3);
}

.vm-carrusel-detail .vm-agenda-link {
  padding: 0.72rem 1.8rem;
  border-radius: 999px;
  font-weight: 600;
  text-decoration: none;
  background: #D7A9E3;
  color: #fff;
  box-shadow: 0 16px 34px rgba(215, 169, 227, 0.28);
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.vm-carrusel-detail .vm-agenda-link:hover {
  transform: translateY(-2px);
  box-shadow: 0 20px 40px rgba(215, 169, 227, 0.36);
  background: #C997DF;
}

.vm-carrusel-detail .vm-detail-close {
  position: absolute;
  top: 1.3rem;
  right: 1.3rem;
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.6rem 1.6rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.92);
  color: var(--vm-dark-blue);
  font-weight: 600;
  border: 2px solid rgba(5, 3, 140, 0.2);
  cursor: pointer;
  transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
  z-index: 3;
}

.vm-carrusel-detail .vm-detail-close::before {
  content: '\2190';
  font-size: 1.1rem;
}

.vm-carrusel-detail .vm-detail-close:hover {
  transform: translateX(-3px);
  box-shadow: 0 12px 26px rgba(5, 3, 140, 0.2);
  border-color: rgba(5, 3, 140, 0.34);
}

.vm-carrusel-detail .vm-detail-close:focus-visible {
  outline: 3px solid var(--vm-icon-blue);
  outline-offset: 3px;
}

.vm-detail-loading {
  text-align: center;
  padding: 1.5rem 0;
  color: var(--vm-body-text);
  font-weight: 600;
}

@media (max-width: 1024px) {
  .vm-carrusel-wrapper {
    gap: 24px;
    justify-content: center;
  }
}

@media (max-width: 768px) {
  .vm-carrusel-container {
    padding: clamp(28px, 7vw, 44px);
  }

  .vm-carrusel-header {
    flex-direction: column;
    align-items: flex-start;
  }

  .vm-specialty-selector {
    width: 100%;
  }

  .vm-carrusel-wrapper {
    display: grid;
    grid-template-columns: repeat(2, auto);
    grid-template-rows: auto auto;
    justify-content: center;
    align-items: center;
    gap: 20px;
  }

  .vm-carrusel-track {
    grid-column: 1 / span 2;
    grid-row: 1;
    gap: 32px;
    overflow: visible;
    justify-content: center;
  }

  .vm-doctor-item {
    min-width: 0;
    flex: 0 0 auto;
  }

  .vm-carrusel-prev,
  .vm-carrusel-next {
    grid-row: 2;
    width: 54px;
    height: 54px;
    font-size: 1.8rem;
    margin: 0;
  }

  .vm-carrusel-prev {
    grid-column: 1;
    justify-self: end;
  }

  .vm-carrusel-next {
    grid-column: 2;
    justify-self: start;
  }

  .vm-doctor-circle {
    width: clamp(150px, 45vw, 190px);
    height: clamp(150px, 45vw, 190px);
  }

  .vm-carrusel-detail .vm-detail-card {
    padding: 1.55rem;
    border-radius: 1.9rem;
  }

  .vm-carrusel-detail .vm-detail-avatar {
    width: 120px;
    height: 120px;
    border-radius: 32px;
    font-size: 2.2rem;
  }

  .vm-carrusel-detail .vm-detail-actions {
    justify-content: center;
  }

  .vm-carrusel-detail .vm-detail-link {
    width: 100%;
    text-align: center;
  }

  .vm-carrusel-detail .vm-agenda-link {
    width: 100%;
    text-align: center;
  }

  .vm-carrusel-detail .vm-detail-close {
    top: 1rem;
    right: 1rem;
  }
}

@media (max-width: 520px) {
  .vm-carrusel-title {
    font-size: clamp(1.8rem, 9vw, 2.4rem);
    text-align: center;
  }

  .vm-carrusel-title::after {
    margin-left: auto;
    margin-right: auto;
  }

  .vm-carrusel-header {
    align-items: center;
  }

  .vm-specialty-toggle {
    justify-content: center;
    text-align: center;
  }

  .vm-specialty-label {
    text-align: center;
  }

  .vm-carrusel-wrapper {
    gap: 18px;
  }

  .vm-carrusel-container {
    padding: clamp(20px, 5vw, 32px);
  }

  .vm-carrusel-track {
    gap: 0;
    max-width: 100%;
    padding: 0 20px;
  }

  .vm-doctor-item {
    gap: 36px;
    padding-bottom: 20px;
    width: 100%;
    max-width: 240px;
  }

  .vm-doctor-circle {
    width: 180px;
    height: 180px;
    border: 5px solid rgba(215, 169, 227, 0.65);
    position: relative;
  }

  .vm-carrusel-prev,
  .vm-carrusel-next {
    width: 48px;
    height: 48px;
    font-size: 1.5rem;
  }

  /* Indicador de doble click en móvil */
  .vm-doctor-circle::before {
    content: '👆';
    position: absolute;
    bottom: -40px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 1.2rem;
    opacity: 0.6;
    animation: tapHint 2s ease-in-out infinite;
  }

  @keyframes tapHint {
    0%, 100% { transform: translateX(-50%) translateY(0); opacity: 0.6; }
    50% { transform: translateX(-50%) translateY(-5px); opacity: 1; }
  }
  
  .vm-doctor-name {
    min-width: 140px;
    max-width: 200px;
    font-size: 0.85rem;
    padding: 8px 16px;
    transform: translate(-50%, 60px) scale(0.95);
  }
  
  .vm-doctor-item:hover .vm-doctor-name {
    font-size: 0.95rem;
    transform: translate(-50%, 8px) scale(1.05);
  }

  .vm-carrusel-nav {
    width: 48px;
    height: 48px;
    font-size: 1.4rem;
  }

  .vm-carrusel-detail .vm-detail-card {
    padding: 1.3rem;
  }

  .vm-carrusel-detail .vm-detail-avatar {
    width: 100px;
    height: 100px;
    border-radius: 28px;
    font-size: 1.8rem;
  }

  .vm-carrusel-detail .vm-detail-name {
    font-size: 1.4rem;
  }

  .vm-carrusel-detail .vm-detail-job {
    font-size: 0.95rem;
  }

  .vm-carrusel-detail .vm-detail-body {
    font-size: 0.95rem;
    padding: 1rem 1.1rem;
  }
}
CSS;

    wp_add_inline_style('vm-carrusel-style', $css);

    $js = <<<'JS'
(function() {
  const root = document.getElementById('__ROOT_ID__');
  if (!root) return;

  const selector = root.querySelector('.vm-specialty-selector');
  const toggle = root.querySelector('.vm-specialty-toggle');
  const label = root.querySelector('.vm-specialty-label');
  const menu = root.querySelector('.vm-specialty-menu');
  const wrapper = root.querySelector('.vm-carrusel-wrapper');
  const track = root.querySelector('.vm-carrusel-track');
  const prevBtn = root.querySelector('.vm-carrusel-prev');
  const nextBtn = root.querySelector('.vm-carrusel-next');
  const detailView = root.querySelector('.vm-carrusel-detail');
  const detailCard = detailView?.querySelector('.vm-detail-card');
  const detailClose = detailView?.querySelector('.vm-detail-close');

  let allData = [];
  let specialtiesWithDoctors = [];
  let currentSpecialty = null;
  let currentDoctors = [];
  let currentIndex = 0;
  let autoRotateInterval = null;
  let specialtyRotateInterval = null;
  let isUserInteracted = false;
  let isMenuOpen = false;
  let menuOptions = [];
  let menuCloseTimer = null;
  let menuOpenedFromAuto = false;

  let DOCTORS_PER_PAGE = 3;
  const AUTO_ROTATE_DELAY = 5000;
  const SPECIALTY_ROTATE_DELAY = 5000;
  const AUTO_OPTION_VALUE = '__auto__';
  const DEFAULT_LABEL = 'Selecciona una especialidad';

  // Función para ajustar el número de doctores según el tamaño de pantalla
  function updateDoctorsPerPage() {
    const width = window.innerWidth;
    if (width <= 520) {
      DOCTORS_PER_PAGE = 1; // En móvil mostrar 1 doctor completo
    } else if (width <= 768) {
      DOCTORS_PER_PAGE = 2; // En tablet mostrar 2 doctores
    } else {
      DOCTORS_PER_PAGE = 3; // En desktop mostrar 3 doctores
    }

    if (currentDoctors.length && currentIndex + DOCTORS_PER_PAGE > currentDoctors.length) {
      currentIndex = Math.max(0, currentDoctors.length - DOCTORS_PER_PAGE);
    }
  }

  // Llamar al inicio y cuando cambie el tamaño de ventana
  updateDoctorsPerPage();
  window.addEventListener('resize', () => {
    updateDoctorsPerPage();
    renderDoctors();
    updateNavButtons();
  });

  setLabel(DEFAULT_LABEL, { muted: true });

  function setLabel(text, options) {
    const value = text || DEFAULT_LABEL;
    const opts = options || {};
    if (label) {
      label.textContent = value;
      label.classList.toggle('is-muted', !!opts.muted);
    }
    if (selector) {
      selector.classList.toggle('is-auto', !!opts.muted);
    }
  }

  function highlightOption(value) {
    if (!menuOptions.length) return;
    menuOptions.forEach(btn => {
      const buttonValue = btn.getAttribute('data-value') || '';
      const isActive = value && buttonValue === value;
      btn.classList.toggle('is-active', isActive);
      btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
  }

  function stopAutoRotation() {
    if (autoRotateInterval) {
      clearInterval(autoRotateInterval);
      autoRotateInterval = null;
    }
  }

  function startAutoRotation() {
    stopAutoRotation();
    if (currentDoctors.length <= DOCTORS_PER_PAGE) return;
    autoRotateInterval = setInterval(() => {
      if (isUserInteracted) return;
      goNext();
    }, AUTO_ROTATE_DELAY);
  }

  function stopSpecialtyAutoRotation() {
    if (specialtyRotateInterval) {
      clearInterval(specialtyRotateInterval);
      specialtyRotateInterval = null;
    }
  }

  function startSpecialtyAutoRotation(force) {
    if (isUserInteracted && !force) return;
    if (!specialtiesWithDoctors.length) return;

    if (force) {
      isUserInteracted = false;
    }

    stopSpecialtyAutoRotation();

    if (specialtiesWithDoctors.length === 1) {
      const onlySpecialty = specialtiesWithDoctors[0];
      displaySpecialty(onlySpecialty, { auto: false });
      return;
    }

    let index = 0;

    const applySpecialty = (specialty) => {
      if (!specialty) return;
      displaySpecialty(specialty, { auto: true });
    };

    applySpecialty(specialtiesWithDoctors[index]);

    specialtyRotateInterval = setInterval(() => {
      if (isUserInteracted) {
        stopSpecialtyAutoRotation();
        return;
      }
      index = (index + 1) % specialtiesWithDoctors.length;
      applySpecialty(specialtiesWithDoctors[index]);
    }, SPECIALTY_ROTATE_DELAY);
  }

  function renderDoctors() {
    if (!track) return;
    if (!currentDoctors.length) {
      track.innerHTML = '<div class="vm-carrusel-empty">No hay doctores disponibles para esta especialidad.</div>';
      if (prevBtn) prevBtn.disabled = true;
      if (nextBtn) nextBtn.disabled = true;
      return;
    }

    const visibleDoctors = currentDoctors.slice(currentIndex, currentIndex + DOCTORS_PER_PAGE);
    track.innerHTML = '';

    visibleDoctors.forEach((doctor, idx) => {
      const item = document.createElement('div');
      item.className = 'vm-doctor-item';
      item.style.animationDelay = `${idx * 0.12}s`;

      const circle = document.createElement('div');
      circle.className = 'vm-doctor-circle';

      const nameCandidates = [];
      if (doctor) {
        if (doctor.nombre_completo && doctor.nombre_completo.trim()) {
          nameCandidates.push(doctor.nombre_completo.trim());
        } else {
          if (doctor.nombre) nameCandidates.push(doctor.nombre);
          if (doctor.apellido) nameCandidates.push(doctor.apellido);
        }
      }
      const fullName = nameCandidates.join(' ').trim() || 'Doctor';

      if (doctor && doctor.imagen) {
        const img = document.createElement('img');
        img.src = doctor.imagen;
        img.alt = fullName;
        img.loading = 'lazy';
        circle.appendChild(img);
      } else {
        const placeholder = document.createElement('div');
        placeholder.className = 'vm-doctor-placeholder';
        const initialSource = fullName.trim();
        placeholder.textContent = (initialSource.charAt(0) || 'D').toUpperCase();
        circle.appendChild(placeholder);
      }

      const name = document.createElement('div');
      name.className = 'vm-doctor-name';
      name.textContent = fullName;

      // Agregar click handler al círculo
      circle.style.cursor = 'pointer';
      circle.tabIndex = 0;
      circle.setAttribute('role', 'button');
      circle.setAttribute('aria-label', `Ver información de ${fullName}`);
      
      let clickCount = 0;
      let clickTimer = null;
      
      const handleActivation = (ev) => {
        if (ev.type === 'keydown' && ev.key !== 'Enter' && ev.key !== ' ') return;
        ev.preventDefault();
        
        // En móvil (pantallas menores a 768px), requerir doble click
        const isMobile = window.innerWidth <= 768;
        
        if (isMobile && ev.type === 'click') {
          clickCount++;
          if (clickCount === 1) {
            // Primer click: mostrar el nombre
            name.style.opacity = '1';
            name.style.transform = 'translate(-50%, 10px) scale(1.08)';
            
            // Resetear después de 2 segundos
            clickTimer = setTimeout(() => {
              clickCount = 0;
              name.style.opacity = '';
              name.style.transform = '';
            }, 2000);
          } else if (clickCount === 2) {
            // Segundo click: abrir detalle
            clearTimeout(clickTimer);
            clickCount = 0;
            openDoctorDetail(doctor, circle);
          }
        } else {
          // En desktop o con teclado, abrir directamente
          openDoctorDetail(doctor, circle);
        }
      };
      
      circle.addEventListener('click', handleActivation);
      circle.addEventListener('keydown', handleActivation);

      item.appendChild(circle);
      item.appendChild(name);
      track.appendChild(item);
    });

    updateNavButtons();
  }

  function updateNavButtons() {
    const hasPrev = currentIndex > 0;
    const hasNext = currentIndex + DOCTORS_PER_PAGE < currentDoctors.length;
    if (prevBtn) prevBtn.disabled = !hasPrev;
    if (nextBtn) nextBtn.disabled = !hasNext;
  }

  function goPrev() {
    if (currentIndex <= 0) return;
    currentIndex = Math.max(0, currentIndex - DOCTORS_PER_PAGE);
    renderDoctors();
  }

  function goNext() {
    if (!currentDoctors.length) return;
    if (currentIndex + DOCTORS_PER_PAGE >= currentDoctors.length) {
      currentIndex = 0;
    } else {
      currentIndex += DOCTORS_PER_PAGE;
    }
    renderDoctors();
  }

  function goNextManual() {
    if (currentIndex + DOCTORS_PER_PAGE >= currentDoctors.length) return;
    currentIndex += DOCTORS_PER_PAGE;
    renderDoctors();
  }

  function displaySpecialty(specialtyData, options = {}) {
    stopAutoRotation();
    if (detailView && detailView.classList.contains('is-active')) {
      closeDoctorDetail({ restoreFocus: false });
    }

    if (!specialtyData) {
      currentSpecialty = null;
      currentDoctors = [];
      currentIndex = 0;
      setLabel(DEFAULT_LABEL, { muted: true });
      highlightOption('');
      renderDoctors();
      return;
    }

    currentSpecialty = specialtyData.nombre || null;
    currentDoctors = Array.isArray(specialtyData.doctores) ? specialtyData.doctores.slice() : [];
    currentIndex = 0;

    const isAuto = !!options.auto && !isUserInteracted;
    const labelText = currentSpecialty || DEFAULT_LABEL;

    setLabel(labelText, { muted: isAuto });
    highlightOption(currentSpecialty || '');

    renderDoctors();

    if (currentDoctors.length > DOCTORS_PER_PAGE && !isUserInteracted) {
      startAutoRotation();
    }
  }

  function handleOptionSelection(value) {
    if (value === AUTO_OPTION_VALUE) {
      isUserInteracted = false;
      menuOpenedFromAuto = false;
      closeMenu({ focusToggle: true });
      startSpecialtyAutoRotation(true);
      return;
    }

    const specialtyData = allData.find(esp => esp.nombre === value);
    if (!specialtyData) return;

    isUserInteracted = true;
    menuOpenedFromAuto = false;
    stopSpecialtyAutoRotation();
    closeMenu({ focusToggle: true });
    displaySpecialty(specialtyData, { auto: false });
  }

  function populateMenu() {
    if (!menu) return;

    if (menuCloseTimer) {
      clearTimeout(menuCloseTimer);
      menuCloseTimer = null;
    }

    menu.innerHTML = '';
    menu.hidden = true;
    menu.setAttribute('aria-hidden', 'true');
    if (selector) {
      selector.classList.remove('is-open');
    }
    isMenuOpen = false;
    menuOptions = [];
    menuOpenedFromAuto = false;

    specialtiesWithDoctors = Array.isArray(allData)
      ? allData.filter(esp => Array.isArray(esp.doctores) && esp.doctores.length > 0)
      : [];

    if (!specialtiesWithDoctors.length) {
      setLabel('Sin especialidades disponibles', { muted: true });
      if (track) {
        track.innerHTML = '<div class="vm-carrusel-empty">No hay especialistas disponibles en este momento.</div>';
      }
      if (prevBtn) prevBtn.disabled = true;
      if (nextBtn) nextBtn.disabled = true;
      return;
    }

    const fragment = document.createDocumentFragment();

    if (specialtiesWithDoctors.length > 1) {
      const autoButton = document.createElement('button');
      autoButton.type = 'button';
      autoButton.className = 'vm-specialty-option';
      autoButton.setAttribute('role', 'option');
      autoButton.setAttribute('data-value', AUTO_OPTION_VALUE);
      autoButton.textContent = 'Ver todas las especialidades';
      autoButton.addEventListener('mousedown', (e) => e.stopPropagation());
      autoButton.addEventListener('touchstart', (e) => e.stopPropagation(), { passive: true });
      autoButton.addEventListener('click', (e) => {
        e.stopPropagation();
        handleOptionSelection(AUTO_OPTION_VALUE);
      });
      fragment.appendChild(autoButton);
    }

    specialtiesWithDoctors.forEach(esp => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'vm-specialty-option';
      btn.setAttribute('role', 'option');
      btn.setAttribute('data-value', esp.nombre);
      btn.textContent = esp.nombre;
      btn.addEventListener('mousedown', (e) => e.stopPropagation());
      btn.addEventListener('touchstart', (e) => e.stopPropagation(), { passive: true });
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        handleOptionSelection(esp.nombre);
      });
      fragment.appendChild(btn);
    });

    menu.appendChild(fragment);
    menuOptions = Array.from(menu.querySelectorAll('.vm-specialty-option'));
    menuOptions.forEach(btn => {
      btn.tabIndex = -1;
      btn.setAttribute('aria-selected', 'false');
    });
    highlightOption(currentSpecialty || '');
  }

  function openMenu() {
    if (!selector || !menu || isMenuOpen) return;
    if (menuCloseTimer) {
      clearTimeout(menuCloseTimer);
      menuCloseTimer = null;
    }
    menuOpenedFromAuto = !isUserInteracted && !!specialtyRotateInterval;
    stopSpecialtyAutoRotation();
    stopAutoRotation();
    menu.hidden = false;
    menu.setAttribute('aria-hidden', 'false');
    selector.classList.add('is-open');
    isMenuOpen = true;
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'true');
    }
    const active = menu.querySelector('.vm-specialty-option.is-active') || menu.querySelector('.vm-specialty-option');
    if (active) {
      active.focus({ preventScroll: true });
    }
  }

  function closeMenu(options = {}) {
    if (!selector || !menu || !isMenuOpen) return;
    const { focusToggle } = options;
    selector.classList.remove('is-open');
    isMenuOpen = false;
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
    }
    menu.setAttribute('aria-hidden', 'true');
    if (menuCloseTimer) {
      clearTimeout(menuCloseTimer);
    }
    menuCloseTimer = setTimeout(() => {
      menu.hidden = true;
      menuCloseTimer = null;
    }, 180);
    if (focusToggle && toggle) {
      toggle.focus();
    }
    if (menuOpenedFromAuto && !isUserInteracted) {
      startSpecialtyAutoRotation(true);
    }
    menuOpenedFromAuto = false;
  }

  function handleMenuKeyDown(ev) {
    if (!isMenuOpen) return;
    if (!menuOptions.length) return;
    const key = ev.key;

    if (key === 'Escape') {
      ev.preventDefault();
      closeMenu({ focusToggle: true });
      return;
    }

    let index = menuOptions.indexOf(document.activeElement);
    if (key === 'ArrowDown') {
      ev.preventDefault();
      index = index >= 0 ? (index + 1) % menuOptions.length : 0;
      menuOptions[index].focus();
    } else if (key === 'ArrowUp') {
      ev.preventDefault();
      index = index >= 0 ? (index - 1 + menuOptions.length) % menuOptions.length : menuOptions.length - 1;
      menuOptions[index].focus();
    } else if (key === 'Home') {
      ev.preventDefault();
      menuOptions[0].focus();
    } else if (key === 'End') {
      ev.preventDefault();
      menuOptions[menuOptions.length - 1].focus();
    }
  }

  function handleDocumentClick(ev) {
    if (!isMenuOpen || !selector) return;
    if (selector.contains(ev.target)) return;
    setTimeout(() => {
      if (isMenuOpen) {
        closeMenu();
      }
    }, 10);
  }

  function handleDocumentKeyDown(ev) {
    if (ev.key === 'Escape') {
      if (detailView && detailView.classList.contains('is-active')) {
        ev.preventDefault();
        closeDoctorDetail();
        return;
      }
      if (isMenuOpen) {
        ev.preventDefault();
        closeMenu({ focusToggle: true });
      }
    }
  }

  async function loadData() {
    setLabel('Cargando especialidades...', { muted: true });
    if (track) {
      track.innerHTML = '<div class="vm-carrusel-loading">Cargando especialidades...</div>';
    }

    try {
      const response = await fetch('__ENDPOINT__');
      const data = await response.json();
      allData = Array.isArray(data) ? data : [];
      populateMenu();
      startSpecialtyAutoRotation(true);
    } catch (error) {
      console.error('Error loading carrusel data:', error);
      if (track) {
        track.innerHTML = '<div class="vm-carrusel-empty">No pudimos cargar la información. Intenta de nuevo más tarde.</div>';
      }
      setLabel('Especialidad no disponible', { muted: true });
      if (prevBtn) prevBtn.disabled = true;
      if (nextBtn) nextBtn.disabled = true;
    }
  }

  if (toggle) {
    toggle.addEventListener('click', ev => {
      ev.preventDefault();
      if (isMenuOpen) {
        closeMenu({ focusToggle: true });
      } else {
        openMenu();
      }
    });

    toggle.addEventListener('keydown', ev => {
      if (ev.key === 'ArrowDown' || ev.key === 'Enter' || ev.key === ' ') {
        ev.preventDefault();
        openMenu();
      } else if (ev.key === 'Escape') {
        ev.preventDefault();
        closeMenu({ focusToggle: true });
      }
    });
  }

  if (menu) {
    menu.addEventListener('keydown', handleMenuKeyDown);
    menu.addEventListener('click', ev => ev.stopPropagation());
    menu.addEventListener('mousedown', ev => ev.stopPropagation());
    menu.addEventListener('touchstart', ev => ev.stopPropagation(), { passive: true });
  }

  document.addEventListener('click', handleDocumentClick);
  document.addEventListener('keydown', handleDocumentKeyDown);

  // Botones de navegación desktop
  if (prevBtn) {
    prevBtn.addEventListener('click', () => {
      isUserInteracted = true;
      stopAutoRotation();
      goPrev();
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', () => {
      isUserInteracted = true;
      stopAutoRotation();
      goNextManual();
    });
  }

  if (track) {
    track.addEventListener('mouseenter', () => {
      stopAutoRotation();
    });

    track.addEventListener('mouseleave', () => {
      if (!isUserInteracted && currentDoctors.length > DOCTORS_PER_PAGE) {
        startAutoRotation();
      }
    });
  }

  // Vista de detalle del doctor
  let wrapperHideTimer = null;
  let detailHideTimer = null;
  let lastFocusedCircle = null;
  let doctorsCache = null;
  let doctorsPromise = null;

  function normalizeName(str) {
    return (str || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim();
  }

  function getFullName(doctor) {
    if (!doctor) return 'Doctor';
    if (doctor.nombre_completo && typeof doctor.nombre_completo === 'string' && doctor.nombre_completo.trim()) {
      return doctor.nombre_completo.trim();
    }
    const parts = [];
    const nombre = (doctor.nombre || '').trim();
    const primerApellido = (doctor.primer_apellido || doctor.apellido || '').trim();
    const segundoApellido = (doctor.segundo_apellido || '').trim();
    if (nombre) parts.push(nombre);
    if (primerApellido) parts.push(primerApellido);
    if (segundoApellido) parts.push(segundoApellido);
    const joined = parts.join(' ').trim();
    return joined || 'Doctor';
  }

  function escapeHTML(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function initials(nombre, primerApellido, segundoApellido) {
    const letters = [];
    const name = (nombre || '').trim();
    const last = (primerApellido || '').trim();
    const last2 = (segundoApellido || '').trim();
    if (name) letters.push(name.charAt(0).toUpperCase());
    if (last) {
      letters.push(last.charAt(0).toUpperCase());
    } else if (last2) {
      letters.push(last2.charAt(0).toUpperCase());
    }
    return letters.join('') || 'M';
  }

  function specialtiesDetail(specs) {
    if (!Array.isArray(specs) || !specs.length) return '';
    return specs.map(s => '<span class="vm-pill">' + escapeHTML(s) + '</span>').join('');
  }

  function pruneRatings(container) {
    if (!container) return;
    const ratingSelectors = ['[class*="rating" i]', '.ct-rating', '.ct-ratings', '.team-ratings', '.ratings', '[data-rating]'];
    ratingSelectors.forEach(sel => {
      container.querySelectorAll(sel).forEach(node => node.remove());
    });
    container.querySelectorAll('h1,h2,h3,h4,h5,h6,p,div,span,strong').forEach(node => {
      const text = (node.textContent || '').trim().toLowerCase();
      if (!text) return;
      if (text === 'valoraciones' || text === 'valoración' || text.startsWith('valoraciones ')) {
        const next = node.nextElementSibling;
        node.remove();
        if (next && /rating/i.test(next.className || '')) {
          next.remove();
        }
      }
    });
  }

  function setDetailLoading(name, doctor) {
    if (!detailCard) return;
    const nameNode = detailCard.querySelector('.vm-detail-name');
    const jobNode = detailCard.querySelector('.vm-detail-job');
    const specsNode = detailCard.querySelector('.vm-detail-specialties');
    const bodyNode = detailCard.querySelector('.vm-detail-body');
    const linkNode = detailCard.querySelector('.vm-detail-link');
    if (nameNode) nameNode.textContent = name || 'Cargando médico';
    if (jobNode) {
      jobNode.textContent = '';
      jobNode.style.display = 'none';
    }
    if (specsNode) {
      if (doctor && Array.isArray(doctor.especialidades) && doctor.especialidades.length) {
        specsNode.innerHTML = specialtiesDetail(doctor.especialidades);
        specsNode.style.display = '';
      } else {
        specsNode.innerHTML = '';
        specsNode.style.display = 'none';
      }
    }
    if (bodyNode) {
      bodyNode.innerHTML = '<p class="vm-detail-loading">Cargando información...</p>';
      bodyNode.style.display = '';
    }
    if (linkNode) {
      linkNode.removeAttribute('href');
      linkNode.style.display = 'none';
    }
    const agendaNode = detailCard.querySelector('.vm-agenda-link');
    if (agendaNode) {
      agendaNode.removeAttribute('href');
      agendaNode.style.display = 'none';
    }
    const avatarNode = detailCard.querySelector('.vm-detail-avatar');
    if (avatarNode) {
      if (doctor && doctor.imagen) {
        avatarNode.innerHTML = '<img src="' + doctor.imagen + '" alt="' + escapeHTML(name || '') + '">';
      } else {
        const nombre = doctor ? doctor.nombre : '';
        const primerApellido = doctor ? (doctor.primer_apellido || doctor.apellido) : '';
        const segundoApellido = doctor ? doctor.segundo_apellido : '';
        avatarNode.innerHTML = '<span>' + initials(nombre, primerApellido, segundoApellido) + '</span>';
      }
    }
  }

  function fillDetailCard(item) {
    if (!detailCard || !item) return;

    const avatarNode = detailCard.querySelector('.vm-detail-avatar');
    const nameNode = detailCard.querySelector('.vm-detail-name');
    const jobNode = detailCard.querySelector('.vm-detail-job');
    const specsNode = detailCard.querySelector('.vm-detail-specialties');
    const bodyNode = detailCard.querySelector('.vm-detail-body');
    const linkNode = detailCard.querySelector('.vm-detail-link');

    const nombre = (item.nombre || '').trim();
    const primerApellido = (item.primer_apellido || item.apellido || '').trim();
    const segundoApellido = (item.segundo_apellido || '').trim();
    const fullRaw = (item.nombre_completo || '').trim();
    const full = fullRaw || [nombre, primerApellido, segundoApellido].filter(Boolean).join(' ');
    const displayFull = full || 'Médico sin nombre';
    const image = (item.imagen || '').trim();

    if (avatarNode) {
      if (image) {
        avatarNode.innerHTML = '<img src="' + image + '" alt="' + escapeHTML(displayFull) + '">';
      } else {
        avatarNode.innerHTML = '<span>' + initials(nombre, primerApellido, segundoApellido) + '</span>';
      }
    }

    if (nameNode) {
      nameNode.textContent = displayFull;
    }

    if (jobNode) {
      const cargo = item.team_member && item.team_member.cargo ? item.team_member.cargo : '';
      const cargoRaw = typeof cargo === 'string' ? cargo.trim() : '';
      let cargoText = cargoRaw;
      if (cargoText) {
        if (typeof cargoText.toLocaleLowerCase === 'function') {
          cargoText = cargoText.toLocaleLowerCase('es-MX');
        } else {
          cargoText = cargoText.toLowerCase();
        }
      }
      jobNode.textContent = cargoText;
      jobNode.style.display = cargoText ? '' : 'none';
    }

    if (specsNode) {
      specsNode.innerHTML = specialtiesDetail(item.especialidades);
      specsNode.style.display = specsNode.innerHTML ? '' : 'none';
    }

    if (bodyNode) {
      const contenido = item.team_member && item.team_member.contenido ? item.team_member.contenido : '';
      if (contenido) {
        bodyNode.innerHTML = contenido;
        bodyNode.style.display = '';
      } else if (item.team_member && item.team_member.resumen) {
        bodyNode.textContent = item.team_member.resumen;
        bodyNode.style.display = '';
      } else {
        bodyNode.innerHTML = '';
        bodyNode.style.display = 'none';
      }
      pruneRatings(bodyNode);
    }

    if (linkNode) {
      const enlace = item.team_member && item.team_member.enlace ? item.team_member.enlace : (item.url || '');
      if (enlace && enlace !== '#') {
        linkNode.setAttribute('href', enlace);
        linkNode.style.display = '';
      } else {
        linkNode.removeAttribute('href');
        linkNode.style.display = 'none';
      }
    }

    const agendaNode = detailCard.querySelector('.vm-agenda-link');
    if (agendaNode) {
      const ameliaId = item.id;
      if (ameliaId) {
        agendaNode.setAttribute('href', 'https://virtualmd.mx/?ameliaEmployeeId=' + ameliaId + '#agendar-consulta-widget');
        agendaNode.style.display = '';
      } else {
        agendaNode.removeAttribute('href');
        agendaNode.style.display = 'none';
      }
    }
  }

  function hideWrapper() {
    if (!wrapper) return;
    if (wrapperHideTimer) {
      clearTimeout(wrapperHideTimer);
      wrapperHideTimer = null;
    }
    
    // Capturar la altura actual del carrusel antes de ocultarlo
    const container = wrapper.closest('.vm-carousel-container');
    if (container) {
      const currentHeight = wrapper.offsetHeight;
      container.style.minHeight = currentHeight + 'px';
    }
    
    wrapper.classList.add('is-hiding');
  }

  function showWrapper() {
    if (!wrapper) return;
    if (wrapperHideTimer) {
      clearTimeout(wrapperHideTimer);
      wrapperHideTimer = null;
    }
    wrapper.classList.remove('is-hiding');
    
    // Restaurar la altura del contenedor después de un momento
    const container = wrapper.closest('.vm-carousel-container');
    if (container) {
      setTimeout(() => {
        container.style.minHeight = '';
      }, 50);
    }
  }

  function showDetailView() {
    if (!detailView) return;
    if (detailHideTimer) {
      clearTimeout(detailHideTimer);
      detailHideTimer = null;
    }
    detailView.setAttribute('aria-hidden', 'false');
    detailView.classList.add('is-active');
    detailView.style.display = 'flex';
    
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        detailView.classList.add('is-visible');
        
        // Ajustar la altura del contenedor a la altura de la tarjeta después de que esté visible
        const container = detailView.closest('.vm-carousel-container');
        if (container) {
          setTimeout(() => {
            const detailHeight = detailView.offsetHeight;
            if (detailHeight > 0) {
              container.style.minHeight = detailHeight + 'px';
            }
          }, 100);
        }
      });
    });
  }

  function hideDetailView() {
    if (!detailView) return;
    detailView.classList.remove('is-visible');
    detailView.setAttribute('aria-hidden', 'true');
    if (detailHideTimer) {
      clearTimeout(detailHideTimer);
    }
    detailHideTimer = setTimeout(() => {
      if (!detailView.classList.contains('is-visible')) {
        detailView.classList.remove('is-active');
        detailView.style.display = 'none';
      }
      detailHideTimer = null;
    }, 360);
  }

  async function getDoctorsCatalog() {
    if (Array.isArray(doctorsCache)) {
      return doctorsCache;
    }
    if (!doctorsPromise) {
      doctorsPromise = fetch('/wp-json/vm/v1/doctores')
        .then(response => response.json())
        .then(data => {
          doctorsCache = Array.isArray(data) ? data : [];
          return doctorsCache;
        })
        .catch(error => {
          doctorsPromise = null;
          throw error;
        });
    }
    return doctorsPromise;
  }

  async function fetchDoctorDetails(doctor, fallbackName) {
    if (!detailCard) return;

    try {
      const catalog = await getDoctorsCatalog();
      const normalizedFallback = normalizeName(fallbackName || getFullName(doctor));

      const idsToMatch = new Set();
      if (doctor && doctor.id != null) idsToMatch.add(String(doctor.id));
      if (doctor && doctor.provider_id != null) idsToMatch.add(String(doctor.provider_id));
      if (doctor && doctor.team_member_id != null) idsToMatch.add(String(doctor.team_member_id));
      if (doctor && doctor.team_member && doctor.team_member.id != null) idsToMatch.add(String(doctor.team_member.id));

      const matchedDoctor = Array.isArray(catalog) ? catalog.find(candidate => {
        if (!candidate) return false;
        const candidateIds = [candidate.id, candidate.provider_id, candidate.team_member_id, candidate.team_member && candidate.team_member.id]
          .filter(value => value != null)
          .map(value => String(value));
        if (candidateIds.some(id => idsToMatch.has(id))) {
          return true;
        }
        const candidateName = normalizeName(getFullName(candidate));
        return candidateName && candidateName === normalizedFallback;
      }) : null;

      if (matchedDoctor) {
        fillDetailCard(matchedDoctor);
      } else {
        fillDetailCard(doctor);
      }
    } catch (error) {
      console.error('Error al cargar datos del doctor:', error);
      fillDetailCard(doctor);
    }
  }

  function openDoctorDetail(doctor, triggerEl) {
    if (!detailView || !detailCard) return;
    lastFocusedCircle = triggerEl || null;
    isUserInteracted = true;
    stopAutoRotation();
    stopSpecialtyAutoRotation();

    const initialName = getFullName(doctor);
    setDetailLoading(initialName, doctor);
    
    // Primero ocultar el carrusel
    hideWrapper();
    
    // Después de que el carrusel se oculte, mostrar la tarjeta
    setTimeout(() => {
      showDetailView();
      if (detailClose) {
        setTimeout(() => detailClose.focus(), 100);
      }
    }, 320);

    fetchDoctorDetails(doctor, initialName);
  }

  function closeDoctorDetail(options = {}) {
    const { restoreFocus = true } = options;
    if (!detailView) {
      showWrapper();
      return;
    }

    const wasActive = detailView.classList.contains('is-active');

    if (wasActive) {
      hideDetailView();
      
      // Mostrar el carrusel después de que la tarjeta se oculte
      setTimeout(() => {
        showWrapper();
      }, 350);
    } else {
      showWrapper();
    }

    if (restoreFocus) {
      if (lastFocusedCircle) {
        setTimeout(() => {
          try {
            lastFocusedCircle.focus();
          } catch (_) {
            if (toggle) toggle.focus();
          }
        }, 700);
      } else if (toggle) {
        setTimeout(() => toggle.focus(), 700);
      }
    }

    lastFocusedCircle = null;
  }

  if (detailClose) {
    detailClose.addEventListener('click', closeDoctorDetail);
  }

  if (detailView) {
    detailView.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') {
        ev.preventDefault();
        closeDoctorDetail();
      }
    });
  }

  loadData();
})();
JS;

    $js = str_replace(
      ['__ENDPOINT__', '__ROOT_ID__'],
      [esc_url_raw($rest_url), esc_js($uid)],
      $js
    );

    wp_add_inline_script('vm-carrusel-script', $js);

    ob_start();
    ?>
    <div id="<?php echo esc_attr($uid); ?>" class="vm-carrusel-container">
    <div class="vm-carrusel-header">
      <h2 class="vm-carrusel-title">Selecciona una especialidad</h2>
      <div class="vm-specialty-selector">
        <button type="button" class="vm-specialty-toggle" aria-haspopup="listbox" aria-expanded="false">
          <span class="vm-specialty-label">Cargando especialidades...</span>
          <span class="vm-specialty-arrow" aria-hidden="true"></span>
        </button>
        <div class="vm-specialty-menu" role="listbox" aria-hidden="true" hidden></div>
      </div>
    </div>
        
    <div class="vm-carousel-container">
      <div class="vm-carrusel-wrapper">
        <button type="button" class="vm-carrusel-nav vm-carrusel-prev" aria-label="Ver doctores anteriores" disabled>
          <span aria-hidden="true">‹</span>
        </button>
              
        <div class="vm-carrusel-track">
          <div class="vm-carrusel-loading">Cargando doctores...</div>
        </div>
              
        <button type="button" class="vm-carrusel-nav vm-carrusel-next" aria-label="Ver doctores siguientes" disabled>
          <span aria-hidden="true">›</span>
        </button>
      </div>

      <div class="vm-carrusel-detail" aria-hidden="true">
      <div class="vm-detail-card">
        <button type="button" class="vm-detail-close">Regresar</button>
        <div class="vm-detail-content">
          <div class="vm-detail-top">
            <div class="vm-detail-avatar"></div>
            <div class="vm-detail-texts">
              <h3 class="vm-detail-name"></h3>
              <p class="vm-detail-job"></p>
              <div class="vm-detail-specialties"></div>
            </div>
          </div>
          <div class="vm-detail-body"></div>
          <div class="vm-detail-actions">
            <a class="vm-agenda-link" href="#" target="_blank" rel="noopener noreferrer">Agendar con este especialista</a>
            <a class="vm-detail-link" href="#" target="_blank" rel="noopener noreferrer">Ver perfil completo</a>
          </div>
        </div>
      </div>
    </div>
    </div>
    <?php
    return ob_get_clean();
  }

  private function normalize_name($name) {
    $prefixes = ['Dr\.', 'Dra\.', 'Dr', 'Dra', 'Lic\.', 'Lic', 'Mtro\.', 'Mtro'];
    $cleaned_name = preg_replace('/^(' . implode('|', $prefixes) . ')\s*/i', '', (string) $name);
    $cleaned_name = trim(preg_replace('/\s+/', ' ', $cleaned_name));
    return $this->normalize_text($cleaned_name);
  }

  private function normalize_text($text) {
    $text = (string) $text;

    if (function_exists('remove_accents')) {
      $text = remove_accents($text);
    } else {
      $iconv = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
      if ($iconv !== false) {
        $text = $iconv;
      }
    }

    $text = preg_replace('/\s+/', ' ', $text);

    return strtolower(trim($text));
  }
}

new VM_BuscadorMedicos_TM();
