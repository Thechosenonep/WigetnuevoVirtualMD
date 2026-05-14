<?php
if (!defined('ABSPATH')) { exit; }

class VM_Carrusel_Psicologia {
  const CPT      = 'ctshowcase_member';
  const META_JOB = 'ctshowcase_job_title';

  public function __construct() {
    add_action('rest_api_init', [$this, 'register_rest']);
    add_shortcode('vm_carrusel_psicologia', [$this, 'shortcode_psicologia']);
  }

  /** REST: /wp-json/vm/v1/psicologos */
  public function register_rest() {
    register_rest_route('vm/v1', '/psicologos', [
      'methods'  => 'GET',
      'callback' => function(\WP_REST_Request $req) {
        global $wpdb;

        $excluded_employees = get_option('vm_excluded_employees', []);
        $excluded_categories = get_option('vm_excluded_categories', []);

        // Obtener Team Members (necesario para imágenes)
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
        
        // Filtro específico para categoría padre 'Psicoterapia'
        // Asumimos que amelia_services tiene categoryId y amelia_categories tiene name
        $where_conditions[] = "c.name = 'Psicoterapia'";

        if (!empty($excluded_employees)) {
          $placeholders = implode(',', array_fill(0, count($excluded_employees), '%d'));
          $where_conditions[] = $wpdb->prepare("u.id NOT IN ($placeholders)", $excluded_employees);
        }

        $where_clause = implode(' AND ', $where_conditions);

        // JOIN con amelia_categories
        $provider_services = $wpdb->get_results(
            "
            SELECT u.id, u.firstName, u.lastName, u.email, s.name AS specialty
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
            
            // Saltar si no hay especialidad o está excluida
            if (!$specialty || in_array($specialty, $excluded_categories)) {
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

        return rest_ensure_response(array_values($especialidades_data));
      },
      'permission_callback' => '__return_true',
    ]);
  }

  /** Shortcode [vm_carrusel_psicologia] */
  public function shortcode_psicologia($atts) {
    // Reutilizamos los scripts y estilos del carrusel original si ya están registrados
    // pero como el CSS está inline en el método shortcode_carrusel original, 
    // lo duplicaré aquí para asegurar independencia y consistencia.
    // O mejor aún, registraré un nuevo handle para evitar conflictos si se usan ambos en la misma página,
    // aunque wp_add_inline_style permite agregar más CSS.
    
    // Como el código original usa wp_add_inline_style('vm-carrusel-style', $css) y define el CSS DENTRO del método,
    // si uso el shortcode nuevo, necesito asegurarme de que ese CSS esté presente.
    // Si la página no usa el shortcode original, el CSS original no se cargará.
    // Así que debo incluir el CSS completo aquí también.
    
    // Voy a definir un handle diferente para asegurar que se cargue.
    wp_register_style('vm-carrusel-psi-style', false);
    wp_enqueue_style('vm-carrusel-psi-style');
    
    // También necesito el script. El script original se inyecta inline.
    // Haré lo mismo.
    wp_register_script('vm-carrusel-psi-script', false, [], '1.0.0', true);
    wp_enqueue_script('vm-carrusel-psi-script');

    $uid = 'vm-carrusel-psi-' . uniqid();
    $rest_url = rest_url('vm/v1/psicologos');

    // Copia del CSS del carrusel original
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
  background: linear-gradient(145deg, #f2f5fc 0%, #e4eaf7 100%);
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

    wp_add_inline_style('vm-carrusel-psi-style', $css);

    // JS del carrusel, copiado del original pero adaptado con las variables nuevas
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
  const DEFAULT_LABEL = 'Selecciona a uno de nuestros psicólogos';

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

    wp_add_inline_script('vm-carrusel-psi-script', $js);

    ob_start();
    ?>
    <div id="<?php echo esc_attr($uid); ?>" class="vm-carrusel-container">
    <div class="vm-carrusel-header">
      <h2 class="vm-carrusel-title">Selecciona a uno de nuestros psicólogos</h2>
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

new VM_Carrusel_Psicologia();
