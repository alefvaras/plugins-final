<?php
/**
 * Plugin Name: SII Boleta Electrónica Chile
 * Plugin URI: https://akibara.cl
 * Description: Plugin para emitir boletas electrónicas tipo 39 al SII de Chile. Soporta certificación y producción.
 * Version: 1.0.0
 * Author: Akibara Store
 * Author URI: https://akibara.cl
 * License: GPL v2 or later
 * Text Domain: sii-boleta
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes
define('SII_BOLETA_VERSION', '1.0.0');
define('SII_BOLETA_PATH', plugin_dir_path(__FILE__));
define('SII_BOLETA_URL', plugin_dir_url(__FILE__));

// Incluir archivos del plugin
require_once SII_BOLETA_PATH . 'includes/class-sii-database.php';
require_once SII_BOLETA_PATH . 'includes/class-sii-api.php';
require_once SII_BOLETA_PATH . 'includes/class-sii-xml-generator.php';
require_once SII_BOLETA_PATH . 'includes/class-sii-admin.php';
require_once SII_BOLETA_PATH . 'includes/class-sii-woocommerce.php';
require_once SII_BOLETA_PATH . 'includes/class-sii-email.php';

/**
 * Clase principal del plugin
 */
class SII_Boleta_Electronica {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hooks de activación/desactivación
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Inicializar componentes
        add_action('plugins_loaded', [$this, 'init']);

        // Declarar compatibilidad con HPOS de WooCommerce
        add_action('before_woocommerce_init', function() {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });
    }

    public function activate() {
        // Crear tablas en base de datos
        SII_Database::create_tables();

        // Crear directorio para certificados
        $upload_dir = wp_upload_dir();
        $sii_dir = $upload_dir['basedir'] . '/sii-certificates';
        if (!file_exists($sii_dir)) {
            wp_mkdir_p($sii_dir);
            file_put_contents($sii_dir . '/.htaccess', 'deny from all');
        }

        // Opciones por defecto
        $defaults = [
            'sii_ambiente' => 'cert',
            'sii_rut_emisor' => '',
            'sii_razon_social' => '',
            'sii_giro' => '',
            'sii_direccion' => '',
            'sii_comuna' => '',
            'sii_ciudad' => '',
            'sii_acteco' => '',
            'sii_fch_resol' => '',
            'sii_nro_resol' => '0',
            'sii_auto_generate' => 'yes',
            'sii_send_email' => 'yes',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function init() {
        // Verificar que WooCommerce esté activo
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p><strong>SII Boleta Electrónica</strong> requiere WooCommerce para funcionar.</p></div>';
            });
            return;
        }

        // Inicializar componentes
        SII_Admin::get_instance();
        SII_WooCommerce::get_instance();
    }
}

// Inicializar el plugin
SII_Boleta_Electronica::get_instance();
