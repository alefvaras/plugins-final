<?php
/**
 * Clase para el panel de administración
 */

if (!defined('ABSPATH')) {
    exit;
}

class SII_Admin {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_sii_upload_certificate', [$this, 'ajax_upload_certificate']);
        add_action('wp_ajax_sii_upload_caf', [$this, 'ajax_upload_caf']);
        add_action('wp_ajax_sii_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_sii_generate_manual', [$this, 'ajax_generate_manual']);
        add_action('wp_ajax_sii_run_test_set', [$this, 'ajax_run_test_set']);
    }

    public function add_menu() {
        add_menu_page(
            'SII Boleta Electrónica',
            'Boleta Electrónica',
            'manage_woocommerce',
            'sii-boleta',
            [$this, 'render_settings_page'],
            'dashicons-media-spreadsheet',
            56
        );

        add_submenu_page(
            'sii-boleta',
            'Configuración',
            'Configuración',
            'manage_woocommerce',
            'sii-boleta',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'sii-boleta',
            'Boletas Emitidas',
            'Boletas Emitidas',
            'manage_woocommerce',
            'sii-boletas-list',
            [$this, 'render_boletas_list']
        );

        add_submenu_page(
            'sii-boleta',
            'Set de Pruebas',
            'Set de Pruebas',
            'manage_woocommerce',
            'sii-test-set',
            [$this, 'render_test_set_page']
        );
    }

    public function register_settings() {
        // Sección General
        add_settings_section('sii_general', 'Configuración General', null, 'sii-boleta');

        register_setting('sii-boleta', 'sii_ambiente');
        add_settings_field('sii_ambiente', 'Ambiente', function() {
            $value = get_option('sii_ambiente', 'cert');
            echo '<select name="sii_ambiente" id="sii_ambiente">';
            echo '<option value="cert"' . selected($value, 'cert', false) . '>Certificación</option>';
            echo '<option value="prod"' . selected($value, 'prod', false) . '>Producción</option>';
            echo '</select>';
        }, 'sii-boleta', 'sii_general');

        // Datos Empresa
        $empresa_fields = [
            'sii_rut_emisor' => 'RUT Emisor (Empresa)',
            'sii_razon_social' => 'Razón Social',
            'sii_giro' => 'Giro',
            'sii_direccion' => 'Dirección',
            'sii_comuna' => 'Comuna',
            'sii_ciudad' => 'Ciudad',
            'sii_acteco' => 'Código Actividad Económica',
            'sii_fch_resol' => 'Fecha Resolución (YYYY-MM-DD)',
            'sii_nro_resol' => 'Número Resolución',
            'sii_rut_envia' => 'RUT Quien Envía (Certificado)',
        ];

        add_settings_section('sii_empresa', 'Datos de la Empresa', null, 'sii-boleta');

        foreach ($empresa_fields as $key => $label) {
            register_setting('sii-boleta', $key);
            add_settings_field($key, $label, function() use ($key) {
                $value = get_option($key, '');
                echo '<input type="text" name="' . $key . '" value="' . esc_attr($value) . '" class="regular-text">';
            }, 'sii-boleta', 'sii_empresa');
        }

        // Opciones
        add_settings_section('sii_options', 'Opciones', null, 'sii-boleta');

        register_setting('sii-boleta', 'sii_auto_generate');
        add_settings_field('sii_auto_generate', 'Generar automáticamente', function() {
            $value = get_option('sii_auto_generate', 'yes');
            echo '<label><input type="checkbox" name="sii_auto_generate" value="yes"' . checked($value, 'yes', false) . '> Generar boleta al completar pedido</label>';
        }, 'sii-boleta', 'sii_options');

        register_setting('sii-boleta', 'sii_send_email');
        add_settings_field('sii_send_email', 'Enviar por email', function() {
            $value = get_option('sii_send_email', 'yes');
            echo '<label><input type="checkbox" name="sii_send_email" value="yes"' . checked($value, 'yes', false) . '> Enviar boleta por email al cliente</label>';
        }, 'sii-boleta', 'sii_options');

        // Certificado
        register_setting('sii-boleta', 'sii_cert_password');
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>SII Boleta Electrónica - Configuración</h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('sii-boleta');
                do_settings_sections('sii-boleta');
                submit_button();
                ?>
            </form>

            <hr>

            <h2>Certificado Digital</h2>
            <p>Suba su certificado .pfx para firmar los documentos.</p>
            <input type="file" id="sii_certificate_file" accept=".pfx,.p12">
            <input type="password" id="sii_cert_password_input" placeholder="Contraseña del certificado">
            <button type="button" class="button" onclick="uploadCertificate()">Subir Certificado</button>
            <div id="cert_status">
                <?php
                $cert_path = $this->get_certificate_path();
                if (file_exists($cert_path)) {
                    echo '<span style="color:green;">✓ Certificado cargado</span>';
                } else {
                    echo '<span style="color:red;">✗ Sin certificado</span>';
                }
                ?>
            </div>

            <hr>

            <h2>CAF (Código Autorización Folios)</h2>
            <p>Suba su archivo CAF tipo 39 para boletas electrónicas.</p>
            <input type="file" id="sii_caf_file" accept=".xml">
            <button type="button" class="button" onclick="uploadCAF()">Subir CAF</button>
            <div id="caf_status">
                <?php
                $caf = SII_Database::get_active_caf(get_option('sii_ambiente', 'cert'));
                if ($caf) {
                    echo '<span style="color:green;">✓ CAF activo: Folios ' . $caf->folio_actual . ' - ' . $caf->folio_hasta . '</span>';
                } else {
                    echo '<span style="color:red;">✗ Sin CAF</span>';
                }
                ?>
            </div>

            <hr>

            <h2>Probar Conexión</h2>
            <button type="button" class="button button-primary" onclick="testConnection()">Probar Conexión con SII</button>
            <div id="test_result"></div>
        </div>

        <script>
        function uploadCertificate() {
            var file = document.getElementById('sii_certificate_file').files[0];
            var password = document.getElementById('sii_cert_password_input').value;

            if (!file) {
                alert('Seleccione un archivo');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'sii_upload_certificate');
            formData.append('certificate', file);
            formData.append('password', password);
            formData.append('nonce', '<?php echo wp_create_nonce('sii_upload'); ?>');

            fetch(ajaxurl, {method: 'POST', body: formData})
                .then(r => r.json())
                .then(data => {
                    document.getElementById('cert_status').innerHTML = data.success ?
                        '<span style="color:green;">✓ ' + data.data + '</span>' :
                        '<span style="color:red;">✗ ' + data.data + '</span>';
                });
        }

        function uploadCAF() {
            var file = document.getElementById('sii_caf_file').files[0];

            if (!file) {
                alert('Seleccione un archivo');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'sii_upload_caf');
            formData.append('caf', file);
            formData.append('nonce', '<?php echo wp_create_nonce('sii_upload'); ?>');

            fetch(ajaxurl, {method: 'POST', body: formData})
                .then(r => r.json())
                .then(data => {
                    document.getElementById('caf_status').innerHTML = data.success ?
                        '<span style="color:green;">✓ ' + data.data + '</span>' :
                        '<span style="color:red;">✗ ' + data.data + '</span>';
                });
        }

        function testConnection() {
            document.getElementById('test_result').innerHTML = 'Probando...';

            fetch(ajaxurl + '?action=sii_test_connection&nonce=<?php echo wp_create_nonce('sii_test'); ?>')
                .then(r => r.json())
                .then(data => {
                    document.getElementById('test_result').innerHTML = data.success ?
                        '<span style="color:green;">✓ ' + data.data + '</span>' :
                        '<span style="color:red;">✗ ' + data.data + '</span>';
                });
        }
        </script>
        <?php
    }

    public function render_boletas_list() {
        global $wpdb;
        $table = SII_Database::get_table_name(SII_Database::TABLE_BOLETAS);
        $boletas = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 100");
        ?>
        <div class="wrap">
            <h1>Boletas Emitidas</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Folio</th>
                        <th>Pedido</th>
                        <th>RUT Receptor</th>
                        <th>Monto Total</th>
                        <th>Fecha</th>
                        <th>Track ID</th>
                        <th>Estado</th>
                        <th>Ambiente</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($boletas as $boleta): ?>
                    <tr>
                        <td><?php echo $boleta->id; ?></td>
                        <td><?php echo $boleta->folio; ?></td>
                        <td><a href="<?php echo admin_url('post.php?post=' . $boleta->order_id . '&action=edit'); ?>">#<?php echo $boleta->order_id; ?></a></td>
                        <td><?php echo $boleta->rut_receptor; ?></td>
                        <td>$<?php echo number_format($boleta->monto_total, 0, ',', '.'); ?></td>
                        <td><?php echo $boleta->fecha_emision; ?></td>
                        <td><?php echo $boleta->track_id ?: '-'; ?></td>
                        <td><?php echo $boleta->estado; ?></td>
                        <td><?php echo $boleta->ambiente; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_test_set_page() {
        ?>
        <div class="wrap">
            <h1>Set de Pruebas SII</h1>
            <p>Ejecute el set de pruebas del SII para certificación de boletas electrónicas tipo 39.</p>

            <h2>Casos de Prueba</h2>
            <ol>
                <li><strong>CASO-1:</strong> Cambio de aceite ($19.900) + Alineación y balanceo ($9.900)</li>
                <li><strong>CASO-2:</strong> Papel de regalo x17 ($120)</li>
                <li><strong>CASO-3:</strong> Sandwich x2 ($1.500) + Bebida x2 ($550)</li>
                <li><strong>CASO-4:</strong> Item afecto x8 ($1.590) + Item exento x2 ($1.000)</li>
                <li><strong>CASO-5:</strong> Arroz x5 ($700) - con unidad de medida Kg</li>
            </ol>

            <button type="button" class="button button-primary button-hero" onclick="runTestSet()">Ejecutar Set de Pruebas</button>

            <div id="test_set_log" style="margin-top:20px; padding:15px; background:#f0f0f0; font-family:monospace; white-space:pre-wrap; max-height:500px; overflow-y:auto;"></div>
        </div>

        <script>
        function runTestSet() {
            var log = document.getElementById('test_set_log');
            log.innerHTML = 'Iniciando set de pruebas...\n';

            var eventSource = new EventSource(ajaxurl + '?action=sii_run_test_set&nonce=<?php echo wp_create_nonce('sii_test_set'); ?>');

            eventSource.onmessage = function(e) {
                log.innerHTML += e.data + '\n';
                log.scrollTop = log.scrollHeight;

                if (e.data.includes('COMPLETADO') || e.data.includes('ERROR FATAL')) {
                    eventSource.close();
                }
            };

            eventSource.onerror = function(e) {
                log.innerHTML += '\nConexión cerrada\n';
                eventSource.close();
            };
        }
        </script>
        <?php
    }

    private function get_certificate_path() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/sii-certificates/certificate.pfx';
    }

    public function ajax_upload_certificate() {
        check_ajax_referer('sii_upload', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Sin permisos');
        }

        if (empty($_FILES['certificate'])) {
            wp_send_json_error('No se recibió archivo');
        }

        $password = sanitize_text_field($_POST['password'] ?? '');
        $file = $_FILES['certificate'];

        // Validar archivo
        $content = file_get_contents($file['tmp_name']);
        $certs = [];

        if (!openssl_pkcs12_read($content, $certs, $password)) {
            wp_send_json_error('Error al leer certificado: ' . openssl_error_string());
        }

        // Guardar certificado
        $upload_dir = wp_upload_dir();
        $cert_path = $upload_dir['basedir'] . '/sii-certificates/certificate.pfx';

        if (!move_uploaded_file($file['tmp_name'], $cert_path)) {
            wp_send_json_error('Error al guardar certificado');
        }

        // Guardar contraseña
        update_option('sii_cert_password', $password);

        // Extraer información
        $info = openssl_x509_parse($certs['cert']);
        $cn = $info['subject']['CN'] ?? 'N/A';

        wp_send_json_success('Certificado cargado: ' . $cn);
    }

    public function ajax_upload_caf() {
        check_ajax_referer('sii_upload', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Sin permisos');
        }

        if (empty($_FILES['caf'])) {
            wp_send_json_error('No se recibió archivo');
        }

        $content = file_get_contents($_FILES['caf']['tmp_name']);
        $xml = @simplexml_load_string($content);

        if (!$xml || !isset($xml->CAF)) {
            wp_send_json_error('Archivo CAF inválido');
        }

        // Extraer datos
        $tipo_dte = (int) $xml->CAF->DA->TD;
        $folio_desde = (int) $xml->CAF->DA->RNG->D;
        $folio_hasta = (int) $xml->CAF->DA->RNG->H;
        $fecha_auth = (string) $xml->CAF->DA->FA;
        $rut_emisor = (string) $xml->CAF->DA->RE;
        $razon_social = (string) $xml->CAF->DA->RS;

        // Extraer clave privada
        $rsask = (string) $xml->RSASK;
        $rsask = preg_replace('/\s+/', '', $rsask);
        $rsask = str_replace(['-----BEGINRSAPRIVATEKEY-----', '-----ENDRSAPRIVATEKEY-----'], '', $rsask);

        // Guardar en base de datos
        $result = SII_Database::save_caf([
            'tipo_dte' => $tipo_dte,
            'folio_desde' => $folio_desde,
            'folio_hasta' => $folio_hasta,
            'folio_actual' => $folio_desde,
            'fecha_autorizacion' => $fecha_auth,
            'rut_emisor' => $rut_emisor,
            'razon_social' => $razon_social,
            'ambiente' => get_option('sii_ambiente', 'cert'),
            'xml_caf' => $content,
            'clave_privada' => $rsask,
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success("CAF cargado: Folios $folio_desde - $folio_hasta");
    }

    public function ajax_test_connection() {
        check_ajax_referer('sii_test', 'nonce');

        $api = new SII_API();

        // Cargar certificado
        $cert_path = $this->get_certificate_path();
        $password = get_option('sii_cert_password', '');

        $result = $api->load_certificate($cert_path, $password);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Autenticar
        $token = $api->authenticate();
        if (is_wp_error($token)) {
            wp_send_json_error($token->get_error_message());
        }

        wp_send_json_success('Conexión exitosa. Token obtenido: ' . substr($token, 0, 20) . '...');
    }

    public function ajax_run_test_set() {
        check_ajax_referer('sii_test_set', 'nonce');

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        $this->send_event('Iniciando set de pruebas SII...');

        // Cargar configuración
        $api = new SII_API();
        $cert_path = $this->get_certificate_path();
        $password = get_option('sii_cert_password', '');

        // Cargar certificado
        $result = $api->load_certificate($cert_path, $password);
        if (is_wp_error($result)) {
            $this->send_event('ERROR: ' . $result->get_error_message());
            exit;
        }
        $this->send_event('✓ Certificado cargado');

        // Obtener CAF
        $caf = SII_Database::get_active_caf(get_option('sii_ambiente', 'cert'));
        if (!$caf) {
            $this->send_event('ERROR: No hay CAF disponible');
            exit;
        }
        $this->send_event('✓ CAF: Folios ' . $caf->folio_actual . ' - ' . $caf->folio_hasta);

        // Loop para intentar hasta obtener trackID
        $max_attempts = 100;
        $attempt = 0;

        while ($attempt < $max_attempts) {
            $attempt++;
            $this->send_event("\n--- INTENTO #$attempt ---");

            // Autenticar
            $token = $api->authenticate();
            if (is_wp_error($token)) {
                $this->send_event('ERROR Auth: ' . $token->get_error_message());
                sleep(5);
                continue;
            }
            $this->send_event('✓ Token obtenido');

            // Obtener folio
            $folio_data = SII_Database::get_next_folio(get_option('sii_ambiente', 'cert'));
            if (is_wp_error($folio_data)) {
                $this->send_event('ERROR Folio: ' . $folio_data->get_error_message());
                break;
            }
            $folio = $folio_data['folio'];
            $this->send_event('✓ Folio: ' . $folio);

            // Generar boleta CASO-1
            $generator = new SII_XML_Generator($api);
            $items = [
                ['nombre' => 'Cambio de aceite', 'qty' => 1, 'precio' => 19900],
                ['nombre' => 'Alineacion y balanceo', 'qty' => 1, 'precio' => 9900],
            ];
            $receptor = [
                'rut' => '66666666-6',
                'razon_social' => 'CONSUMIDOR FINAL',
            ];
            $referencia = [
                'tipo' => 'SET',
                'razon' => 'CASO-1',
            ];

            $caf_data = [
                'xml_caf' => $caf->xml_caf,
                'clave_privada' => $caf->clave_privada,
            ];

            $boleta = $generator->generate_boleta($folio, $items, $receptor, $caf_data, $referencia);
            if (is_wp_error($boleta)) {
                $this->send_event('ERROR XML: ' . $boleta->get_error_message());
                sleep(5);
                continue;
            }
            $this->send_event('✓ XML generado');

            // Enviar al SII
            $rut_sender = get_option('sii_rut_envia', get_option('sii_rut_emisor'));
            $rut_company = get_option('sii_rut_emisor');

            $result = $api->send_boleta($boleta['xml'], $rut_sender, $rut_company);
            if (is_wp_error($result)) {
                $this->send_event('ERROR Envío: ' . $result->get_error_message());
                sleep(5);
                continue;
            }

            $this->send_event('Respuesta HTTP: ' . $result['code']);
            $this->send_event('Body: ' . substr($result['body'], 0, 300));

            if ($result['track_id']) {
                $this->send_event("\n★★★ TRACKID OBTENIDO: " . $result['track_id'] . " ★★★");

                // Guardar en base de datos
                SII_Database::save_boleta([
                    'order_id' => 0,
                    'folio' => $folio,
                    'tipo_dte' => 39,
                    'rut_receptor' => '66666666-6',
                    'razon_social_receptor' => 'CONSUMIDOR FINAL',
                    'monto_neto' => $boleta['monto_neto'],
                    'monto_iva' => $boleta['monto_iva'],
                    'monto_total' => $boleta['monto_total'],
                    'fecha_emision' => date('Y-m-d'),
                    'track_id' => $result['track_id'],
                    'estado' => 'enviado',
                    'ambiente' => get_option('sii_ambiente', 'cert'),
                    'xml_enviado' => $boleta['xml'],
                    'xml_respuesta' => $result['body'],
                    'fecha_envio' => current_time('mysql'),
                ]);

                // Consultar estado
                $this->send_event("\nConsultando estado...");
                sleep(10);

                for ($i = 0; $i < 30; $i++) {
                    $status = $api->check_status($result['track_id'], $rut_company);
                    if (is_wp_error($status)) {
                        if ($status->get_error_code() === 'not_authenticated') {
                            $this->send_event('Renovando token...');
                            $api->authenticate();
                            continue;
                        }
                        $this->send_event('ERROR Estado: ' . $status->get_error_message());
                    } else {
                        $this->send_event('Estado: ' . ($status['estado'] ?? 'desconocido'));

                        if (in_array($status['estado'], ['EPR', 'RLV', 'DOK', 'SOK', '00'])) {
                            $this->send_event("\n★★★ SET DE PRUEBAS COMPLETADO EXITOSAMENTE ★★★");
                            $this->send_event("TrackID: " . $result['track_id']);
                            $this->send_event("Folio: " . $folio);
                            exit;
                        }

                        if (in_array($status['estado'], ['RCT', 'RCH', 'DNK', 'RFR'])) {
                            $this->send_event("Boleta rechazada: " . $status['estado']);
                            break;
                        }
                    }
                    sleep(30);
                }

                $this->send_event("\n=== SET DE PRUEBAS COMPLETADO ===");
                $this->send_event("TrackID obtenido: " . $result['track_id']);
                exit;
            }

            sleep(10);
        }

        $this->send_event("\nERROR FATAL: No se pudo obtener trackID después de $max_attempts intentos");
        exit;
    }

    private function send_event($message) {
        echo "data: $message\n\n";
        ob_flush();
        flush();
    }
}
