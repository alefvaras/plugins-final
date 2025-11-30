<?php
/**
 * Clase para gestionar la base de datos del plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class SII_Database {

    const TABLE_BOLETAS = 'sii_boletas';
    const TABLE_CAF = 'sii_caf';

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_boletas = $wpdb->prefix . self::TABLE_BOLETAS;
        $table_caf = $wpdb->prefix . self::TABLE_CAF;

        $sql_boletas = "CREATE TABLE IF NOT EXISTS $table_boletas (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            folio int(11) NOT NULL,
            tipo_dte int(11) NOT NULL DEFAULT 39,
            rut_receptor varchar(15) NOT NULL,
            razon_social_receptor varchar(100) NOT NULL,
            monto_neto int(11) NOT NULL,
            monto_iva int(11) NOT NULL,
            monto_total int(11) NOT NULL,
            fecha_emision date NOT NULL,
            track_id varchar(20) DEFAULT NULL,
            estado varchar(20) DEFAULT 'pendiente',
            ambiente varchar(10) NOT NULL DEFAULT 'cert',
            xml_enviado longtext,
            xml_respuesta longtext,
            fecha_envio datetime DEFAULT NULL,
            fecha_aceptacion datetime DEFAULT NULL,
            intentos int(11) DEFAULT 0,
            ultimo_error text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY folio_ambiente (folio, ambiente),
            KEY order_id (order_id),
            KEY track_id (track_id),
            KEY estado (estado)
        ) $charset_collate;";

        $sql_caf = "CREATE TABLE IF NOT EXISTS $table_caf (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            tipo_dte int(11) NOT NULL DEFAULT 39,
            folio_desde int(11) NOT NULL,
            folio_hasta int(11) NOT NULL,
            folio_actual int(11) NOT NULL,
            fecha_autorizacion date NOT NULL,
            rut_emisor varchar(15) NOT NULL,
            razon_social varchar(100) NOT NULL,
            ambiente varchar(10) NOT NULL DEFAULT 'cert',
            xml_caf longtext NOT NULL,
            clave_privada text NOT NULL,
            activo tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tipo_dte_ambiente (tipo_dte, ambiente),
            KEY activo (activo)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_boletas);
        dbDelta($sql_caf);
    }

    public static function get_table_name($table) {
        global $wpdb;
        return $wpdb->prefix . $table;
    }

    /**
     * Obtener siguiente folio disponible
     */
    public static function get_next_folio($ambiente = 'cert') {
        global $wpdb;
        $table = self::get_table_name(self::TABLE_CAF);

        $caf = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE tipo_dte = 39 AND ambiente = %s AND activo = 1 AND folio_actual <= folio_hasta ORDER BY id DESC LIMIT 1",
            $ambiente
        ));

        if (!$caf) {
            return new WP_Error('no_caf', 'No hay CAF disponible para el ambiente ' . $ambiente);
        }

        $folio = $caf->folio_actual;

        // Incrementar folio actual
        $wpdb->update(
            $table,
            ['folio_actual' => $folio + 1],
            ['id' => $caf->id]
        );

        // Si se acabaron los folios, desactivar CAF
        if ($folio >= $caf->folio_hasta) {
            $wpdb->update($table, ['activo' => 0], ['id' => $caf->id]);
        }

        return [
            'folio' => $folio,
            'caf' => $caf
        ];
    }

    /**
     * Guardar boleta en base de datos
     */
    public static function save_boleta($data) {
        global $wpdb;
        $table = self::get_table_name(self::TABLE_BOLETAS);

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return new WP_Error('db_error', $wpdb->last_error);
        }

        return $wpdb->insert_id;
    }

    /**
     * Actualizar boleta
     */
    public static function update_boleta($id, $data) {
        global $wpdb;
        $table = self::get_table_name(self::TABLE_BOLETAS);

        return $wpdb->update($table, $data, ['id' => $id]);
    }

    /**
     * Obtener boleta por ID
     */
    public static function get_boleta($id) {
        global $wpdb;
        $table = self::get_table_name(self::TABLE_BOLETAS);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ));
    }

    /**
     * Obtener boleta por order_id
     */
    public static function get_boleta_by_order($order_id) {
        global $wpdb;
        $table = self::get_table_name(self::TABLE_BOLETAS);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $order_id
        ));
    }

    /**
     * Obtener boletas pendientes
     */
    public static function get_pending_boletas($limit = 10) {
        global $wpdb;
        $table = self::get_table_name(self::TABLE_BOLETAS);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE estado IN ('pendiente', 'enviado') AND intentos < 10 ORDER BY id ASC LIMIT %d",
            $limit
        ));
    }

    /**
     * Guardar CAF
     */
    public static function save_caf($data) {
        global $wpdb;
        $table = self::get_table_name(self::TABLE_CAF);

        // Desactivar CAFs anteriores del mismo tipo y ambiente
        $wpdb->update(
            $table,
            ['activo' => 0],
            [
                'tipo_dte' => $data['tipo_dte'],
                'ambiente' => $data['ambiente']
            ]
        );

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return new WP_Error('db_error', $wpdb->last_error);
        }

        return $wpdb->insert_id;
    }

    /**
     * Obtener CAF activo
     */
    public static function get_active_caf($ambiente = 'cert') {
        global $wpdb;
        $table = self::get_table_name(self::TABLE_CAF);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE tipo_dte = 39 AND ambiente = %s AND activo = 1 ORDER BY id DESC LIMIT 1",
            $ambiente
        ));
    }
}
