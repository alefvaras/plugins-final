<?php
/**
 * Clase para comunicación con la API del SII
 */

if (!defined('ABSPATH')) {
    exit;
}

class SII_API {

    private $ambiente;
    private $token;
    private $cert;
    private $key;
    private $cert_clean;

    // URLs de la API
    private $urls = [
        'cert' => [
            'semilla' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.semilla',
            'token' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.token',
            'envio' => 'https://pangal.sii.cl/recursos/v1/boleta.electronica.envio',
            'estado' => 'https://apicert.sii.cl/recursos/v1/boleta.electronica.estado',
        ],
        'prod' => [
            'semilla' => 'https://api.sii.cl/recursos/v1/boleta.electronica.semilla',
            'token' => 'https://api.sii.cl/recursos/v1/boleta.electronica.token',
            'envio' => 'https://rahue.sii.cl/recursos/v1/boleta.electronica.envio',
            'estado' => 'https://api.sii.cl/recursos/v1/boleta.electronica.estado',
        ],
    ];

    public function __construct($ambiente = null) {
        $this->ambiente = $ambiente ?: get_option('sii_ambiente', 'cert');
    }

    /**
     * Cargar certificado PFX
     */
    public function load_certificate($pfx_path, $password) {
        if (!file_exists($pfx_path)) {
            return new WP_Error('cert_not_found', 'Certificado no encontrado');
        }

        $pfx_content = file_get_contents($pfx_path);
        $certs = [];

        if (!openssl_pkcs12_read($pfx_content, $certs, $password)) {
            return new WP_Error('cert_error', 'Error al leer certificado: ' . openssl_error_string());
        }

        $this->cert = $certs['cert'];
        $this->key = $certs['pkey'];

        // Limpiar certificado para XML
        $this->cert_clean = preg_replace('/-----.*?-----/', '', $this->cert);
        $this->cert_clean = preg_replace('/\s+/', '', $this->cert_clean);

        return true;
    }

    /**
     * Realizar petición HTTP
     */
    private function http_request($url, $method = 'GET', $body = null, $content_type = null, $is_multipart = false) {
        $headers = [
            'User-Agent' => 'Mozilla/4.0 (compatible; PROG 1.0; Windows NT)',
            'Accept' => 'application/xml',
        ];

        if ($this->token && strpos($url, 'semilla') === false) {
            $headers['Cookie'] = 'TOKEN=' . $this->token;
        }

        if ($content_type && !$is_multipart) {
            $headers['Content-Type'] = $content_type;
        }

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 120,
            'sslverify' => true,
        ];

        if ($body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'code' => wp_remote_retrieve_response_code($response),
            'body' => wp_remote_retrieve_body($response),
        ];
    }

    /**
     * Obtener semilla
     */
    public function get_semilla() {
        $url = $this->urls[$this->ambiente]['semilla'];
        $response = $this->http_request($url, 'GET');

        if (is_wp_error($response)) {
            return $response;
        }

        if ($response['code'] !== 200) {
            return new WP_Error('semilla_error', 'Error HTTP: ' . $response['code']);
        }

        if (preg_match('/<SEMILLA>(\d+)<\/SEMILLA>/i', $response['body'], $matches)) {
            return $matches[1];
        }

        return new WP_Error('semilla_parse_error', 'No se pudo extraer la semilla');
    }

    /**
     * Obtener token
     */
    public function get_token($semilla) {
        $xml = "<getToken><item><Semilla>$semilla</Semilla></item></getToken>";
        $signed_xml = $this->sign_xml($xml, '');

        if (is_wp_error($signed_xml)) {
            return $signed_xml;
        }

        $url = $this->urls[$this->ambiente]['token'];
        $response = $this->http_request($url, 'POST', $signed_xml, 'application/xml');

        if (is_wp_error($response)) {
            return $response;
        }

        if ($response['code'] !== 200) {
            return new WP_Error('token_error', 'Error HTTP: ' . $response['code'] . ' - ' . $response['body']);
        }

        if (preg_match('/<TOKEN>([^<]+)<\/TOKEN>/i', $response['body'], $matches)) {
            $this->token = $matches[1];
            return $this->token;
        }

        // Verificar error del SII
        if (preg_match('/<ESTADO>(\d+)<\/ESTADO>.*?<GLOSA>([^<]*)<\/GLOSA>/is', $response['body'], $matches)) {
            return new WP_Error('sii_error', "Error SII [{$matches[1]}]: {$matches[2]}");
        }

        return new WP_Error('token_parse_error', 'No se pudo extraer el token');
    }

    /**
     * Autenticar (obtener semilla y token)
     */
    public function authenticate() {
        $semilla = $this->get_semilla();
        if (is_wp_error($semilla)) {
            return $semilla;
        }

        return $this->get_token($semilla);
    }

    /**
     * Enviar boleta al SII
     */
    public function send_boleta($xml_content, $rut_sender, $rut_company) {
        // Crear archivo temporal
        $temp_file = sys_get_temp_dir() . '/sii_boleta_' . uniqid() . '.xml';
        file_put_contents($temp_file, $xml_content);

        list($rut_sender_num, $rut_sender_dv) = explode('-', $rut_sender);
        list($rut_company_num, $rut_company_dv) = explode('-', $rut_company);

        // Preparar datos multipart usando cURL directamente
        $url = $this->urls[$this->ambiente]['envio'];

        $ch = curl_init();

        $post_fields = [
            'rutSender' => $rut_sender_num,
            'dvSender' => $rut_sender_dv,
            'rutCompany' => $rut_company_num,
            'dvCompany' => $rut_company_dv,
            'archivo' => new CURLFile($temp_file, 'text/xml', 'envio.xml'),
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT)',
                'Accept: application/json',
                'Cookie: TOKEN=' . $this->token,
            ],
        ]);

        $response_body = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Eliminar archivo temporal
        @unlink($temp_file);

        if ($curl_error) {
            return new WP_Error('curl_error', $curl_error);
        }

        $result = [
            'code' => $response_code,
            'body' => $response_body,
            'track_id' => null,
        ];

        // Extraer trackID
        $json = @json_decode($response_body, true);
        if ($json && isset($json['trackid'])) {
            $result['track_id'] = $json['trackid'];
        } elseif (preg_match('/TRACKID[>\s:"\']+(\d+)/i', $response_body, $matches)) {
            $result['track_id'] = $matches[1];
        }

        return $result;
    }

    /**
     * Consultar estado de boleta
     */
    public function check_status($track_id, $rut_emisor) {
        list($rut_num, $rut_dv) = explode('-', $rut_emisor);

        $url = $this->urls[$this->ambiente]['estado'] . "?rut_emisor=$rut_num&dv_emisor=$rut_dv&trackid=$track_id";

        $response = $this->http_request($url, 'GET');

        if (is_wp_error($response)) {
            return $response;
        }

        if ($response['body'] === 'NO ESTA AUTENTICADO') {
            return new WP_Error('not_authenticated', 'Token expirado');
        }

        $result = [
            'code' => $response['code'],
            'body' => $response['body'],
            'estado' => null,
        ];

        $json = @json_decode($response['body'], true);
        if ($json && isset($json['estado'])) {
            $result['estado'] = $json['estado'];
        } elseif (preg_match('/<ESTADO>([^<]*)<\/ESTADO>/i', $response['body'], $matches)) {
            $result['estado'] = $matches[1];
        }

        return $result;
    }

    /**
     * Firmar XML
     */
    public function sign_xml($xml, $ref_uri = '') {
        if (!$this->cert || !$this->key) {
            return new WP_Error('no_cert', 'Certificado no cargado');
        }

        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $doc->loadXML($xml);

        // Canonicalizar para digest
        $c14n = $doc->C14N();
        $digest = base64_encode(hash('sha1', $c14n, true));

        // SignedInfo
        $signed_info = '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">' .
            '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>' .
            '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>' .
            '<Reference URI="' . $ref_uri . '">' .
            '<Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/></Transforms>' .
            '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>' .
            '<DigestValue>' . $digest . '</DigestValue>' .
            '</Reference></SignedInfo>';

        // Canonicalizar SignedInfo y firmar
        $doc_si = new DOMDocument();
        $doc_si->loadXML($signed_info);
        $c14n_si = $doc_si->C14N();

        openssl_sign($c14n_si, $sig, $this->key, OPENSSL_ALGO_SHA1);
        $sig_value = base64_encode($sig);

        // KeyInfo
        $pubkey = openssl_pkey_get_public($this->cert);
        $details = openssl_pkey_get_details($pubkey);
        $modulus = base64_encode($details['rsa']['n']);
        $exponent = base64_encode($details['rsa']['e']);

        // Signature completa
        $signature = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">' .
            $signed_info .
            '<SignatureValue>' . $sig_value . '</SignatureValue>' .
            '<KeyInfo>' .
            '<KeyValue><RSAKeyValue>' .
            '<Modulus>' . $modulus . '</Modulus>' .
            '<Exponent>' . $exponent . '</Exponent>' .
            '</RSAKeyValue></KeyValue>' .
            '<X509Data><X509Certificate>' . $this->cert_clean . '</X509Certificate></X509Data>' .
            '</KeyInfo>' .
            '</Signature>';

        // Insertar firma antes del último tag de cierre
        $pos = strrpos($xml, '</');
        return substr($xml, 0, $pos) . $signature . substr($xml, $pos);
    }

    /**
     * Obtener token actual
     */
    public function get_current_token() {
        return $this->token;
    }

    /**
     * Establecer token
     */
    public function set_token($token) {
        $this->token = $token;
    }
}
