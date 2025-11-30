#!/usr/bin/env php
<?php
/**
 * ================================================================================
 * BOLETA ELECTRÓNICA SII - API REST CORREGIDO
 * ================================================================================
 * 
 * CORRECCIONES APLICADAS:
 * - Envío usa multipart/form-data (no XML directo)
 * - Headers correctos (Accept: application/json, Cookie: TOKEN=xxx)
 * - Consulta de estado en apicert/api (no pangal/rahue)
 * - RVD (ex RCOF) con estructura correcta
 * - Firma XML-DSig correcta
 * 
 * FLUJO:
 * 1. GET  apicert.sii.cl → semilla
 * 2. POST apicert.sii.cl → token (semilla firmada)
 * 3. POST pangal.sii.cl  → envío boleta (multipart/form-data) → trackID
 * 4. GET  apicert.sii.cl → consulta estado
 * 5. POST pangal.sii.cl  → envío RVD
 * 
 * Autor: Claude para Alejandro (Akibara Store)
 * ================================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

// =============================================================================
// CONFIGURACIÓN
// =============================================================================

$CONFIG = [
    'ambiente' => 'cert', // 'cert' o 'prod'
    
    // Certificado
    'cert_pfx' => __DIR__ . '/certificates/certificado.pfx',
    'cert_password' => 'TU_PASSWORD',
    
    // CAF tipo 39
    'caf_file' => __DIR__ . '/certificates/CAF_39.xml',
    
    // Datos empresa
    'rut_emisor' => '76XXXXXX-X',
    'razon_social' => 'AKIBARA LTDA',
    'giro' => 'VENTA AL POR MENOR DE OTROS PRODUCTOS',
    'direccion' => 'TU DIRECCION 123',
    'comuna' => 'SANTIAGO',
    'ciudad' => 'SANTIAGO',
    'acteco' => 479100,
    
    // Resolución (la tuya real)
    'fch_resol' => '2014-08-22',
    'nro_resol' => 80,
    
    // Loop
    'max_hours' => 7,
    'retry_delay' => 30,
];

// URLs por ambiente
$URLS = [
    'cert' => [
        'semilla'  => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.semilla',
        'token'    => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.token',
        'envio'    => 'https://pangal.sii.cl/recursos/v1/boleta.electronica.envio',
        'estado'   => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.estado',  // Estado en apicert!
        'consulta' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.consulta',
    ],
    'prod' => [
        'semilla'  => 'https://api.sii.cl/recursos/v1/boleta.electronica.semilla',
        'token'    => 'https://api.sii.cl/recursos/v1/boleta.electronica.token',
        'envio'    => 'https://rahue.sii.cl/recursos/v1/boleta.electronica.envio',
        'estado'   => 'https://api.sii.cl/recursos/v1/boleta.electronica.estado',
        'consulta' => 'https://api.sii.cl/recursos/v1/boleta.electronica.consulta',
    ]
];

// =============================================================================
// CLASE PRINCIPAL
// =============================================================================

class SIIBoletaREST {
    private $config;
    private $urls;
    private $cert;
    private $key;
    private $certClean;
    private $caf;
    private $token;
    private $startTime;
    private $endTime;
    private $logFile;
    
    public function __construct($config, $urls) {
        $this->config = $config;
        $this->urls = $urls[$config['ambiente']];
        $this->startTime = time();
        $this->endTime = $this->startTime + ($config['max_hours'] * 3600);
        $this->logFile = __DIR__ . '/sii-' . date('Ymd-His') . '.log';
    }
    
    private function log($msg, $level = 'INFO') {
        $elapsed = gmdate('H:i:s', time() - $this->startTime);
        $remaining = gmdate('H:i:s', max(0, $this->endTime - time()));
        $line = "[" . date('H:i:s') . "][+$elapsed][-$remaining][$level] $msg\n";
        echo $line;
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
    
    private function shouldContinue() {
        return time() < $this->endTime;
    }
    
    // =========================================================================
    // HTTP REQUEST
    // =========================================================================
    
    private function http($url, $method = 'GET', $body = null, $contentType = null, $isMultipart = false) {
        $ch = curl_init();
        
        $headers = [
            'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT)',
            'Accept: application/json',
        ];
        
        // Token en Cookie para todas las llamadas excepto semilla
        if ($this->token && strpos($url, 'semilla') === false) {
            $headers[] = 'Cookie: TOKEN=' . $this->token;
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
    
    // =========================================================================
    // CARGAR CERTIFICADO
    // =========================================================================
    
    public function loadCertificate() {
        $this->log("Cargando certificado...");
        
        $pfx = file_get_contents($this->config['cert_pfx']);
        $certs = [];
        
        if (!openssl_pkcs12_read($pfx, $certs, $this->config['cert_password'])) {
            throw new Exception("Error PFX: " . openssl_error_string());
        }
        
        $this->cert = $certs['cert'];
        $this->key = $certs['pkey'];
        
        // Limpiar certificado para XML
        $this->certClean = preg_replace('/-----.*?-----/', '', $this->cert);
        $this->certClean = preg_replace('/\s+/', '', $this->certClean);
        
        $info = openssl_x509_parse($this->cert);
        $this->log("✓ Certificado: " . $info['subject']['CN']);
        $this->log("  Válido hasta: " . date('Y-m-d', $info['validTo_time_t']));
        
        return true;
    }
    
    // =========================================================================
    // CARGAR CAF
    // =========================================================================
    
    public function loadCAF() {
        $this->log("Cargando CAF...");
        
        $xml = simplexml_load_file($this->config['caf_file']);
        
        // Extraer clave privada RSA del CAF
        $rsask = (string)$xml->CAF->RSASK;
        if (empty($rsask)) {
            $rsask = (string)$xml->RSASK;
        }
        $rsask = preg_replace('/\s+/', '', $rsask);
        
        $this->caf = [
            'xml' => file_get_contents($this->config['caf_file']),
            'desde' => (int)$xml->CAF->DA->RNG->D,
            'hasta' => (int)$xml->CAF->DA->RNG->H,
            'tipo' => (int)$xml->CAF->DA->TD,
            'rut' => (string)$xml->CAF->DA->RE,
            'rsask' => $rsask,
            'parsed' => $xml,
        ];
        
        $this->log("✓ CAF: Folios {$this->caf['desde']} - {$this->caf['hasta']}");
        
        return true;
    }
    
    // =========================================================================
    // PASO 1: OBTENER SEMILLA
    // =========================================================================
    
    public function getSemilla() {
        $this->log("PASO 1: Obteniendo semilla...");
        
        $resp = $this->http($this->urls['semilla'], 'GET');
        
        if ($resp['code'] !== 200) {
            $this->log("✗ HTTP {$resp['code']}", 'ERROR');
            return null;
        }
        
        // Puede venir como XML
        if (preg_match('/<SEMILLA>(\d+)<\/SEMILLA>/i', $resp['body'], $m)) {
            $this->log("✓ Semilla: " . $m[1]);
            return $m[1];
        }
        
        // O como JSON
        $json = @json_decode($resp['body'], true);
        if ($json && isset($json['semilla'])) {
            $this->log("✓ Semilla: " . $json['semilla']);
            return $json['semilla'];
        }
        
        $this->log("✗ No se encontró semilla en: " . substr($resp['body'], 0, 200), 'ERROR');
        return null;
    }
    
    // =========================================================================
    // PASO 2: OBTENER TOKEN
    // =========================================================================
    
    public function getToken($semilla) {
        $this->log("PASO 2: Obteniendo token...");
        
        // Crear XML a firmar
        $xml = "<getToken><item><Semilla>$semilla</Semilla></item></getToken>";
        
        // Firmar
        $signedXml = $this->firmarXML($xml, '');
        
        $resp = $this->http($this->urls['token'], 'POST', $signedXml, 'application/xml');
        
        if ($resp['code'] !== 200) {
            $this->log("✗ HTTP {$resp['code']}: " . substr($resp['body'], 0, 300), 'ERROR');
            return null;
        }
        
        // Extraer token
        if (preg_match('/<TOKEN>([^<]+)<\/TOKEN>/i', $resp['body'], $m)) {
            $this->token = $m[1];
            $this->log("✓ Token: " . $this->token);
            return $this->token;
        }
        
        // Verificar error
        if (preg_match('/<ESTADO>(\d+)<\/ESTADO>.*?<GLOSA>([^<]*)<\/GLOSA>/is', $resp['body'], $m)) {
            $this->log("✗ SII Error [{$m[1]}]: {$m[2]}", 'ERROR');
        }
        
        return null;
    }
    
    // =========================================================================
    // FIRMAR XML (XML-DSig)
    // =========================================================================
    
    private function firmarXML($xml, $refUri = '') {
        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $doc->loadXML($xml);
        
        // Canonicalizar para digest
        $c14n = $doc->C14N();
        $digest = base64_encode(hash('sha1', $c14n, true));
        
        // SignedInfo
        $signedInfo = '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">' .
            '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>' .
            '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>' .
            '<Reference URI="' . $refUri . '">' .
            '<Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/></Transforms>' .
            '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>' .
            '<DigestValue>' . $digest . '</DigestValue>' .
            '</Reference></SignedInfo>';
        
        // Canonicalizar SignedInfo y firmar
        $docSI = new DOMDocument();
        $docSI->loadXML($signedInfo);
        $c14nSI = $docSI->C14N();
        
        openssl_sign($c14nSI, $sig, $this->key, OPENSSL_ALGO_SHA1);
        $sigValue = base64_encode($sig);
        
        // KeyInfo
        $pubkey = openssl_pkey_get_public($this->cert);
        $details = openssl_pkey_get_details($pubkey);
        $modulus = base64_encode($details['rsa']['n']);
        $exponent = base64_encode($details['rsa']['e']);
        
        // Signature completa
        $signature = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">' .
            $signedInfo .
            '<SignatureValue>' . $sigValue . '</SignatureValue>' .
            '<KeyInfo>' .
            '<KeyValue><RSAKeyValue>' .
            '<Modulus>' . $modulus . '</Modulus>' .
            '<Exponent>' . $exponent . '</Exponent>' .
            '</RSAKeyValue></KeyValue>' .
            '<X509Data><X509Certificate>' . $this->certClean . '</X509Certificate></X509Data>' .
            '</KeyInfo>' .
            '</Signature>';
        
        // Insertar firma antes del último tag de cierre
        $pos = strrpos($xml, '</');
        return substr($xml, 0, $pos) . $signature . substr($xml, $pos);
    }
    
    // =========================================================================
    // PASO 3: GENERAR Y ENVIAR BOLETA
    // =========================================================================
    
    public function enviarBoleta($folio) {
        $this->log("PASO 3: Enviando boleta (folio: $folio)...");
        
        $fecha = date('Y-m-d');
        $timestamp = date('Y-m-d\TH:i:s');
        $rut = $this->config['rut_emisor'];
        list($rutNum, $rutDv) = explode('-', $rut);
        
        // Items
        $items = [
            ['nombre' => 'Manga Dragon Ball Vol. 1', 'qty' => 1, 'precio' => 9990],
            ['nombre' => 'Manga One Piece Vol. 1', 'qty' => 2, 'precio' => 8990],
        ];
        
        // Calcular montos (precios incluyen IVA)
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
        
        // Calcular neto e IVA desde el total (montos incluyen IVA)
        $montoNeto = round($montoTotal / 1.19);
        $montoIVA = $montoTotal - $montoNeto;
        
        // Generar TED
        $ted = $this->generarTED($folio, $fecha, $montoTotal, $items[0]['nombre']);
        
        // Documento (boleta)
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
            '<RznSocEmisor>' . htmlspecialchars($this->config['razon_social']) . '</RznSocEmisor>' .
            '<GiroEmisor>' . htmlspecialchars(substr($this->config['giro'], 0, 80)) . '</GiroEmisor>' .
            '<Acteco>' . $this->config['acteco'] . '</Acteco>' .
            '<DirOrigen>' . htmlspecialchars($this->config['direccion']) . '</DirOrigen>' .
            '<CmnaOrigen>' . $this->config['comuna'] . '</CmnaOrigen>' .
            '<CiudadOrigen>' . $this->config['ciudad'] . '</CiudadOrigen>' .
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
            $ted .
            '</Documento>';
        
        // Firmar documento
        $docFirmado = $this->firmarXML($documento, '#' . $docId);
        
        // DTE wrapper
        $dte = '<DTE version="1.0">' . $docFirmado . '</DTE>';
        
        // SetDTE
        $setId = 'SetDoc';
        $setDTE = '<SetDTE ID="' . $setId . '">' .
            '<Caratula version="1.0">' .
            '<RutEmisor>' . $rut . '</RutEmisor>' .
            '<RutEnvia>' . $rut . '</RutEnvia>' .
            '<FchResol>' . $this->config['fch_resol'] . '</FchResol>' .
            '<NroResol>' . $this->config['nro_resol'] . '</NroResol>' .
            '<TmstFirmaEnv>' . $timestamp . '</TmstFirmaEnv>' .
            '<SubTotDTE><TpoDTE>39</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>' .
            '</Caratula>' .
            $dte .
            '</SetDTE>';
        
        // Firmar SetDTE
        $setFirmado = $this->firmarXML($setDTE, '#' . $setId);
        
        // EnvioBOLETA
        $envioBoleta = '<?xml version="1.0" encoding="ISO-8859-1"?>' .
            '<EnvioBOLETA xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.0">' .
            $setFirmado .
            '</EnvioBOLETA>';
        
        // Guardar XML para debug
        file_put_contents(__DIR__ . '/envio_boleta_' . $folio . '.xml', $envioBoleta);
        
        // Enviar como multipart/form-data
        $boundary = '----SIIBoundary' . md5(time());
        
        $body = "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"rutSender\"\r\n\r\n";
        $body .= "$rutNum\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"dvSender\"\r\n\r\n";
        $body .= "$rutDv\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"rutCompany\"\r\n\r\n";
        $body .= "$rutNum\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"dvCompany\"\r\n\r\n";
        $body .= "$rutDv\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"archivo\"; filename=\"envio.xml\"\r\n";
        $body .= "Content-Type: text/xml\r\n\r\n";
        $body .= $envioBoleta . "\r\n";
        $body .= "--$boundary--\r\n";
        
        $this->log("  POST " . $this->urls['envio']);
        
        $resp = $this->http(
            $this->urls['envio'],
            'POST',
            $body,
            'multipart/form-data; boundary=' . $boundary,
            true
        );
        
        $this->log("  HTTP: {$resp['code']}");
        $this->log("  Response: " . substr($resp['body'], 0, 500));
        
        // Extraer trackID
        $trackId = null;
        
        // JSON
        $json = @json_decode($resp['body'], true);
        if ($json) {
            $trackId = $json['trackid'] ?? $json['TRACKID'] ?? null;
        }
        
        // XML
        if (!$trackId && preg_match('/TRACKID[>\s:"\']+(\d+)/i', $resp['body'], $m)) {
            $trackId = $m[1];
        }
        
        if ($trackId) {
            $this->log("✓ TRACKID: $trackId");
            return ['trackId' => $trackId, 'neto' => $montoNeto, 'iva' => $montoIVA, 'total' => $montoTotal];
        }
        
        $this->log("✗ No se obtuvo trackID", 'ERROR');
        return null;
    }
    
    // =========================================================================
    // GENERAR TED
    // =========================================================================
    
    private function generarTED($folio, $fecha, $monto, $item1) {
        $caf = $this->caf['parsed'];
        
        // Obtener solo el contenido CAF (sin el tag raíz AUTORIZACION)
        $cafXml = '';
        foreach ($caf->CAF->children() as $child) {
            $cafXml .= $child->asXML();
        }
        $cafXml = '<CAF version="1.0">' . $cafXml . '</CAF>';
        
        $dd = '<DD>' .
            '<RE>' . $this->config['rut_emisor'] . '</RE>' .
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
        
        // Firmar con clave del CAF
        $cafKey = "-----BEGIN RSA PRIVATE KEY-----\n" . 
                  chunk_split($this->caf['rsask'], 64) . 
                  "-----END RSA PRIVATE KEY-----";
        
        openssl_sign($dd, $sig, $cafKey, OPENSSL_ALGO_SHA1);
        $frmt = base64_encode($sig);
        
        return '<TED version="1.0">' . $dd . '<FRMT algoritmo="SHA1withRSA">' . $frmt . '</FRMT></TED>';
    }
    
    // =========================================================================
    // PASO 4: CONSULTAR ESTADO
    // =========================================================================
    
    public function consultarEstado($trackId) {
        $this->log("PASO 4: Consultando estado (trackID: $trackId)...");
        
        $rut = $this->config['rut_emisor'];
        list($rutNum, $rutDv) = explode('-', $rut);
        
        // La consulta va a apicert/api, NO a pangal/rahue
        $url = $this->urls['estado'] . "?rut_emisor=$rutNum&dv_emisor=$rutDv&trackid=$trackId";
        
        $resp = $this->http($url, 'GET');
        
        $this->log("  HTTP: {$resp['code']}");
        $this->log("  Response: " . substr($resp['body'], 0, 300));
        
        if ($resp['body'] === 'NO ESTA AUTENTICADO') {
            return ['estado' => 'TOKEN_EXPIRED'];
        }
        
        $json = @json_decode($resp['body'], true);
        if ($json) {
            return $json;
        }
        
        // XML
        if (preg_match('/<ESTADO>([^<]*)<\/ESTADO>/i', $resp['body'], $m)) {
            return ['estado' => $m[1]];
        }
        
        return ['estado' => 'UNKNOWN', 'raw' => $resp['body']];
    }
    
    // =========================================================================
    // PASO 5: ENVIAR RVD (ex RCOF)
    // =========================================================================
    
    public function enviarRVD($foliosUsados) {
        $this->log("PASO 5: Enviando RVD (Resumen Ventas Diarias)...");
        
        $fecha = date('Y-m-d');
        $timestamp = date('Y-m-d\TH:i:s');
        $rut = $this->config['rut_emisor'];
        list($rutNum, $rutDv) = explode('-', $rut);
        $secEnvio = date('YmdHis');
        
        // Sumar totales
        $totalNeto = array_sum(array_column($foliosUsados, 'neto'));
        $totalIva = array_sum(array_column($foliosUsados, 'iva'));
        $totalTotal = array_sum(array_column($foliosUsados, 'total'));
        $cantBoletas = count($foliosUsados);
        
        $docId = 'RVD_' . $fecha;
        
        // Estructura RVD (ConsumoFolios simplificado)
        $rvd = '<?xml version="1.0" encoding="ISO-8859-1"?>' .
            '<ConsumoFolios xmlns="http://www.sii.cl/SiiDte" version="1.0">' .
            '<DocumentoConsumoFolios ID="' . $docId . '">' .
            '<Caratula>' .
            '<RutEmisor>' . $rut . '</RutEmisor>' .
            '<RutEnvia>' . $rut . '</RutEnvia>' .
            '<FchResol>' . $this->config['fch_resol'] . '</FchResol>' .
            '<NroResol>' . $this->config['nro_resol'] . '</NroResol>' .
            '<FchInicio>' . $fecha . '</FchInicio>' .
            '<FchFinal>' . $fecha . '</FchFinal>' .
            '<SecEnvio>' . $secEnvio . '</SecEnvio>' .
            '<TmstFirmaEnv>' . $timestamp . '</TmstFirmaEnv>' .
            '</Caratula>' .
            '<Resumen>' .
            '<TipoDocumento>39</TipoDocumento>' .
            '<MntNeto>' . $totalNeto . '</MntNeto>' .
            '<MntIva>' . $totalIva . '</MntIva>' .
            '<TasaIVA>19</TasaIVA>' .
            '<MntTotal>' . $totalTotal . '</MntTotal>' .
            '<FoliosEmitidos>' . $cantBoletas . '</FoliosEmitidos>' .
            '<FoliosAnulados>0</FoliosAnulados>' .
            '<FoliosUtilizados>' . $cantBoletas . '</FoliosUtilizados>' .
            '</Resumen>' .
            '</DocumentoConsumoFolios>' .
            '</ConsumoFolios>';
        
        // Firmar
        $rvdFirmado = $this->firmarXML($rvd, '#' . $docId);
        
        // Guardar para debug
        file_put_contents(__DIR__ . '/rvd_' . $fecha . '.xml', $rvdFirmado);
        
        // Enviar igual que boleta
        $boundary = '----SIIBoundary' . md5(time() . 'rvd');
        
        $body = "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"rutSender\"\r\n\r\n$rutNum\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"dvSender\"\r\n\r\n$rutDv\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"rutCompany\"\r\n\r\n$rutNum\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"dvCompany\"\r\n\r\n$rutDv\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"archivo\"; filename=\"rvd.xml\"\r\n";
        $body .= "Content-Type: text/xml\r\n\r\n";
        $body .= $rvdFirmado . "\r\n";
        $body .= "--$boundary--\r\n";
        
        $resp = $this->http(
            $this->urls['envio'],
            'POST',
            $body,
            'multipart/form-data; boundary=' . $boundary,
            true
        );
        
        $this->log("  HTTP: {$resp['code']}");
        $this->log("  Response: " . substr($resp['body'], 0, 300));
        
        // Extraer trackID
        if (preg_match('/TRACKID[>\s:"\']+(\d+)/i', $resp['body'], $m)) {
            $this->log("✓ RVD TRACKID: " . $m[1]);
            return $m[1];
        }
        
        $json = @json_decode($resp['body'], true);
        if ($json && isset($json['trackid'])) {
            $this->log("✓ RVD TRACKID: " . $json['trackid']);
            return $json['trackid'];
        }
        
        return null;
    }
    
    // =========================================================================
    // RUN
    // =========================================================================
    
    public function run() {
        echo "\n════════════════════════════════════════════════════════════════\n";
        echo "  BOLETA ELECTRÓNICA SII - API REST\n";
        echo "  Ambiente: " . strtoupper($this->config['ambiente']) . "\n";
        echo "  Inicio: " . date('Y-m-d H:i:s') . "\n";
        echo "════════════════════════════════════════════════════════════════\n\n";
        
        try {
            $this->loadCertificate();
            $this->loadCAF();
        } catch (Exception $e) {
            $this->log("FATAL: " . $e->getMessage(), 'ERROR');
            return false;
        }
        
        $folio = $this->caf['desde'];
        $intento = 0;
        
        while ($this->shouldContinue()) {
            $intento++;
            $this->log("\n" . str_repeat('─', 50));
            $this->log("INTENTO #$intento - Folio: $folio");
            $this->log(str_repeat('─', 50));
            
            try {
                // 1. Semilla
                $semilla = $this->getSemilla();
                if (!$semilla) { sleep($this->config['retry_delay']); continue; }
                
                // 2. Token
                if (!$this->getToken($semilla)) { sleep($this->config['retry_delay']); continue; }
                
                // 3. Enviar boleta
                $result = $this->enviarBoleta($folio);
                if (!$result) { $folio++; sleep($this->config['retry_delay']); continue; }
                
                $trackId = $result['trackId'];
                
                // 4. Enviar RVD (solo certificación)
                if ($this->config['ambiente'] === 'cert') {
                    $this->enviarRVD([['neto' => $result['neto'], 'iva' => $result['iva'], 'total' => $result['total']]]);
                }
                
                // 5. Consultar estado
                $this->log("\n★★★ TRACKID: $trackId ★★★\n");
                
                for ($i = 0; $i < 60 && $this->shouldContinue(); $i++) {
                    sleep(30);
                    
                    $estado = $this->consultarEstado($trackId);
                    $code = strtoupper($estado['estado'] ?? '');
                    
                    if ($code === 'TOKEN_EXPIRED') {
                        $this->log("Renovando token...");
                        $semilla = $this->getSemilla();
                        if ($semilla) $this->getToken($semilla);
                        continue;
                    }
                    
                    // Éxito
                    if (in_array($code, ['EPR', 'RLV', 'DOK', 'SOK', '00'])) {
                        $this->log("\n" . str_repeat('★', 50));
                        $this->log("ÉXITO! Estado: $code");
                        $this->log("TrackID: $trackId, Folio: $folio");
                        $this->log(str_repeat('★', 50));
                        
                        file_put_contents(__DIR__ . '/exito.json', json_encode([
                            'trackId' => $trackId, 'folio' => $folio, 'estado' => $code,
                            'fecha' => date('c'), 'intentos' => $intento
                        ], JSON_PRETTY_PRINT));
                        
                        return true;
                    }
                    
                    // Rechazo
                    if (in_array($code, ['RCT', 'RCH', 'DNK', 'RFR'])) {
                        $this->log("RECHAZADO: $code", 'ERROR');
                        break;
                    }
                    
                    $this->log("Estado: $code (esperando...)");
                }
                
                $folio++;
                
            } catch (Exception $e) {
                $this->log("Error: " . $e->getMessage(), 'ERROR');
            }
            
            if ($folio > $this->caf['hasta']) {
                $this->log("Folios agotados!", 'ERROR');
                return false;
            }
            
            sleep($this->config['retry_delay']);
        }
        
        $this->log("\nTiempo agotado. Intentos: $intento");
        return false;
    }
}

// =============================================================================
// EJECUTAR
// =============================================================================

$sii = new SIIBoletaREST($CONFIG, $URLS);
exit($sii->run() ? 0 : 1);
