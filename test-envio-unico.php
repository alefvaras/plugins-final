#!/usr/bin/env php
<?php
/**
 * Script de prueba de envío único al SII
 * Realiza UN solo intento de envío de boleta
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "\n════════════════════════════════════════════════════════════════\n";
echo "  PRUEBA DE ENVÍO ÚNICO - BOLETA ELECTRÓNICA SII\n";
echo "  Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════════════════════════════\n\n";

// Configuración
$CONFIG = [
    'ambiente' => 'cert',
    'cert_pfx' => __DIR__ . '/16694181-4-new.pfx',
    'cert_password' => '5605',
    'caf_file' => __DIR__ . '/FoliosSII7827422539120251191419 (1).xml',
    'rut_emisor' => '78274225-6',
    'razon_social' => 'AKIBARA SPA',
    'giro' => 'VENTA AL POR MENOR DE OTROS PRODUCTOS',
    'direccion' => 'DIRECCION EMPRESA',
    'comuna' => 'SANTIAGO',
    'ciudad' => 'SANTIAGO',
    'acteco' => 479100,
    'fch_resol' => '2025-11-16',
    'nro_resol' => 0,
];

$URLS = [
    'semilla' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.semilla',
    'token' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.token',
    'envio' => 'https://pangal.sii.cl/recursos/v1/boleta.electronica.envio',
    'estado' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.estado',
];

// Variables globales
$cert = null;
$key = null;
$certClean = null;
$caf = null;
$token = null;

function log_msg($msg, $level = 'INFO') {
    echo "[" . date('H:i:s') . "][$level] $msg\n";
}

function http_request($url, $method = 'GET', $body = null, $contentType = null, $isMultipart = false) {
    global $token;

    $ch = curl_init();

    $headers = [
        'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT)',
        'Accept: application/xml',
    ];

    if ($token && strpos($url, 'semilla') === false) {
        $headers[] = 'Cookie: TOKEN=' . $token;
    }

    if ($contentType && !$isMultipart) {
        $headers[] = 'Content-Type: ' . $contentType;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['code' => $code, 'body' => $response, 'error' => $error];
}

function firmar_xml($xml, $refUri = '') {
    global $cert, $key, $certClean;

    $doc = new DOMDocument('1.0', 'ISO-8859-1');
    $doc->loadXML($xml);

    $c14n = $doc->C14N();
    $digest = base64_encode(hash('sha1', $c14n, true));

    $signedInfo = '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">' .
        '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>' .
        '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>' .
        '<Reference URI="' . $refUri . '">' .
        '<Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/></Transforms>' .
        '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>' .
        '<DigestValue>' . $digest . '</DigestValue>' .
        '</Reference></SignedInfo>';

    $docSI = new DOMDocument();
    $docSI->loadXML($signedInfo);
    $c14nSI = $docSI->C14N();

    openssl_sign($c14nSI, $sig, $key, OPENSSL_ALGO_SHA1);
    $sigValue = base64_encode($sig);

    $pubkey = openssl_pkey_get_public($cert);
    $details = openssl_pkey_get_details($pubkey);
    $modulus = base64_encode($details['rsa']['n']);
    $exponent = base64_encode($details['rsa']['e']);

    $signature = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">' .
        $signedInfo .
        '<SignatureValue>' . $sigValue . '</SignatureValue>' .
        '<KeyInfo>' .
        '<KeyValue><RSAKeyValue>' .
        '<Modulus>' . $modulus . '</Modulus>' .
        '<Exponent>' . $exponent . '</Exponent>' .
        '</RSAKeyValue></KeyValue>' .
        '<X509Data><X509Certificate>' . $certClean . '</X509Certificate></X509Data>' .
        '</KeyInfo>' .
        '</Signature>';

    $pos = strrpos($xml, '</');
    return substr($xml, 0, $pos) . $signature . substr($xml, $pos);
}

function generar_ted($folio, $fecha, $monto, $item1) {
    global $caf, $CONFIG;

    // Generar CAF limpio sin saltos de línea
    $cafXml = '';
    foreach ($caf['parsed']->CAF->children() as $child) {
        $childXml = $child->asXML();
        // Limpiar todos los espacios entre tags y dentro de valores
        $childXml = preg_replace('/>\s+</', '><', $childXml);
        $childXml = preg_replace('/>\s+/', '>', $childXml);
        $childXml = preg_replace('/\s+</', '<', $childXml);
        $cafXml .= $childXml;
    }
    $cafXml = '<CAF version="1.0">' . $cafXml . '</CAF>';

    $dd = '<DD>' .
        '<RE>' . $CONFIG['rut_emisor'] . '</RE>' .
        '<TD>39</TD>' .
        '<F>' . $folio . '</F>' .
        '<FE>' . $fecha . '</FE>' .
        '<RR>66666666-6</RR>' .
        '<RSR>CONSUMIDOR FINAL</RSR>' .
        '<MNT>' . $monto . '</MNT>' .
        '<IT1>' . htmlspecialchars(substr($item1, 0, 40)) . '</IT1>' .
        $cafXml .
        '<TSTED>' . date('Y-m-d\TH:i:s') . '</TSTED>' .
        '</DD>';

    $cafKey = "-----BEGIN RSA PRIVATE KEY-----\n" .
              chunk_split($caf['rsask'], 64) .
              "-----END RSA PRIVATE KEY-----";

    openssl_sign($dd, $sig, $cafKey, OPENSSL_ALGO_SHA1);
    $frmt = base64_encode($sig);

    return '<TED version="1.0">' . $dd . '<FRMT algoritmo="SHA1withRSA">' . $frmt . '</FRMT></TED>';
}

// ============== INICIO ==============

// 1. Cargar certificado
log_msg("Cargando certificado...");
$pfx = file_get_contents($CONFIG['cert_pfx']);
$certs = [];
if (!openssl_pkcs12_read($pfx, $certs, $CONFIG['cert_password'])) {
    die("Error al cargar certificado: " . openssl_error_string() . "\n");
}
$cert = $certs['cert'];
$key = $certs['pkey'];
$certClean = preg_replace('/-----.*?-----/', '', $cert);
$certClean = preg_replace('/\s+/', '', $certClean);
$info = openssl_x509_parse($cert);
log_msg("✓ Certificado: " . $info['subject']['CN']);

// 2. Cargar CAF
log_msg("Cargando CAF...");
$xml = simplexml_load_file($CONFIG['caf_file']);
$rsask = (string)$xml->RSASK;
$rsask = preg_replace('/\s+/', '', $rsask);
$rsask = str_replace(['-----BEGINRSAPRIVATEKEY-----', '-----ENDRSAPRIVATEKEY-----'], '', $rsask);

$caf = [
    'xml' => file_get_contents($CONFIG['caf_file']),
    'desde' => (int)$xml->CAF->DA->RNG->D,
    'hasta' => (int)$xml->CAF->DA->RNG->H,
    'tipo' => (int)$xml->CAF->DA->TD,
    'rut' => (string)$xml->CAF->DA->RE,
    'rsask' => $rsask,
    'parsed' => $xml,
];
log_msg("✓ CAF: Folios {$caf['desde']} - {$caf['hasta']}");

// 3. Obtener semilla
log_msg("Obteniendo semilla...");
$resp = http_request($URLS['semilla'], 'GET');
if ($resp['code'] !== 200) {
    die("Error obteniendo semilla: HTTP {$resp['code']}\n");
}
if (preg_match('/<SEMILLA>(\d+)<\/SEMILLA>/i', $resp['body'], $m)) {
    $semilla = $m[1];
    log_msg("✓ Semilla: $semilla");
} else {
    die("No se encontró semilla en respuesta\n");
}

// 4. Obtener token
log_msg("Obteniendo token...");
$xmlToken = "<getToken><item><Semilla>$semilla</Semilla></item></getToken>";
$signedXml = firmar_xml($xmlToken, '');
$resp = http_request($URLS['token'], 'POST', $signedXml, 'application/xml');

if ($resp['code'] !== 200) {
    die("Error obteniendo token: HTTP {$resp['code']}: " . substr($resp['body'], 0, 500) . "\n");
}

if (preg_match('/<TOKEN>([^<]+)<\/TOKEN>/i', $resp['body'], $m)) {
    $token = $m[1];
    log_msg("✓ Token obtenido: " . substr($token, 0, 20) . "...");
} else {
    die("No se encontró token en respuesta: " . substr($resp['body'], 0, 500) . "\n");
}

// 5. Generar y enviar boleta (CASO-1 del Set de Pruebas)
$folio = $caf['desde'];
log_msg("Generando boleta - Folio: $folio");

$fecha = date('Y-m-d');
$timestamp = date('Y-m-d\TH:i:s');
$rut = $CONFIG['rut_emisor'];
list($rutNum, $rutDv) = explode('-', $rut);

// CASO-1: Cambio de aceite y Alineación
$items = [
    ['nombre' => 'Cambio de aceite', 'qty' => 1, 'precio' => 19900],
    ['nombre' => 'Alineacion y balanceo', 'qty' => 1, 'precio' => 9900],
];

$montoTotal = 0;
$detalleXml = '';
$nlin = 1;

foreach ($items as $item) {
    $subtotal = $item['qty'] * $item['precio'];
    $montoTotal += $subtotal;

    $detalleXml .= '<Detalle>' .
        '<NroLinDet>' . $nlin++ . '</NroLinDet>' .
        '<NmbItem>' . htmlspecialchars($item['nombre']) . '</NmbItem>' .
        '<QtyItem>' . $item['qty'] . '</QtyItem>' .
        '<PrcItem>' . $item['precio'] . '</PrcItem>' .
        '<MontoItem>' . $subtotal . '</MontoItem>' .
        '</Detalle>';
}

$montoNeto = round($montoTotal / 1.19);
$montoIVA = $montoTotal - $montoNeto;

$ted = generar_ted($folio, $fecha, $montoTotal, $items[0]['nombre']);

$docId = 'F' . $folio . 'T39';
$documento = '<Documento ID="' . $docId . '">' .
    '<Encabezado>' .
    '<IdDoc>' .
    '<TipoDTE>39</TipoDTE>' .
    '<Folio>' . $folio . '</Folio>' .
    '<FchEmis>' . $fecha . '</FchEmis>' .
    '<IndServicio>3</IndServicio>' .
    '</IdDoc>' .
    '<Emisor>' .
    '<RUTEmisor>' . $rut . '</RUTEmisor>' .
    '<RznSocEmisor>' . htmlspecialchars($CONFIG['razon_social']) . '</RznSocEmisor>' .
    '<GiroEmisor>' . htmlspecialchars(substr($CONFIG['giro'], 0, 80)) . '</GiroEmisor>' .
    '<Acteco>' . $CONFIG['acteco'] . '</Acteco>' .
    '<DirOrigen>' . htmlspecialchars($CONFIG['direccion']) . '</DirOrigen>' .
    '<CmnaOrigen>' . $CONFIG['comuna'] . '</CmnaOrigen>' .
    '<CiudadOrigen>' . $CONFIG['ciudad'] . '</CiudadOrigen>' .
    '</Emisor>' .
    '<Receptor>' .
    '<RUTRecep>66666666-6</RUTRecep>' .
    '<RznSocRecep>CONSUMIDOR FINAL</RznSocRecep>' .
    '</Receptor>' .
    '<Totales>' .
    '<MntNeto>' . $montoNeto . '</MntNeto>' .
    '<TasaIVA>19</TasaIVA>' .
    '<IVA>' . $montoIVA . '</IVA>' .
    '<MntTotal>' . $montoTotal . '</MntTotal>' .
    '</Totales>' .
    '</Encabezado>' .
    $detalleXml .
    '<Referencia>' .
    '<NroLinRef>1</NroLinRef>' .
    '<TpoDocRef>SET</TpoDocRef>' .
    '<RazonRef>CASO-1</RazonRef>' .
    '</Referencia>' .
    $ted .
    '</Documento>';

$docFirmado = firmar_xml($documento, '#' . $docId);

$dte = '<DTE version="1.0">' . $docFirmado . '</DTE>';

$setId = 'SetDoc';
$rutEnvia = '16694181-4'; // RUT del certificado que firma
$setDTE = '<SetDTE ID="' . $setId . '">' .
    '<Caratula version="1.0">' .
    '<RutEmisor>' . $rut . '</RutEmisor>' .
    '<RutEnvia>' . $rutEnvia . '</RutEnvia>' .
    '<FchResol>' . $CONFIG['fch_resol'] . '</FchResol>' .
    '<NroResol>' . $CONFIG['nro_resol'] . '</NroResol>' .
    '<TmstFirmaEnv>' . $timestamp . '</TmstFirmaEnv>' .
    '<SubTotDTE><TpoDTE>39</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>' .
    '</Caratula>' .
    $dte .
    '</SetDTE>';

$setFirmado = firmar_xml($setDTE, '#' . $setId);

$envioBoleta = '<?xml version="1.0" encoding="ISO-8859-1"?>' .
    '<EnvioBOLETA xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.0">' .
    $setFirmado .
    '</EnvioBOLETA>';

// Guardar XML para revisión
file_put_contents(__DIR__ . '/test_envio_' . $folio . '.xml', $envioBoleta);
log_msg("XML guardado: test_envio_{$folio}.xml");

// Guardar XML en archivo temporal para enviar
$tempFile = tempnam(sys_get_temp_dir(), 'sii_') . '.xml';
file_put_contents($tempFile, $envioBoleta);

log_msg("Enviando boleta al SII...");

// Usar CURLFile para multipart/form-data
$ch = curl_init();

// RUT del certificado (quien envía)
$rutSender = '16694181';
$dvSender = '4';

$postFields = [
    'rutSender' => $rutSender,
    'dvSender' => $dvSender,
    'rutCompany' => $rutNum,
    'dvCompany' => $rutDv,
    'archivo' => new CURLFile($tempFile, 'application/xml', 'EnvioBOLETA.xml'),
];

curl_setopt_array($ch, [
    CURLOPT_URL => $URLS['envio'],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => [
        'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT)',
        'Accept: application/xml',
        'Cookie: TOKEN=' . $token,
    ],
]);

$respBody = curl_exec($ch);
$respCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

unlink($tempFile);

$resp = ['code' => $respCode, 'body' => $respBody];

log_msg("HTTP Response: {$resp['code']}");
log_msg("Body: " . substr($resp['body'], 0, 1000));

// Extraer trackID
$trackId = null;

$json = @json_decode($resp['body'], true);
if ($json) {
    $trackId = $json['trackid'] ?? $json['TRACKID'] ?? null;
}

if (!$trackId && preg_match('/TRACKID[>\s:"\']+(\d+)/i', $resp['body'], $m)) {
    $trackId = $m[1];
}

if ($trackId) {
    log_msg("★★★ TRACKID OBTENIDO: $trackId ★★★", 'SUCCESS');

    // Consultar estado después de unos segundos
    log_msg("Esperando 10 segundos para consultar estado...");
    sleep(10);

    $url = $URLS['estado'] . "?rut_emisor=$rutNum&dv_emisor=$rutDv&trackid=$trackId";
    $resp = http_request($url, 'GET');
    log_msg("Estado HTTP: {$resp['code']}");
    log_msg("Estado Body: " . substr($resp['body'], 0, 500));
} else {
    log_msg("No se obtuvo trackID", 'ERROR');
}

echo "\n════════════════════════════════════════════════════════════════\n";
echo "  PRUEBA FINALIZADA\n";
echo "════════════════════════════════════════════════════════════════\n\n";
