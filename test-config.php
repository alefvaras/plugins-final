#!/usr/bin/env php
<?php
/**
 * Script de prueba para validar configuración SII
 * Verifica: certificado, CAF y conexión a semilla
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "\n=== TEST DE CONFIGURACIÓN SII ===\n\n";

// Cargar configuración del archivo principal
$CONFIG = [
    'ambiente' => 'cert',
    'cert_pfx' => __DIR__ . '/16694181-4-new.pfx',
    'cert_password' => '5605',
    'caf_file' => __DIR__ . '/FoliosSII7827422539120251191419 (1).xml',
    'rut_emisor' => '78274225-6',
    'razon_social' => 'AKIBARA SPA',
    'fch_resol' => '2025-11-16',
    'nro_resol' => 0,
];

$URLS = [
    'cert' => [
        'semilla' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.semilla',
        'token'   => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.token',
    ],
];

$errores = [];
$ok = 0;

// TEST 1: Verificar archivos existen
echo "1. Verificando archivos...\n";

if (file_exists($CONFIG['cert_pfx'])) {
    echo "   ✓ Certificado PFX encontrado: " . basename($CONFIG['cert_pfx']) . "\n";
    $ok++;
} else {
    $errores[] = "Certificado PFX no encontrado: " . $CONFIG['cert_pfx'];
    echo "   ✗ Certificado PFX NO encontrado\n";
}

if (file_exists($CONFIG['caf_file'])) {
    echo "   ✓ CAF encontrado: " . basename($CONFIG['caf_file']) . "\n";
    $ok++;
} else {
    $errores[] = "CAF no encontrado: " . $CONFIG['caf_file'];
    echo "   ✗ CAF NO encontrado\n";
}

// TEST 2: Cargar certificado
echo "\n2. Cargando certificado PFX...\n";

$pfx = @file_get_contents($CONFIG['cert_pfx']);
if ($pfx) {
    $certs = [];
    if (openssl_pkcs12_read($pfx, $certs, $CONFIG['cert_password'])) {
        $info = openssl_x509_parse($certs['cert']);
        echo "   ✓ Certificado cargado correctamente\n";
        echo "   → Nombre: " . ($info['subject']['CN'] ?? 'N/A') . "\n";
        echo "   → Serial: " . ($info['subject']['serialNumber'] ?? 'N/A') . "\n";
        echo "   → Válido hasta: " . date('Y-m-d', $info['validTo_time_t']) . "\n";

        // Verificar si está vigente
        if ($info['validTo_time_t'] > time()) {
            echo "   ✓ Certificado VIGENTE\n";
            $ok++;
        } else {
            echo "   ✗ Certificado EXPIRADO\n";
            $errores[] = "Certificado expirado";
        }
    } else {
        $errores[] = "Error al leer PFX: " . openssl_error_string();
        echo "   ✗ Error al leer certificado: " . openssl_error_string() . "\n";
    }
} else {
    echo "   ✗ No se pudo leer el archivo PFX\n";
}

// TEST 3: Cargar CAF
echo "\n3. Cargando CAF (folios)...\n";

$cafContent = @file_get_contents($CONFIG['caf_file']);
if ($cafContent) {
    $xml = @simplexml_load_string($cafContent);
    if ($xml && isset($xml->CAF)) {
        $desde = (int)$xml->CAF->DA->RNG->D;
        $hasta = (int)$xml->CAF->DA->RNG->H;
        $tipo = (int)$xml->CAF->DA->TD;
        $rut = (string)$xml->CAF->DA->RE;
        $rs = (string)$xml->CAF->DA->RS;
        $fa = (string)$xml->CAF->DA->FA;

        echo "   ✓ CAF cargado correctamente\n";
        echo "   → RUT empresa: $rut\n";
        echo "   → Razón social: $rs\n";
        echo "   → Tipo DTE: $tipo (Boleta Electrónica)\n";
        echo "   → Rango folios: $desde - $hasta\n";
        echo "   → Fecha autorización: $fa\n";
        echo "   → Folios disponibles: " . ($hasta - $desde + 1) . "\n";

        // Verificar clave privada RSA
        $rsask = (string)$xml->RSASK;
        if (!empty($rsask)) {
            echo "   ✓ Clave privada RSA presente\n";
            $ok++;
        } else {
            echo "   ⚠ Clave privada RSA no encontrada en formato esperado\n";
        }

        // Verificar que RUT coincide con configuración
        if ($rut === $CONFIG['rut_emisor']) {
            echo "   ✓ RUT coincide con configuración\n";
            $ok++;
        } else {
            echo "   ⚠ RUT en CAF ($rut) difiere de configuración ({$CONFIG['rut_emisor']})\n";
        }
    } else {
        echo "   ✗ Error al parsear XML del CAF\n";
        $errores[] = "Error al parsear CAF";
    }
} else {
    echo "   ✗ No se pudo leer el archivo CAF\n";
}

// TEST 4: Conectar al SII (obtener semilla)
echo "\n4. Probando conexión al SII (semilla)...\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $URLS['cert']['semilla'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT)',
        'Accept: application/xml',
    ],
]);

$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($code === 200) {
    echo "   ✓ Conexión exitosa (HTTP $code)\n";

    // Extraer semilla
    if (preg_match('/<SEMILLA>(\d+)<\/SEMILLA>/i', $response, $m)) {
        echo "   ✓ Semilla obtenida: " . $m[1] . "\n";
        $ok++;
    } else {
        $json = @json_decode($response, true);
        if ($json && isset($json['semilla'])) {
            echo "   ✓ Semilla obtenida: " . $json['semilla'] . "\n";
            $ok++;
        } else {
            echo "   → Respuesta: " . substr($response, 0, 200) . "\n";
        }
    }
} else {
    echo "   ✗ Error de conexión (HTTP $code)\n";
    if ($error) {
        echo "   → Error curl: $error\n";
    }
    $errores[] = "No se pudo conectar al SII";
}

// RESUMEN
echo "\n" . str_repeat('=', 50) . "\n";
echo "RESUMEN DE PRUEBAS\n";
echo str_repeat('=', 50) . "\n";

if (empty($errores)) {
    echo "✓ Todas las pruebas pasaron ($ok verificaciones OK)\n";
    echo "\nEl sistema está listo para ejecutar el script principal:\n";
    echo "  php sii-boleta-final.php\n";
} else {
    echo "✗ Se encontraron " . count($errores) . " errores:\n";
    foreach ($errores as $e) {
        echo "  - $e\n";
    }
}

echo "\n";
