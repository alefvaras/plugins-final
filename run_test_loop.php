#!/usr/bin/env php
<?php
/**
 * Script para ejecutar set de pruebas SII en loop hasta obtener trackID
 */

require_once __DIR__ . '/wp-load.php';

echo "\n════════════════════════════════════════════════════════════════\n";
echo "  SET DE PRUEBAS SII - BOLETA ELECTRÓNICA TIPO 39\n";
echo "  Ejecutando en loop hasta obtener TrackID exitoso\n";
echo "════════════════════════════════════════════════════════════════\n\n";

function log_msg($msg, $level = 'INFO') {
    echo "[" . date('H:i:s') . "][$level] $msg\n";
}

// Configuración
$max_attempts = 100;
$retry_delay = 10;

// Cargar API
$api = new SII_API();
$upload_dir = wp_upload_dir();
$cert_path = $upload_dir['basedir'] . '/sii-certificates/certificate.pfx';
$password = get_option('sii_cert_password', '');

log_msg("Cargando certificado...");
$result = $api->load_certificate($cert_path, $password);
if (is_wp_error($result)) {
    log_msg("ERROR: " . $result->get_error_message(), 'ERROR');
    exit(1);
}
log_msg("✓ Certificado cargado");

// Obtener CAF
$caf = SII_Database::get_active_caf('cert');
if (!$caf) {
    log_msg("ERROR: No hay CAF disponible", 'ERROR');
    exit(1);
}
log_msg("✓ CAF: Folios {$caf->folio_actual} - {$caf->folio_hasta}");

// Loop principal
for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
    log_msg("\n" . str_repeat('─', 50));
    log_msg("INTENTO #$attempt");
    log_msg(str_repeat('─', 50));

    // Autenticar
    log_msg("Autenticando...");
    $token = $api->authenticate();
    if (is_wp_error($token)) {
        log_msg("ERROR Auth: " . $token->get_error_message(), 'ERROR');
        sleep($retry_delay);
        continue;
    }
    log_msg("✓ Token obtenido: " . substr($token, 0, 15) . "...");

    // Obtener folio
    $folio_data = SII_Database::get_next_folio('cert');
    if (is_wp_error($folio_data)) {
        log_msg("ERROR Folio: " . $folio_data->get_error_message(), 'ERROR');
        break;
    }
    $folio = $folio_data['folio'];
    log_msg("✓ Folio: $folio");

    // Generar boleta CASO-1
    log_msg("Generando XML CASO-1...");
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
        log_msg("ERROR XML: " . $boleta->get_error_message(), 'ERROR');
        sleep($retry_delay);
        continue;
    }
    log_msg("✓ XML generado. Monto: $" . number_format($boleta['monto_total'], 0, ',', '.'));

    // Guardar XML para debug
    file_put_contents(__DIR__ . "/debug_boleta_$folio.xml", $boleta['xml']);

    // Enviar al SII
    log_msg("Enviando al SII...");
    $rut_sender = get_option('sii_rut_envia');
    $rut_company = get_option('sii_rut_emisor');

    $result = $api->send_boleta($boleta['xml'], $rut_sender, $rut_company);
    if (is_wp_error($result)) {
        log_msg("ERROR Envío: " . $result->get_error_message(), 'ERROR');
        sleep($retry_delay);
        continue;
    }

    log_msg("HTTP: {$result['code']}");
    log_msg("Response: " . substr($result['body'], 0, 300));

    if ($result['track_id']) {
        log_msg("\n" . str_repeat('★', 50));
        log_msg("★★★ TRACKID OBTENIDO: {$result['track_id']} ★★★");
        log_msg(str_repeat('★', 50));

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
            'ambiente' => 'cert',
            'xml_enviado' => $boleta['xml'],
            'xml_respuesta' => $result['body'],
            'fecha_envio' => current_time('mysql'),
        ]);

        // Consultar estado
        log_msg("\nConsultando estado (esperando 15 segundos)...");
        sleep(15);

        for ($i = 0; $i < 20; $i++) {
            $status = $api->check_status($result['track_id'], $rut_company);

            if (is_wp_error($status)) {
                if ($status->get_error_code() === 'not_authenticated') {
                    log_msg("Renovando token...");
                    $api->authenticate();
                    continue;
                }
                log_msg("ERROR Estado: " . $status->get_error_message());
            } else {
                $estado = $status['estado'] ?? 'desconocido';
                log_msg("Estado: $estado");

                if (in_array($estado, ['EPR', 'RLV', 'DOK', 'SOK', '00'])) {
                    log_msg("\n" . str_repeat('★', 50));
                    log_msg("★★★ BOLETA ACEPTADA POR EL SII ★★★");
                    log_msg("TrackID: {$result['track_id']}");
                    log_msg("Folio: $folio");
                    log_msg("Estado: $estado");
                    log_msg(str_repeat('★', 50));

                    file_put_contents(__DIR__ . '/exito.json', json_encode([
                        'track_id' => $result['track_id'],
                        'folio' => $folio,
                        'estado' => $estado,
                        'fecha' => date('c'),
                        'intentos' => $attempt,
                    ], JSON_PRETTY_PRINT));

                    exit(0);
                }

                if (in_array($estado, ['RCT', 'RCH', 'DNK', 'RFR'])) {
                    log_msg("Boleta rechazada: $estado", 'ERROR');
                    break;
                }
            }

            log_msg("Esperando 30 segundos...");
            sleep(30);
        }

        // TrackID obtenido pero estado pendiente
        log_msg("\n=== TrackID obtenido, estado pendiente ===");
        log_msg("Puede consultar el estado en el portal del SII");

        file_put_contents(__DIR__ . '/trackid.txt', $result['track_id']);
        exit(0);
    }

    log_msg("No se obtuvo trackID", 'WARN');
    sleep($retry_delay);
}

log_msg("\n=== No se pudo obtener trackID después de $max_attempts intentos ===", 'ERROR');
exit(1);
