<?php
/**
 * Integración con WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class SII_WooCommerce {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook para generar boleta al completar pedido
        add_action('woocommerce_order_status_completed', [$this, 'generate_boleta_on_complete'], 10, 1);

        // Agregar metabox en pedido
        add_action('add_meta_boxes', [$this, 'add_order_metabox']);

        // Agregar columna en lista de pedidos
        add_filter('manage_edit-shop_order_columns', [$this, 'add_order_column']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_order_column'], 10, 2);

        // AJAX para generación manual
        add_action('wp_ajax_sii_generate_boleta', [$this, 'ajax_generate_boleta']);

        // Cron para verificar estados
        add_action('sii_check_boleta_status', [$this, 'check_pending_boletas']);

        if (!wp_next_scheduled('sii_check_boleta_status')) {
            wp_schedule_event(time(), 'hourly', 'sii_check_boleta_status');
        }
    }

    /**
     * Generar boleta al completar pedido
     */
    public function generate_boleta_on_complete($order_id) {
        if (get_option('sii_auto_generate', 'yes') !== 'yes') {
            return;
        }

        $this->generate_boleta($order_id);
    }

    /**
     * Generar boleta para un pedido
     */
    public function generate_boleta($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('invalid_order', 'Pedido no válido');
        }

        // Verificar si ya existe boleta
        $existing = SII_Database::get_boleta_by_order($order_id);
        if ($existing && $existing->track_id) {
            return new WP_Error('already_exists', 'Ya existe boleta para este pedido');
        }

        // Cargar API
        $api = new SII_API();
        $upload_dir = wp_upload_dir();
        $cert_path = $upload_dir['basedir'] . '/sii-certificates/certificate.pfx';
        $password = get_option('sii_cert_password', '');

        $result = $api->load_certificate($cert_path, $password);
        if (is_wp_error($result)) {
            return $result;
        }

        // Autenticar
        $token = $api->authenticate();
        if (is_wp_error($token)) {
            return $token;
        }

        // Obtener folio
        $ambiente = get_option('sii_ambiente', 'cert');
        $folio_data = SII_Database::get_next_folio($ambiente);
        if (is_wp_error($folio_data)) {
            return $folio_data;
        }

        // Preparar items
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'nombre' => $item->get_name(),
                'qty' => $item->get_quantity(),
                'precio' => round($item->get_total() / $item->get_quantity()),
            ];
        }

        // Preparar receptor
        $rut_receptor = $order->get_meta('_billing_rut') ?: '66666666-6';
        $receptor = [
            'rut' => $rut_receptor,
            'razon_social' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        ];

        // Generar XML
        $generator = new SII_XML_Generator($api);
        $caf_data = [
            'xml_caf' => $folio_data['caf']->xml_caf,
            'clave_privada' => $folio_data['caf']->clave_privada,
        ];

        $boleta = $generator->generate_boleta($folio_data['folio'], $items, $receptor, $caf_data);
        if (is_wp_error($boleta)) {
            return $boleta;
        }

        // Enviar al SII
        $rut_sender = get_option('sii_rut_envia', get_option('sii_rut_emisor'));
        $rut_company = get_option('sii_rut_emisor');

        $result = $api->send_boleta($boleta['xml'], $rut_sender, $rut_company);
        if (is_wp_error($result)) {
            return $result;
        }

        // Guardar en base de datos
        $boleta_id = SII_Database::save_boleta([
            'order_id' => $order_id,
            'folio' => $folio_data['folio'],
            'tipo_dte' => 39,
            'rut_receptor' => $rut_receptor,
            'razon_social_receptor' => $receptor['razon_social'],
            'monto_neto' => $boleta['monto_neto'],
            'monto_iva' => $boleta['monto_iva'],
            'monto_total' => $boleta['monto_total'],
            'fecha_emision' => date('Y-m-d'),
            'track_id' => $result['track_id'],
            'estado' => $result['track_id'] ? 'enviado' : 'error',
            'ambiente' => $ambiente,
            'xml_enviado' => $boleta['xml'],
            'xml_respuesta' => $result['body'],
            'fecha_envio' => current_time('mysql'),
        ]);

        // Guardar en metadata del pedido
        $order->update_meta_data('_sii_boleta_id', $boleta_id);
        $order->update_meta_data('_sii_folio', $folio_data['folio']);
        $order->update_meta_data('_sii_track_id', $result['track_id']);
        $order->save();

        // Enviar email si está configurado
        if ($result['track_id'] && get_option('sii_send_email', 'yes') === 'yes') {
            SII_Email::send_boleta_email($order, $boleta['xml'], $folio_data['folio']);
        }

        return [
            'boleta_id' => $boleta_id,
            'folio' => $folio_data['folio'],
            'track_id' => $result['track_id'],
        ];
    }

    /**
     * Agregar metabox en página de pedido
     */
    public function add_order_metabox() {
        add_meta_box(
            'sii_boleta_metabox',
            'Boleta Electrónica SII',
            [$this, 'render_order_metabox'],
            'shop_order',
            'side',
            'high'
        );

        // HPOS compatible
        add_meta_box(
            'sii_boleta_metabox',
            'Boleta Electrónica SII',
            [$this, 'render_order_metabox'],
            'woocommerce_page_wc-orders',
            'side',
            'high'
        );
    }

    /**
     * Renderizar metabox
     */
    public function render_order_metabox($post_or_order) {
        $order_id = is_numeric($post_or_order) ? $post_or_order :
                    (is_a($post_or_order, 'WP_Post') ? $post_or_order->ID :
                    (is_a($post_or_order, 'WC_Order') ? $post_or_order->get_id() : 0));

        $boleta = SII_Database::get_boleta_by_order($order_id);

        if ($boleta): ?>
            <p><strong>Folio:</strong> <?php echo $boleta->folio; ?></p>
            <p><strong>Track ID:</strong> <?php echo $boleta->track_id ?: 'N/A'; ?></p>
            <p><strong>Estado:</strong> <?php echo $boleta->estado; ?></p>
            <p><strong>Monto:</strong> $<?php echo number_format($boleta->monto_total, 0, ',', '.'); ?></p>
            <p><strong>Fecha:</strong> <?php echo $boleta->fecha_emision; ?></p>
        <?php else: ?>
            <p>No hay boleta emitida para este pedido.</p>
            <button type="button" class="button" onclick="generateBoleta(<?php echo $order_id; ?>)">Generar Boleta</button>
            <script>
            function generateBoleta(orderId) {
                if (!confirm('¿Generar boleta para este pedido?')) return;

                fetch(ajaxurl + '?action=sii_generate_boleta&order_id=' + orderId + '&nonce=<?php echo wp_create_nonce('sii_generate'); ?>')
                    .then(r => r.json())
                    .then(data => {
                        alert(data.success ? 'Boleta generada: Folio ' + data.data.folio : 'Error: ' + data.data);
                        if (data.success) location.reload();
                    });
            }
            </script>
        <?php endif;
    }

    /**
     * Agregar columna en lista de pedidos
     */
    public function add_order_column($columns) {
        $new_columns = [];
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'order_status') {
                $new_columns['sii_boleta'] = 'Boleta SII';
            }
        }
        return $new_columns;
    }

    /**
     * Renderizar columna
     */
    public function render_order_column($column, $order_id) {
        if ($column !== 'sii_boleta') return;

        $boleta = SII_Database::get_boleta_by_order($order_id);

        if ($boleta) {
            $color = $boleta->track_id ? 'green' : 'orange';
            echo '<span style="color:' . $color . ';">F' . $boleta->folio . '</span>';
        } else {
            echo '<span style="color:gray;">-</span>';
        }
    }

    /**
     * AJAX para generar boleta
     */
    public function ajax_generate_boleta() {
        check_ajax_referer('sii_generate', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Sin permisos');
        }

        $order_id = intval($_GET['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error('ID de pedido inválido');
        }

        $result = $this->generate_boleta($order_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * Verificar estados de boletas pendientes
     */
    public function check_pending_boletas() {
        $boletas = SII_Database::get_pending_boletas(10);

        if (empty($boletas)) return;

        $api = new SII_API();
        $upload_dir = wp_upload_dir();
        $cert_path = $upload_dir['basedir'] . '/sii-certificates/certificate.pfx';
        $password = get_option('sii_cert_password', '');

        $result = $api->load_certificate($cert_path, $password);
        if (is_wp_error($result)) return;

        $token = $api->authenticate();
        if (is_wp_error($token)) return;

        $rut_emisor = get_option('sii_rut_emisor');

        foreach ($boletas as $boleta) {
            if (!$boleta->track_id) continue;

            $status = $api->check_status($boleta->track_id, $rut_emisor);

            if (is_wp_error($status)) {
                SII_Database::update_boleta($boleta->id, [
                    'intentos' => $boleta->intentos + 1,
                    'ultimo_error' => $status->get_error_message(),
                ]);
                continue;
            }

            $estado = $status['estado'] ?? '';

            // Actualizar estado
            $new_status = $boleta->estado;
            if (in_array($estado, ['EPR', 'RLV', 'DOK', 'SOK', '00'])) {
                $new_status = 'aceptado';
            } elseif (in_array($estado, ['RCT', 'RCH', 'DNK', 'RFR'])) {
                $new_status = 'rechazado';
            }

            SII_Database::update_boleta($boleta->id, [
                'estado' => $new_status,
                'fecha_aceptacion' => $new_status === 'aceptado' ? current_time('mysql') : null,
                'intentos' => $boleta->intentos + 1,
                'xml_respuesta' => $status['body'] ?? $boleta->xml_respuesta,
            ]);
        }
    }
}
