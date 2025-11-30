<?php
require_once '/home/user/wordpress/wp-load.php';

$caf_file = '/home/user/plugins-final/FoliosSII7827422539120251191419 (1).xml';
$content = file_get_contents($caf_file);
$xml = simplexml_load_string($content);

$tipo_dte = (int) $xml->CAF->DA->TD;
$folio_desde = (int) $xml->CAF->DA->RNG->D;
$folio_hasta = (int) $xml->CAF->DA->RNG->H;
$fecha_auth = (string) $xml->CAF->DA->FA;
$rut_emisor = (string) $xml->CAF->DA->RE;
$razon_social = (string) $xml->CAF->DA->RS;

$rsask = (string) $xml->RSASK;
$rsask = preg_replace('/\s+/', '', $rsask);
$rsask = str_replace(['-----BEGINRSAPRIVATEKEY-----', '-----ENDRSAPRIVATEKEY-----'], '', $rsask);

$result = SII_Database::save_caf([
    'tipo_dte' => $tipo_dte,
    'folio_desde' => $folio_desde,
    'folio_hasta' => $folio_hasta,
    'folio_actual' => $folio_desde,
    'fecha_autorizacion' => $fecha_auth,
    'rut_emisor' => $rut_emisor,
    'razon_social' => $razon_social,
    'ambiente' => 'cert',
    'xml_caf' => $content,
    'clave_privada' => $rsask,
]);

if (is_wp_error($result)) {
    echo "Error: " . $result->get_error_message() . "\n";
} else {
    echo "CAF cargado exitosamente. ID: $result\n";
    echo "Folios: $folio_desde - $folio_hasta\n";
}
