<?php
/**
 * Clase para generar XML de boletas electrónicas
 */

if (!defined('ABSPATH')) {
    exit;
}

class SII_XML_Generator {

    private $api;
    private $config;

    public function __construct($api = null) {
        $this->api = $api;
        $this->config = [
            'rut_emisor' => get_option('sii_rut_emisor'),
            'razon_social' => get_option('sii_razon_social'),
            'giro' => get_option('sii_giro'),
            'direccion' => get_option('sii_direccion'),
            'comuna' => get_option('sii_comuna'),
            'ciudad' => get_option('sii_ciudad'),
            'acteco' => get_option('sii_acteco'),
            'fch_resol' => get_option('sii_fch_resol'),
            'nro_resol' => get_option('sii_nro_resol', '0'),
        ];
    }

    /**
     * Generar boleta completa para envío al SII
     */
    public function generate_boleta($folio, $items, $receptor, $caf_data, $referencia = null) {
        $fecha = date('Y-m-d');
        $timestamp = date('Y-m-d\TH:i:s');

        // Calcular montos
        $monto_total = 0;
        $detalle_xml = '';
        $nlin = 1;

        foreach ($items as $item) {
            $subtotal = $item['qty'] * $item['precio'];
            $monto_total += $subtotal;

            $detalle_xml .= '<Detalle>' .
                '<NroLinDet>' . $nlin++ . '</NroLinDet>' .
                '<NmbItem>' . htmlspecialchars(substr($item['nombre'], 0, 80)) . '</NmbItem>' .
                '<QtyItem>' . $item['qty'] . '</QtyItem>' .
                '<PrcItem>' . $item['precio'] . '</PrcItem>' .
                '<MontoItem>' . $subtotal . '</MontoItem>' .
                '</Detalle>';
        }

        // Precios incluyen IVA
        $monto_neto = round($monto_total / 1.19);
        $monto_iva = $monto_total - $monto_neto;

        // Generar TED
        $ted = $this->generate_ted($folio, $fecha, $monto_total, $items[0]['nombre'], $caf_data);

        // Documento
        $doc_id = 'F' . $folio . 'T39';
        $documento = '<Documento ID="' . $doc_id . '">' .
            '<Encabezado>' .
            '<IdDoc>' .
            '<TipoDTE>39</TipoDTE>' .
            '<Folio>' . $folio . '</Folio>' .
            '<FchEmis>' . $fecha . '</FchEmis>' .
            '<IndServicio>3</IndServicio>' .
            '</IdDoc>' .
            '<Emisor>' .
            '<RUTEmisor>' . $this->config['rut_emisor'] . '</RUTEmisor>' .
            '<RznSocEmisor>' . htmlspecialchars($this->config['razon_social']) . '</RznSocEmisor>' .
            '<GiroEmisor>' . htmlspecialchars(substr($this->config['giro'], 0, 80)) . '</GiroEmisor>' .
            '<Acteco>' . $this->config['acteco'] . '</Acteco>' .
            '<DirOrigen>' . htmlspecialchars($this->config['direccion']) . '</DirOrigen>' .
            '<CmnaOrigen>' . $this->config['comuna'] . '</CmnaOrigen>' .
            '<CiudadOrigen>' . $this->config['ciudad'] . '</CiudadOrigen>' .
            '</Emisor>' .
            '<Receptor>' .
            '<RUTRecep>' . $receptor['rut'] . '</RUTRecep>' .
            '<RznSocRecep>' . htmlspecialchars(substr($receptor['razon_social'], 0, 100)) . '</RznSocRecep>' .
            '</Receptor>' .
            '<Totales>' .
            '<MntNeto>' . $monto_neto . '</MntNeto>' .
            '<TasaIVA>19</TasaIVA>' .
            '<IVA>' . $monto_iva . '</IVA>' .
            '<MntTotal>' . $monto_total . '</MntTotal>' .
            '</Totales>' .
            '</Encabezado>' .
            $detalle_xml;

        // Agregar referencia si existe (para set de pruebas)
        if ($referencia) {
            $documento .= '<Referencia>' .
                '<NroLinRef>1</NroLinRef>' .
                '<TpoDocRef>' . $referencia['tipo'] . '</TpoDocRef>' .
                '<RazonRef>' . $referencia['razon'] . '</RazonRef>' .
                '</Referencia>';
        }

        $documento .= $ted . '</Documento>';

        // Firmar documento
        if ($this->api) {
            $doc_firmado = $this->api->sign_xml($documento, '#' . $doc_id);
            if (is_wp_error($doc_firmado)) {
                return $doc_firmado;
            }
        } else {
            $doc_firmado = $documento;
        }

        // DTE wrapper
        $dte = '<DTE version="1.0">' . $doc_firmado . '</DTE>';

        // SetDTE
        $set_id = 'SetDoc';
        $rut_envia = get_option('sii_rut_envia', $this->config['rut_emisor']);
        $set_dte = '<SetDTE ID="' . $set_id . '">' .
            '<Caratula version="1.0">' .
            '<RutEmisor>' . $this->config['rut_emisor'] . '</RutEmisor>' .
            '<RutEnvia>' . $rut_envia . '</RutEnvia>' .
            '<FchResol>' . $this->config['fch_resol'] . '</FchResol>' .
            '<NroResol>' . $this->config['nro_resol'] . '</NroResol>' .
            '<TmstFirmaEnv>' . $timestamp . '</TmstFirmaEnv>' .
            '<SubTotDTE><TpoDTE>39</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>' .
            '</Caratula>' .
            $dte .
            '</SetDTE>';

        // Firmar SetDTE
        if ($this->api) {
            $set_firmado = $this->api->sign_xml($set_dte, '#' . $set_id);
            if (is_wp_error($set_firmado)) {
                return $set_firmado;
            }
        } else {
            $set_firmado = $set_dte;
        }

        // EnvioBOLETA
        $envio_boleta = '<?xml version="1.0" encoding="ISO-8859-1"?>' .
            '<EnvioBOLETA xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.0">' .
            $set_firmado .
            '</EnvioBOLETA>';

        return [
            'xml' => $envio_boleta,
            'monto_neto' => $monto_neto,
            'monto_iva' => $monto_iva,
            'monto_total' => $monto_total,
        ];
    }

    /**
     * Generar TED (Timbre Electrónico)
     */
    private function generate_ted($folio, $fecha, $monto, $item1, $caf_data) {
        // Parsear CAF
        $caf_xml = simplexml_load_string($caf_data['xml_caf']);

        // Generar CAF limpio sin saltos de línea
        $caf_content = '';
        foreach ($caf_xml->CAF->children() as $child) {
            $child_xml = $child->asXML();
            $child_xml = preg_replace('/>\s+</', '><', $child_xml);
            $child_xml = preg_replace('/>\s+/', '>', $child_xml);
            $child_xml = preg_replace('/\s+</', '<', $child_xml);
            $caf_content .= $child_xml;
        }
        $caf_content = '<CAF version="1.0">' . $caf_content . '</CAF>';

        $dd = '<DD>' .
            '<RE>' . $this->config['rut_emisor'] . '</RE>' .
            '<TD>39</TD>' .
            '<F>' . $folio . '</F>' .
            '<FE>' . $fecha . '</FE>' .
            '<RR>66666666-6</RR>' .
            '<RSR>CONSUMIDOR FINAL</RSR>' .
            '<MNT>' . $monto . '</MNT>' .
            '<IT1>' . htmlspecialchars(substr($item1, 0, 40)) . '</IT1>' .
            $caf_content .
            '<TSTED>' . date('Y-m-d\TH:i:s') . '</TSTED>' .
            '</DD>';

        // Firmar con clave del CAF
        $caf_key = "-----BEGIN RSA PRIVATE KEY-----\n" .
                   chunk_split($caf_data['clave_privada'], 64) .
                   "-----END RSA PRIVATE KEY-----";

        openssl_sign($dd, $sig, $caf_key, OPENSSL_ALGO_SHA1);
        $frmt = base64_encode($sig);

        return '<TED version="1.0">' . $dd . '<FRMT algoritmo="SHA1withRSA">' . $frmt . '</FRMT></TED>';
    }

    /**
     * Generar RVD (Resumen Ventas Diarias)
     */
    public function generate_rvd($boletas) {
        $fecha = date('Y-m-d');
        $timestamp = date('Y-m-d\TH:i:s');
        $sec_envio = date('YmdHis');

        $total_neto = 0;
        $total_iva = 0;
        $total_total = 0;

        foreach ($boletas as $boleta) {
            $total_neto += $boleta['monto_neto'];
            $total_iva += $boleta['monto_iva'];
            $total_total += $boleta['monto_total'];
        }

        $cant_boletas = count($boletas);
        $doc_id = 'RVD_' . $fecha;

        $rvd = '<?xml version="1.0" encoding="ISO-8859-1"?>' .
            '<ConsumoFolios xmlns="http://www.sii.cl/SiiDte" version="1.0">' .
            '<DocumentoConsumoFolios ID="' . $doc_id . '">' .
            '<Caratula>' .
            '<RutEmisor>' . $this->config['rut_emisor'] . '</RutEmisor>' .
            '<RutEnvia>' . $this->config['rut_emisor'] . '</RutEnvia>' .
            '<FchResol>' . $this->config['fch_resol'] . '</FchResol>' .
            '<NroResol>' . $this->config['nro_resol'] . '</NroResol>' .
            '<FchInicio>' . $fecha . '</FchInicio>' .
            '<FchFinal>' . $fecha . '</FchFinal>' .
            '<SecEnvio>' . $sec_envio . '</SecEnvio>' .
            '<TmstFirmaEnv>' . $timestamp . '</TmstFirmaEnv>' .
            '</Caratula>' .
            '<Resumen>' .
            '<TipoDocumento>39</TipoDocumento>' .
            '<MntNeto>' . $total_neto . '</MntNeto>' .
            '<MntIva>' . $total_iva . '</MntIva>' .
            '<TasaIVA>19</TasaIVA>' .
            '<MntTotal>' . $total_total . '</MntTotal>' .
            '<FoliosEmitidos>' . $cant_boletas . '</FoliosEmitidos>' .
            '<FoliosAnulados>0</FoliosAnulados>' .
            '<FoliosUtilizados>' . $cant_boletas . '</FoliosUtilizados>' .
            '</Resumen>' .
            '</DocumentoConsumoFolios>' .
            '</ConsumoFolios>';

        if ($this->api) {
            return $this->api->sign_xml($rvd, '#' . $doc_id);
        }

        return $rvd;
    }
}
