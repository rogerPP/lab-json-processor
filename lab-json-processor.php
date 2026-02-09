<?php
/**
 * Plugin Name: Lab JSON Processor
 * Description: Procesa JSON de laboratorio hacia CPT + ACF. Incluye activación por código, listado por usuario y vista detalle /mi-cuenta/test/XXXXX.
 * Version: 1.1.1
 * Author: Marketers Group
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Lab_JSON_Processor
{
    const JSON_DIR_NAME = 'lab-uploads';
    const JSON_DEST_DIR_NAME = 'json-lab';
    const PDF_DIR_NAME = 'resultadospdf';
    const CPT = 'lab_test';
    const TAX = 'tipo_test';
    const OPTION_LAST_RUN = 'labjson_last_run';

    const OPTION_LOG = 'labjson_process_log';

    const CRON_HOOK = 'labjson_process_event';

    const QUERY_VAR_CODE = 'lab_test_code';

    // Slugs de página (deben coincidir con tus páginas reales)
    const DETAIL_PAGENAME = 'mi-cuenta/test';

    public static function init(): void
    {
        // Core
        add_action('init', [__CLASS__, 'register_cpt_tax']);
        add_action('init', [__CLASS__, 'register_rewrite']);
        add_filter('query_vars', [__CLASS__, 'register_query_vars']);

        // Admin
        add_action('admin_menu', [__CLASS__, 'admin_menu']);

        // Cron
        add_action(self::CRON_HOOK, [__CLASS__, 'process_incoming_json']);

        // Front: resolver detalle antes de que ACF/shortcodes lo necesiten
        add_action('wp', [__CLASS__, 'resolve_detail_context']);

        // Elementor custom query (listado por usuario)
        add_action('elementor/query/mis_resultados_usuario', [__CLASS__, 'elementor_query_mis_resultados']);

        // ACF: forzar el post_id al lab_test en contexto detalle
        add_filter('acf/pre_load_post_id', [__CLASS__, 'acf_force_post_id']);

        // Assets
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Shortcodes
        add_shortcode('activar_test', [__CLASS__, 'shortcode_activar_test']);
        add_shortcode('lab_test_detalle', [__CLASS__, 'shortcode_lab_test_detalle']);
        add_shortcode('lab_test_grafica', [__CLASS__, 'shortcode_lab_test_grafica']);
        add_shortcode('if_tipo_test', [__CLASS__, 'shortcode_if_tipo_test']);
        add_shortcode('lab_test_valores', [__CLASS__, 'shortcode_lab_test_valores']);

        // Hooks de activación
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);

        // WP-CLI opcional
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('labjson process', function () {
                Lab_JSON_Processor::process_incoming_json(true);
                \WP_CLI::success('Procesamiento finalizado');
            });
        }
    }

    /* =======================
       CONFIG / PATHS
       ======================= */

    public static function json_dir(): string 
    {
        return rtrim(ABSPATH, '/') . '/' . self::JSON_DIR_NAME;
    }

    public static function pdf_dest_dir(): string 
    {
        return rtrim(ABSPATH, '/') . '/' . self::PDF_DIR_NAME;
    }

    public static function json_dest_dir(): string 
    {
        return rtrim(ABSPATH, '/') . '/' . self::JSON_DEST_DIR_NAME;
    }

    public static function processed_dir(): string
    {
        return rtrim(self::json_dest_dir(), '/') . '/processed';
    }

    public static function error_dir(): string
    {
        return rtrim(self::json_dest_dir(), '/') . '/error';
    }

    /* =======================
       ACTIVATE / DEACTIVATE
       ======================= */

    public static function activate(): void
    {
        self::register_cpt_tax();
        self::register_rewrite();
        flush_rewrite_rules();

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void
    {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
        flush_rewrite_rules();
    }

    /* =======================
       CPT + TAX
       ======================= */

    public static function register_cpt_tax(): void
    {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'Lab Tests',
                'singular_name' => 'Lab Test',
            ],
            'public' => false,
            'show_ui' => true,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'has_archive' => false,
            'rewrite' => false,
            'supports' => ['title'],
            'menu_icon' => 'dashicons-clipboard',
        ]);

        register_taxonomy(self::TAX, [self::CPT], [
            'labels' => [
                'name' => 'Tipo de test',
                'singular_name' => 'Tipo de test',
            ],
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'hierarchical' => true,
            'rewrite' => false,
        ]);

        foreach (['SIBO', 'Microbiota intestinal', 'Sensibilidad alimentaria'] as $term) {
            if (!term_exists($term, self::TAX)) {
                wp_insert_term($term, self::TAX);
            }
        }
    }

    /* =======================
       ADMIN PAGES
       ======================= */

    public static function admin_menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . self::CPT,
            'Procesar JSON',
            'Procesar JSON',
            'manage_options',
            'labjson-process',
            [__CLASS__, 'admin_page_process']
        );

        add_submenu_page(
            'edit.php?post_type=' . self::CPT,
            'Monitor JSON',
            'Monitor JSON',
            'manage_options',
            'labjson-monitor',
            [__CLASS__, 'admin_page_monitor']
        );
    }

    public static function admin_page_process(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $out = null;
        if (isset($_POST['labjson_run']) && check_admin_referer('labjson_run_action')) {
            $out = self::process_incoming_json(true);
            echo '<div class="notice notice-success"><p>Procesado ejecutado.</p></div>';
        }

        echo '<div class="wrap">';
        echo '<h1>Procesar JSON de laboratorio</h1>';
        echo '<p>Directorio: <code>' . esc_html(self::json_dir()) . '</code></p>';
        echo '<form method="post">';
        wp_nonce_field('labjson_run_action');
        echo '<button class="button button-primary" name="labjson_run" value="1">Procesar ahora</button>';
        echo '</form>';
        
        if ($out) {
            echo '<h2>Resultado</h2><pre>' . esc_html(print_r($out, true)) . '</pre>';
        }
        
        $last = get_option(self::OPTION_LAST_RUN);
        if ($last) {
            echo '<p><strong>Última ejecución:</strong> ' . esc_html($last) . '</p>';
        }

        echo '</div>';
    }

    public static function admin_page_monitor(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['labjson_run_now']) && check_admin_referer('labjson_run_now_action')) {
            self::process_incoming_json(true);
            echo '<div class="notice notice-success"><p>Procesado ejecutado.</p></div>';
        }

        $log = get_option(self::OPTION_LOG, []);

        echo '<div class="wrap">';
        echo '<h1>Monitor JSON</h1>';

        echo '<p>Directorio: <code>' . esc_html(self::json_dir()) . '</code></p>';

        echo '<form method="post" style="margin:20px 0">';
        wp_nonce_field('labjson_run_now_action');
        echo '<button class="button button-primary" name="labjson_run_now" value="1">Procesar JSON ahora</button>';
        echo '</form>';

        if (empty($log)) {
            echo '<p>No hay registros.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>
            <th>Fecha</th><th>Archivo</th><th>Estado</th><th>Post</th><th>Mensaje</th>
        </tr></thead><tbody>';

        foreach ($log as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row['timestamp'] ?? '') . '</td>';
            echo '<td><code>' . esc_html($row['file'] ?? '') . '</code></td>';
            echo '<td>' . esc_html($row['status'] ?? '') . '</td>';
            echo '<td>' . (!empty($row['post_link']) ? '<a href="' . esc_url($row['post_link']) . '">Ver</a>' : '—') . '</td>';
            echo '<td>' . esc_html($row['message'] ?? '') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /* =======================
       REWRITE / QUERY VAR
       ======================= */

    public static function register_query_vars(array $vars): array
    {
        $vars[] = self::QUERY_VAR_CODE;
        return $vars;
    }

    public static function register_rewrite(): void
    {
        // /mi-cuenta/test/XXXXX => Page mi-cuenta/test con query var lab_test_code
        add_rewrite_rule(
            '^mi-cuenta/test/([^/]+)/?$',
            'index.php?pagename=' . self::DETAIL_PAGENAME . '&' . self::QUERY_VAR_CODE . '=$matches[1]',
            'top'
        );
    }

    /* =======================
       ASSETS
       ======================= */

    public static function enqueue_assets(): void
    {
        // Cargar chartjs solo en la página detalle (o cuando se use el shortcode)
        if (get_query_var(self::QUERY_VAR_CODE)) {
            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js',
                [],
                null,
                true
            );
        }
    }

    /* =======================
       CONTEXT: detalle /mi-cuenta/test/XXXXX
       ======================= */

    public static function resolve_detail_context(): void
    {
        // 1️⃣ SOLO en la página /mi-cuenta/test/
        // Usa el slug REAL de la página (normalmente "test")
        if (!is_page('test')) {
            return;
        }

        // 2️⃣ SOLO si el código viene por URL (NO por POST)
        $code = get_query_var('lab_test_code');
        if (empty($code)) {
            return;
        }

        // 3️⃣ Seguridad: usuario logueado
        if (!is_user_logged_in()) {
            wp_redirect(wp_login_url());
            exit;
        }

        // 4️⃣ Buscar el Lab Test
        $q = new WP_Query([
            'post_type' => 'lab_test',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => 'num_peticion_procedencia',
                    'value' => $code,
                    'compare' => '='
                ]
            ]
        ]);

        if (empty($q->posts)) {
            wp_die('Resultado no encontrado', 'Error', ['response' => 404]);
        }

        $post_id = (int) $q->posts[0];

        // 5️⃣ Verificar asignación
        $usuario_vinculado = function_exists('get_field')
            ? (int) get_field('usuario_vinculado', $post_id)
            : (int) get_post_meta($post_id, 'usuario_vinculado', true);

        if ($usuario_vinculado !== (int) get_current_user_id()) {
            wp_die('No tienes permiso para ver este resultado', 'Acceso denegado', ['response' => 403]);
        }

        // 6️⃣ Contexto correcto para Elementor / ACF
        $GLOBALS['lab_test_detail_id'] = $post_id;
    }

    public static function acf_force_post_id($post_id)
    {
        if (isset($GLOBALS['lab_test_detail_id'])) {
            return (int) $GLOBALS['lab_test_detail_id'];
        }
        return $post_id;
    }

    /* =======================
       ELEMENTOR: query listado
       ======================= */

    public static function elementor_query_mis_resultados($query): void
    {
        if (!is_user_logged_in()) {
            $query->set('post__in', [0]);
            return;
        }

        $query->set('post_type', self::CPT);
        $query->set('posts_per_page', -1);
        $query->set('meta_query', [
            [
                'key' => 'usuario_vinculado',
                'value' => get_current_user_id(),
                'compare' => '='
            ]
        ]);
    }

    /* =======================
       JSON PROCESSING
       ======================= */

    public static function process_incoming_json(bool $manual = false): array 
    {
        $dir = self::json_dir(); // /lab-uploads
        $pdf_dest = self::pdf_dest_dir(); // /resultadospdf
        
        $processed = self::processed_dir();
        $error = self::error_dir();

        if (!is_dir($processed)) wp_mkdir_p($processed);
        if (!is_dir($error)) wp_mkdir_p($error);
        if (!is_dir($pdf_dest)) wp_mkdir_p($pdf_dest);

        $files = glob($dir . '/*.{json,csv}', GLOB_BRACE);
        
        $result = [
            'dir' => $dir,
            'found' => is_array($files) ? count($files) : 0,
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'files' => []
        ];

        if (!$files) {
            update_option(self::OPTION_LAST_RUN, current_time('mysql'));
            return $result;
        }

        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            try {
                if ($ext === 'json') {
                    $res = self::handle_json($file, $dir, $pdf_dest, $processed);
                } else {
                    $res = self::handle_csv_sensibilidad($file, $dir, $pdf_dest, $processed);
                }
                
                if ($res === 'updated') $result['updated']++;
                else $result['created']++;

            } catch (Exception $e) {
                rename($file, $error . '/' . basename($file));
                $result['errors']++;
            }
        }

        update_option(self::OPTION_LAST_RUN, current_time('mysql'));
        return $result;
    }

    /**
     * Lógica específica para el CSV S200+ (Sensibilidad)
     */
    private static function handle_csv_sensibilidad($file, $dir, $pdf_dest, $processed) {
        $base = basename($file);
        $filename_only = pathinfo($base, PATHINFO_FILENAME);

        try {
            
            // 1. Manejo de Codificación (UTF-8)
            $content = file_get_contents($file);
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
            
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $content);
            rewind($stream);
            
            // Variables de cabecera
            $paciente_1st = ''; $paciente_2nd = '';
            $procedencia = ''; $calderon = ''; $doctor = '';
            
            // Variables de resumen (Nuevas)
            $total_foods = ''; $total_elevated = ''; $total_borderline = ''; $total_normal = '';
            
            $is_table = false;
            $mediciones = [];

            while (($row = fgetcsv($stream, 1000, ",")) !== FALSE) {
                if (empty($row) || !isset($row[0])) continue;

                $row = array_map(function($item) {
                    return trim(str_replace("\xEF\xBB\xBF", '', $item));
                }, $row);

                $key = $row[0];
                $val = isset($row[1]) ? $row[1] : '';

                // --- 2. EXTRACCIÓN DE CABECERA Y RESÚMENES ---
                if (!$is_table) {
                    // Datos del paciente/doctor
                    if (stripos($key, '1st Name') !== false) $paciente_1st = $val;
                    if (stripos($key, '2nd Name') !== false) $paciente_2nd = $val;
                    if (stripos($key, 'Kit Lot') !== false) $procedencia = $val;
                    if (stripos($key, 'Slide Lot') !== false) $calderon = $val;
                    if (stripos($key, 'Doctor') !== false) $doctor = $val;

                    // Extraemos el Patient Number para la búsqueda del PDF
                    if (stripos($key, 'Patient Number:') !== false) $patient_number = $val;

                    // Datos de resumen (Los nuevos campos que me has pedido)
                    if (stripos($key, 'Total Foods:') !== false) $total_foods = $val;
                    if (stripos($key, 'Total Elevated:') !== false) $total_elevated = $val;
                    if (stripos($key, 'Total Borderline:') !== false) $total_borderline = $val;
                    if (stripos($key, 'Total Normal:') !== false) $total_normal = $val;
                }

                // --- 3. DETECCIÓN DE INICIO DE TABLA ---
                if (stripos($key, 'FOOD (English)') !== false) {
                    $is_table = true;
                    continue;
                }

                // --- 4. CAPTURA DE RESULTADOS ---
                if ($is_table && !empty($row[0])) {
                    $mediciones[] = [
                        'parametro'        => $row[1] ?: $row[0], 
                        'valor'            => $row[4] ?? '',      
                        'rango_referencia' => $row[5] ?? ''       
                    ];
                }
            }
            fclose($stream);

            $paciente_nombre = trim($paciente_1st . ' ' . $paciente_2nd);
            $hash = hash('sha256', $content);
            
            $existing = self::find_existing_post($procedencia, $calderon, $hash);
            $post_id = $existing ?: 0;

            $tipo = 'Sensibilidad alimentaria';
            $titulo = sprintf('Test de %s - %s', $tipo, $procedencia ?: $base);

            $post_data = ['post_type' => self::CPT, 'post_title' => $titulo, 'post_status' => 'publish'];
            if ($post_id) $post_data['ID'] = $post_id;

            $post_id = wp_insert_post($post_data);

            // Guardar todos los campos ACF
            update_field('num_peticion_procedencia', $procedencia, $post_id);
            update_field('num_peticion_calderon', $calderon, $post_id);
            update_field('paciente_nombre', $paciente_nombre, $post_id);
            update_field('doctor_nombre', $doctor, $post_id);

            // Información debug
            update_field('json_origen_path', $file, $post_id);
            update_field('json_origen_hash', $hash, $post_id);
            update_field('json_origen_raw', $content, $post_id);
            
            // Guardar los nuevos totales
            update_field('total_alimentos', $total_foods, $post_id);
            update_field('total_elevado', $total_elevated, $post_id);
            update_field('total_limite', $total_borderline, $post_id);
            update_field('total_normal', $total_normal, $post_id);

            update_field('tipo_test_ui', 'sensibilidad', $post_id);
            update_field('resultados_sensibilidad', $mediciones, $post_id);

            self::assign_taxonomy($post_id, $tipo);

            // Metadatos internos
            update_post_meta($post_id, '_lab_num_peticion_procedencia', $procedencia);
            update_post_meta($post_id, '_lab_num_peticion_calderon', $calderon);
            update_post_meta($post_id, '_lab_json_hash', $hash);

            // Vincular PDF si existe
            if (!empty($patient_number)) {
                // Buscamos cualquier archivo PDF en la carpeta que contenga el número
                $all_pdfs = glob($dir . '/*.pdf');
                foreach ($all_pdfs as $pdf_file) {
                    if (strpos(basename($pdf_file), $patient_number) !== false) {
                        $pdf_name = basename($pdf_file);
                        if (@rename($pdf_file, $pdf_dest . '/' . $pdf_name)) {
                            update_field('ruta_pdf_resultados', home_url('/' . self::PDF_DIR_NAME . '/' . $pdf_name), $post_id);
                            break; // Salimos del bucle una vez encontrado el primero
                        }
                    }
                }
            }

            self::add_log([
                'file'      => $base,
                'status'    => $existing ? 'updated' : 'created',
                'post_id'   => $post_id,
                'post_link' => admin_url('post.php?post=' . $post_id . '&action=edit'),
                'tipo'      => $tipo,
                'message'   => sprintf('CSV procesado correctamente. PDF vinculado: %s', file_exists($pdf_source) ? 'Sí' : 'No')
            ]);

            rename($file, $processed . '/' . $base);
            return $existing ? 'updated' : 'created';
        } catch (Exception $e) {
                if (file_exists($file)) {
                    rename($file, $error . '/' . $base);
                }
                $result['errors']++;
                self::add_log([
                    'file' => $base,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
    }

    /**
     * Lógica específica para el JSON (SIBO)
     */
    private static function handle_json($file, $dir, $pdf_dest, $processed){
            $base = basename($file);
            $filename_only = pathinfo($base, PATHINFO_FILENAME);

            try {
                $raw = file_get_contents($file);
                if ($raw === false || trim($raw) === '') {
                    throw new Exception('Archivo vacío o no legible');
                }

                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    throw new Exception('JSON inválido');
                }

                $hash = hash('sha256', $raw);
                $procedencia = isset($data['num_peticion_procedencia']) ? (string)$data['num_peticion_procedencia'] : '';
                $calderon = isset($data['num_peticion_calderon']) ? (string)$data['num_peticion_calderon'] : '';

                $existing = self::find_existing_post($procedencia, $calderon, $hash);
                $isUpdate = $existing ? true : false;
                $post_id = $existing ?: 0;

                // Inferencia de tipo (Taxonomía)
                $tipo = self::infer_tipo_test($data);

                // NUEVO: Construcción del título: Test de {nombre taxonomia} - {$procedencia}
                $nuevo_titulo = sprintf('Test de %s - %s', $tipo, $procedencia ?: $base);

                $postarr = [
                    'post_type' => self::CPT,
                    'post_title' => $nuevo_titulo,
                    'post_status' => 'publish',
                ];

                if ($isUpdate) {
                    $postarr['ID'] = $post_id;
                    wp_update_post($postarr);
                } else {
                    $post_id = wp_insert_post($postarr);
                    if (is_wp_error($post_id) || !$post_id) {
                        throw new Exception('No se pudo crear el post');
                    }
                }

                // Asignación de taxonomía y campos
                self::assign_taxonomy($post_id, $tipo);
                self::save_fields($post_id, $data, $file, $hash, $raw);

                // Actualización de tipo_test_ui
                if (function_exists('update_field')) {
                    $ui_map = [
                        'SIBO' => 'sibo',
                        'Microbiota intestinal' => 'microbiota',
                        'Sensibilidad alimentaria' => 'sensibilidad'
                    ];
                    if (isset($ui_map[$tipo])) {
                        update_field('tipo_test_ui', $ui_map[$tipo], $post_id);
                    }
                }

                // Vinculación de PDF
                $pdf_source = $dir . '/' . $filename_only . '.pdf';
                if (file_exists($pdf_source)) {
                    $pdf_filename = $filename_only . '.pdf';
                    $pdf_dest_path = $pdf_dest . '/' . $pdf_filename;
                    
                    if (@rename($pdf_source, $pdf_dest_path)) {
                        $pdf_url = home_url('/' . self::PDF_DIR_NAME . '/' . $pdf_filename);
                        update_field('ruta_pdf_resultados', $pdf_url, $post_id);
                    }
                }

                // Metas internos
                update_post_meta($post_id, '_lab_num_peticion_procedencia', $procedencia);
                update_post_meta($post_id, '_lab_num_peticion_calderon', $calderon);
                update_post_meta($post_id, '_lab_json_hash', $hash);

                self::add_log([
                    'file'      => basename($file),
                    'status'    => $existing ? 'updated' : 'created',
                    'post_id'   => $post_id,
                    'post_link' => admin_url('post.php?post=' . $post_id . '&action=edit'),
                    'tipo'      => $tipo, // La variable que infieres
                    'message'   => 'JSON procesado correctamente'
                ]);

                // Mover archivo JSON a la nueva ruta /lab-uploads/processed/
                rename($file, $processed . '/' . $base);

                if ($isUpdate) $result['updated']++; else $result['created']++;

            } catch (Exception $e) {
                if (file_exists($file)) {
                    rename($file, $error . '/' . $base);
                }
                $result['errors']++;
                self::add_log([
                    'file' => $base,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
    }

    private static function find_existing_post($procedencia, $calderon, $hash) {
    // 1) Por procedencia + calderon (si vienen)
    if (!empty($procedencia) || !empty($calderon)) {
      $meta_query = ['relation' => 'AND'];
      if (!empty($procedencia)) {
        $meta_query[] = [
          'key' => '_lab_num_peticion_procedencia',
          'value' => $procedencia,
          'compare' => '='
        ];
      }
      if (!empty($calderon)) {
        $meta_query[] = [
          'key' => '_lab_num_peticion_calderon',
          'value' => $calderon,
          'compare' => '='
        ];
      }

      $q = new WP_Query([
        'post_type' => self::CPT,
        'post_status' => 'any',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => $meta_query
      ]);

      if (!empty($q->posts)) return (int)$q->posts[0];
    }

    // 2) Por hash (fallback)
    if (!empty($hash)) {
      $q2 = new WP_Query([
        'post_type' => self::CPT,
        'post_status' => 'any',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => [
          [
            'key' => '_lab_json_hash',
            'value' => $hash,
            'compare' => '='
          ]
        ]
      ]);
      if (!empty($q2->posts)) return (int)$q2->posts[0];
    }

    return 0;
  }

    private static function infer_tipo_test(array $data): string
    {
        // Heurística básica: si viene "valores" (H2/CH4/CO2) lo tratamos como SIBO
        if (isset($data['valores']) && is_array($data['valores'])) {
            return 'SIBO';
        }
        // Ajusta aquí cuando tengas estructura real de microbiota/sensibilidad
        return 'Microbiota intestinal';
    }

    private static function assign_taxonomy(int $post_id, string $label): void
    {
        wp_set_object_terms($post_id, $label, self::TAX, false);
    }

    private static function save_fields($post_id, $data, $filePath, $hash, $raw): void
    {
        $fields = [
        'num_peticion_procedencia' => $data['num_peticion_procedencia'] ?? '',
        'num_peticion_calderon' => $data['num_peticion_calderon'] ?? '',
        'paciente_nombre' => $data['paciente_nombre'] ?? '',
        'doctor_nombre' => $data['doctor_nombre'] ?? '',
        'sustrato' => $data['sustrato'] ?? '',
        'conclusion' => isset($data['conclusion']) ? (int)$data['conclusion'] : null,
        'orientacion_diagnostico' => $data['orientacion_diagnostico'] ?? '',
        'comentarios' => $data['comentarios'] ?? '',
        'json_origen_path' => $filePath,
        'json_origen_hash' => $hash,
        'json_origen_raw' => $raw,
        ];

        $tipo = self::infer_tipo_test($data);
        if ($tipo === 'Microbiota intestinal') {
            self::import_microbiota_intestinal($post_id, $data);
            return; // 👈 CLAVE: evita que se ejecute lógica SIBO
        }

        // Medidas -> repeater
        $rows = [];
        if (isset($data['valores']) && is_array($data['valores'])) {
        for ($i = 1; $i <= 50; $i++) {
            $h2k = "h2_$i";
            $ch4k = "ch4_$i";
            $co2k = "co2_$i";

            $hasAny = array_key_exists($h2k, $data['valores']) || array_key_exists($ch4k, $data['valores']) || array_key_exists($co2k, $data['valores']);
            if (!$hasAny) {
            // si ya hemos empezado a llenar y aquí ya no hay nada, cortamos
            if ($i > 1) break;
            continue;
            }

            $rows[] = [
            'tiempo' => $i,
            'h2'  => isset($data['valores'][$h2k]) ? (int)$data['valores'][$h2k] : null,
            'ch4' => isset($data['valores'][$ch4k]) ? (int)$data['valores'][$ch4k] : null,
            'co2' => isset($data['valores'][$co2k]) ? (int)$data['valores'][$co2k] : null,
            ];
        }
        }

        // Si ACF existe, guardamos con update_field; si no, fallback
        if (function_exists('update_field')) {
        foreach ($fields as $k => $v) {
            update_field($k, $v, $post_id);
        }
        update_field('mediciones', $rows, $post_id);
        } else {
        foreach ($fields as $k => $v) {
            update_post_meta($post_id, $k, $v);
        }
        update_post_meta($post_id, 'mediciones', $rows);
        }
    }

    private static function add_log(array $entry): void
    {
        $log = get_option(self::OPTION_LOG, []);
        $entry['timestamp'] = current_time('mysql');

        array_unshift($log, $entry);
        update_option(self::OPTION_LOG, array_slice($log, 0, 50), false);
    }

    /* =======================
       SHORTCODES
       ======================= */

    public static function shortcode_activar_test(): string
    {
        if (!is_user_logged_in()) {
            return '<p>Debes iniciar sesión.</p>';
        }

        $output = '';

        if (
            isset($_POST['lab_test_code'], $_POST['lab_test_nonce']) &&
            wp_verify_nonce($_POST['lab_test_nonce'], 'activar_test_action')
        ) {
            $code = sanitize_text_field($_POST['lab_test_code']);

            $q = new WP_Query([
                'post_type' => self::CPT,
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => 'num_activa_test',
                        'value' => $code,
                        'compare' => '='
                    ]
                ]
            ]);

            if (!empty($q->posts)) {
                $post_id = (int) $q->posts[0];

                $usuario_vinculado = function_exists('get_field')
                    ? (int) get_field('usuario_vinculado', $post_id)
                    : (int) get_post_meta($post_id, 'usuario_vinculado', true);

                if ($usuario_vinculado) {
                    $output .= '<p style="color:red">Código no válido.</p>';
                    $output .= '<form method="post">
                    <label for="lab_test_code">Introduce tu ID de activación</label>
                    <input type="text" name="lab_test_code" id="lab_test_code" required>
                    ' . wp_nonce_field('activar_test_action', 'lab_test_nonce', true, false) . '
                    <button type="submit">Activar</button></form>';
                } else {
                    if (function_exists('update_field')) {
                        update_field('usuario_vinculado', get_current_user_id(), $post_id);
                    } else {
                        update_post_meta($post_id, 'usuario_vinculado', get_current_user_id());
                    }
                    $output .= '<script>
                    document.addEventListener("DOMContentLoaded", function () {
                    setTimeout(function(){
                    const input = document.querySelector(\'.wpcf7-form-control-wrap[data-name="idkit"] > input\');
                    if (input) {
                        input.value = ' . json_encode($code) . ';
                        input.setAttribute(\'value\', ' . json_encode($code) . ');
                    }
                        }, 2000);
                    });
                    </script><style>
                        .wpcf7{display: block !important;;}
                        .wpcf7 .kit{display:none!important}
                        .wpcf7-form {display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;}
                    </style>';
                }
            } else {
                $output .= '<p style="color:red">Código no válido.</p>';
                $output .= '<form method="post">
                <label for="lab_test_code">Introduce tu ID de activación</label>
                <input type="text" name="lab_test_code" id="lab_test_code" required>
                ' . wp_nonce_field('activar_test_action', 'lab_test_nonce', true, false) . '
                <button type="submit">Activar</button></form>';
            }
        }
        else {
            $output .= '<form method="post">
            <label for="lab_test_code">Introduce tu ID de activación</label>
            <input type="text" name="lab_test_code" id="lab_test_code" required>
            ' . wp_nonce_field('activar_test_action', 'lab_test_nonce', true, false) . '
            <button type="submit">Activar</button></form>';
        }

        return $output;
    }

    public static function shortcode_if_tipo_test($atts, $content = null): string
    {
        if (!isset($GLOBALS['lab_test_detail_id'])) {
            return '';
        }

        $atts = shortcode_atts(['is' => ''], $atts);

        $post_id = (int) $GLOBALS['lab_test_detail_id'];
        $valor = function_exists('get_field') ? (string) get_field('tipo_test_ui', $post_id) : (string) get_post_meta($post_id, 'tipo_test_ui', true);

        if ($valor === (string) $atts['is']) {
            return do_shortcode((string)$content);
        }

        return '';
    }

    public static function shortcode_lab_test_detalle(): string
    {
        if (!isset($GLOBALS['lab_test_detail_id'])) {
            return '';
        }

        $post_id = (int) $GLOBALS['lab_test_detail_id'];

        ob_start(); ?>
        <h2><?php echo esc_html(get_the_title($post_id)); ?></h2>

        <p><strong>Sustrato:</strong> <?php echo esc_html(function_exists('get_field') ? get_field('sustrato', $post_id) : get_post_meta($post_id, 'sustrato', true)); ?></p>
        <p><strong>Conclusión:</strong> <?php echo esc_html(function_exists('get_field') ? get_field('orientacion_diagnostico', $post_id) : get_post_meta($post_id, 'orientacion_diagnostico', true)); ?></p>
        <?php
        return (string) ob_get_clean();
    }

    public static function shortcode_lab_test_grafica(): string
    {
        if (!isset($GLOBALS['lab_test_detail_id'])) {
            return '';
        }

        $post_id = (int) $GLOBALS['lab_test_detail_id'];

        if (!function_exists('have_rows') || !have_rows('mediciones', $post_id)) {
            return '<p>No hay datos para mostrar.</p>';
        }

        $labels = [];
        $h2 = [];
        $ch4 = [];

        while (have_rows('mediciones', $post_id)) {
            the_row();
            $labels[] = (int) get_sub_field('tiempo');
            $h2[] = (int) get_sub_field('h2');
            $ch4[] = (int) get_sub_field('ch4');
        }

        $chart_id = 'lab_chart_' . wp_generate_uuid4();

        // Construir puntos {x,y} convirtiendo "punto" -> minutos
        $h2_points = [];
        $ch4_points = [];

        foreach ($labels as $i => $punto) {
            $minutos = max(0, ($punto - 1) * 25);

            $h2_points[] = ['x' => $minutos, 'y' => $h2[$i] ?? 0];
            $ch4_points[] = ['x' => $minutos, 'y' => $ch4[$i] ?? 0];
        }

        ob_start(); ?>
        <div style="max-width: 800px; margin: 0 auto;">
            <canvas id="<?php echo esc_attr($chart_id); ?>"></canvas>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const el = document.getElementById('<?php echo esc_js($chart_id); ?>');
            if (!el || typeof Chart === 'undefined') return;

            const ctx = el.getContext('2d');

            new Chart(ctx, {
                type: 'line',
                data: {
                    datasets: [
                        {
                            label: 'H₂ (ppm)',
                            data: <?php echo wp_json_encode($h2_points); ?>,
                            borderColor: '#1e90ff',
                            backgroundColor: 'rgba(30,144,255,0.15)',
                            tension: 0.4,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        },
                        {
                            label: 'CH₄ (ppm)',
                            data: <?php echo wp_json_encode($ch4_points); ?>,
                            borderColor: '#2ecc71',
                            backgroundColor: 'rgba(46,204,113,0.15)',
                            tension: 0.4,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'top' }
                    },
                    scales: {
                        x: {
                            type: 'linear',
                            min: 0,
                            max: 175,
                            ticks: { stepSize: 25 },
                            title: { display: true, text: 'Tiempo (min)' },
                            grid: { display: false }
                        },
                        y: {
                            min: 0,
                            max: 60,
                            ticks: { stepSize: 15 },
                            title: { display: true, text: 'Concentración (ppm)' }
                        }
                    }
                }
            });
        });
        </script>
        <?php
        return (string) ob_get_clean();
    }

    public static function shortcode_lab_test_valores($atts, $content = null): string
    {

        if (!isset($GLOBALS['lab_test_detail_id'])) {
            return '';
        }

        $atts = shortcode_atts([
            'parametro' => 'h2', // h2 | ch4 | co2
        ], $atts);

        $post_id = (int) $GLOBALS['lab_test_detail_id'];

        if (!function_exists('have_rows') || !have_rows('mediciones', $post_id)) {
            return '<p>No hay datos disponibles.</p>';
        }

        // Map de colores (puedes moverlo a CSS)
        $colors = [
            'h2'  => 'green',
            'ch4' => 'blue',
            'co2' => 'gray',
        ];

        $param = strtolower($atts['parametro']);
        if (!in_array($param, ['h2', 'ch4', 'co2'], true)) {
            return '';
        }

        ob_start();
        ?>
        <div class="lab-valores lab-valores-<?php echo esc_attr($param); ?>">

            <!-- Valores -->
            <div class="lab-valores-puntos">
                <?php
                while (have_rows('mediciones', $post_id)) {
                    the_row();

                    $punto = (int) get_sub_field('tiempo'); // 1..8
                    $valor = (int) get_sub_field($param);

                    echo '<div class="lab-punto lab-punto-' . esc_attr($colors[$param]) . '">';
                    echo esc_html($valor);
                    echo '</div>';
                }
                ?>
            </div>

        </div>
        <?php

        return ob_get_clean();
    }

    private static function import_microbiota_intestinal(int $post_id, array $json): void
    {
        $bloques = [];
        $indice_resiliencia = null;
        $fodmap = null;
        $parametros = [];

        foreach ($json as $bloque) {

            // Bloques funcionales con items
            if (isset($bloque['items']) && is_array($bloque['items'])) {

                $row = [
                    'nombre' => $bloque['nombre'] ?? '',
                    'descripcion' => $bloque['descripcion'] ?? '',
                    'items' => []
                ];

                foreach ($bloque['items'] as $item) {
                    $row['items'][] = [
                        'nombre' => $item['nombre'] ?? '',
                        'resultado' => $item['resultado'] ?? '',
                        'unidad' => $item['unidad'] ?? '',
                        'interpretacion' => $item['interpretacion'] ?? '',
                        'valores_ref' => $item['valores_ref'] ?? '',
                        'metodo' => $item['metodo'] ?? ''
                    ];
                }

                $bloques[] = $row;
            }

            // Índice resiliencia
            if (($bloque['nombre'] ?? '') === 'Indice Resiliencia') {
                $indice_resiliencia = $bloque['valor'] ?? null;
            }

            // FODMAP
            if (($bloque['nombre'] ?? '') === 'FODMAP') {
                $fodmap = $bloque['valor'] ?? null;
            }

            // Parámetros clínicos
            if (($bloque['nombre'] ?? '') === 'Parámetros Clínicos') {
                $parametros = $bloque['valores'] ?? [];
            }
        }

        // Guardado ACF
        update_field('microbiota_bloques', $bloques, $post_id);
        update_field('indice_resiliencia', $indice_resiliencia, $post_id);
        update_field('fodmap', $fodmap, $post_id);

        if (!empty($parametros)) {
            update_field('parametros_clinicos', [
                'consistencia_heces' => $parametros['consistencia_heces']['valor'] ?? '',
                'ph_valor' => $parametros['ph']['valor'] ?? '',
                'ph_interpretacion' => $parametros['ph']['interpretacion'] ?? '',
                'ph_valores_ref' => $parametros['ph']['valores_ref'] ?? '',
                'ph_metodo' => $parametros['ph']['metodo'] ?? '',
            ], $post_id);
        }
    }

}

Lab_JSON_Processor::init();
