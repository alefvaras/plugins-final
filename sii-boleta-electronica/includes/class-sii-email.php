<?php
/**
 * Clase para envío de emails con boletas
 */

if (!defined('ABSPATH')) {
    exit;
}

class SII_Email {

    /**
     * Enviar boleta por email al cliente
     */
    public static function send_boleta_email($order, $xml_content, $folio) {
        $to = $order->get_billing_email();

        if (!$to) {
            return false;
        }

        $razon_social = get_option('sii_razon_social', 'Empresa');
        $subject = "Boleta Electrónica N° $folio - $razon_social";

        // Crear PDF o XML adjunto
        $upload_dir = wp_upload_dir();
        $xml_path = $upload_dir['basedir'] . "/sii-boletas/boleta_$folio.xml";

        // Crear directorio si no existe
        $dir = dirname($xml_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        file_put_contents($xml_path, $xml_content);

        // Contenido del email
        $message = self::get_email_template($order, $folio);

        // Headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $razon_social . ' <' . get_option('admin_email') . '>',
        ];

        // Adjuntos
        $attachments = [$xml_path];

        // Enviar
        $sent = wp_mail($to, $subject, $message, $headers, $attachments);

        // Limpiar archivo temporal
        @unlink($xml_path);

        return $sent;
    }

    /**
     * Obtener plantilla de email
     */
    private static function get_email_template($order, $folio) {
        $razon_social = get_option('sii_razon_social', 'Empresa');
        $rut_emisor = get_option('sii_rut_emisor', '');
        $direccion = get_option('sii_direccion', '');
        $comuna = get_option('sii_comuna', '');
        $ciudad = get_option('sii_ciudad', '');

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0073aa; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
                th { background: #f0f0f0; }
                .total { font-weight: bold; font-size: 18px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>Boleta Electrónica</h1>
                    <p>N° <?php echo $folio; ?></p>
                </div>

                <div class="content">
                    <h2>Datos del Emisor</h2>
                    <p>
                        <strong><?php echo esc_html($razon_social); ?></strong><br>
                        RUT: <?php echo esc_html($rut_emisor); ?><br>
                        <?php echo esc_html($direccion); ?><br>
                        <?php echo esc_html($comuna); ?>, <?php echo esc_html($ciudad); ?>
                    </p>

                    <h2>Detalle de la Compra</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Cantidad</th>
                                <th>Precio</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order->get_items() as $item): ?>
                            <tr>
                                <td><?php echo esc_html($item->get_name()); ?></td>
                                <td><?php echo $item->get_quantity(); ?></td>
                                <td>$<?php echo number_format($item->get_total() / $item->get_quantity(), 0, ',', '.'); ?></td>
                                <td>$<?php echo number_format($item->get_total(), 0, ',', '.'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" style="text-align:right;"><strong>Neto:</strong></td>
                                <td>$<?php echo number_format($order->get_total() / 1.19, 0, ',', '.'); ?></td>
                            </tr>
                            <tr>
                                <td colspan="3" style="text-align:right;"><strong>IVA (19%):</strong></td>
                                <td>$<?php echo number_format($order->get_total() - ($order->get_total() / 1.19), 0, ',', '.'); ?></td>
                            </tr>
                            <tr class="total">
                                <td colspan="3" style="text-align:right;"><strong>TOTAL:</strong></td>
                                <td>$<?php echo number_format($order->get_total(), 0, ',', '.'); ?></td>
                            </tr>
                        </tfoot>
                    </table>

                    <p>
                        <strong>Fecha de Emisión:</strong> <?php echo date('d/m/Y'); ?><br>
                        <strong>Pedido N°:</strong> <?php echo $order->get_order_number(); ?>
                    </p>

                    <p style="margin-top: 20px; padding: 15px; background: #e7f3ff; border-left: 4px solid #0073aa;">
                        Este documento es una representación de su Boleta Electrónica.
                        El documento tributario electrónico (XML) ha sido enviado al SII.
                    </p>
                </div>

                <div class="footer">
                    <p>
                        <?php echo esc_html($razon_social); ?><br>
                        Este correo fue generado automáticamente. Por favor no responda a este mensaje.
                    </p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}
