<?php
/**
 * =================================================================================
 * MÉTODO PHP - vista_previa_factura (Para formulario form_offve001 en Scriptcase)
 * =================================================================================
 * Genera una representación PDF de alta fidelidad idéntica al formato oficial de
 * The Factory HKA pero en modo borrador ("VISTA PREVIA / PROFORMA"), permitiendo al
 * usuario validar visualmente el membrete, receptor, ODC, OPP, ítems con sus códigos,
 * desglose bimonetario y totales ANTES de realizar la emisión fiscal definitiva.
 * 
 * NO consume correlativo fiscal, NO llama a la API de The Factory HKA y NO altera
 * el estatus fiscal del borrador en la base de datos.
 * =================================================================================
 */

// 0. CONFIGURACIÓN DINÁMICA DE ENTORNO (tb_env)
if (!function_exists('of_http_get_hka_config')) {
    sc_include_library("sys", "of_http_lib", "of_http_lib.php", true, true);
}

$hka_cfg      = of_http_get_hka_config();
$api_base_url = $hka_cfg['url'];
$api_token    = $hka_cfg['token'];
$current_env  = $hka_cfg['env'];

// 1. OBTENER ID DE LA FACTURA
$factura_id = null;
if (isset({factura_id}) && !empty({factura_id})) {
    $factura_id = {factura_id};
} elseif (isset({id}) && !empty({id})) {
    // Si estamos dentro de form_offve001 donde {id} es la clave primaria de offve001
    $sql_f_id = "SELECT factura_id FROM offve001 WHERE id = " . {id};
    sc_lookup(ds_fid, $sql_f_id);
    if (!empty({ds_fid}) && {ds_fid} !== false) {
        $factura_id = {ds_fid}[0][0];
    }
}

if ($factura_id === null) {
    throw new Exception("No se pudo identificar la factura para generar la vista previa.");
}

// 2. CONSULTAR CABECERA DE LA FACTURA (ofcm020) Y CLIENTE (ofcm001)
$sql_cabecera = "SELECT 
                    f.id,
                    f.tipo_factura,
                    f.numero,
                    f.fecha,
                    f.moneda_trn,
                    c.id_fiscal,
                    c.nombre_fiscal,
                    c.direccion,
                    c.telf_1,
                    c.email_contacto_adm,
                    c.email_contacto_compras,
                    f.gravable_loc,
                    f.exento_loc,
                    f.monto_loc,
                    f.monto_iva_loc,
                    f.neto_loc,
                    f.doc_ref,
                    f.tasa_cambio,
                    f.monto_usd,
                    f.monto_iva_usd,
                    f.neto_usd,
                    f.gravable_usd,
                    f.exento_usd,
                    f.monto_eur,
                    f.monto_iva_eur,
                    f.neto_eur,
                    f.gravable_eur,
                    f.exento_eur,
                    f.tasa_cambio_eur,
                    
                    -- Datos Comerciales adicionales ERP (ofcm020)
                    f.politica_credito,
                    f.po_cliente,
                    f.ofgl005_codigo,
                    f.fecha_vencimiento
                 FROM ofcm020 f
                 INNER JOIN ofcm001 c ON f.cliente = c.codigo
                 WHERE f.id = " . $factura_id;

sc_lookup(ds_cabecera, $sql_cabecera);

if (empty({ds_cabecera}) || {ds_cabecera} === false) {
    throw new Exception("No se encontró la cabecera en ofcm020 para la factura ID: " . $factura_id);
}

$tipo_factura_db  = {ds_cabecera}[0][1];
$num_documento    = preg_replace('/[^0-9]/', '', {ds_cabecera}[0][2]);
$fecha_registro   = {ds_cabecera}[0][3];
$moneda_trn       = strtoupper(trim({ds_cabecera}[0][4]));
$id_fiscal        = {ds_cabecera}[0][5];
$nombre_fiscal    = {ds_cabecera}[0][6];
$direccion        = {ds_cabecera}[0][7];
$telefono         = {ds_cabecera}[0][8];
$email_adm        = {ds_cabecera}[0][9];
$email_compras    = {ds_cabecera}[0][10];

// Mapear campos comerciales del ERP (ofcm020)
$erp_politica_credito = isset({ds_cabecera}[0][29]) ? trim({ds_cabecera}[0][29]) : '';
$erp_po_cliente       = isset({ds_cabecera}[0][30]) ? trim({ds_cabecera}[0][30]) : '';
$erp_ofgl005          = isset({ds_cabecera}[0][31]) ? trim({ds_cabecera}[0][31]) : '';
$erp_fecha_venc       = isset({ds_cabecera}[0][32]) ? {ds_cabecera}[0][32] : null;

$tipo_doc_fiscal = "01";
if ($tipo_factura_db === 'C') {
    $tipo_doc_fiscal = "02";
} elseif ($tipo_factura_db === 'D') {
    $tipo_doc_fiscal = "03";
}

// 3. CONSULTAR REGISTRO FISCAL PRELIMINAR (offve001)
$sql_fiscal = "SELECT 
                   id, forma_pago, 
                   monto_subtotal, monto_gravable, monto_exento, monto_iva, monto_total, monto_igtf, monto_total_pagar,
                   moneda_trn, tasa_cambio, monto_subtotal_trn, monto_gravable_trn, monto_exento_trn, monto_iva_trn, monto_total_trn, monto_igtf_trn, monto_total_pagar_trn,
                   fecha_vencimiento, no_odc, no_opp
               FROM offve001 
               WHERE factura_id = " . $factura_id . " AND tipo_documento = '" . $tipo_doc_fiscal . "'";
sc_lookup(ds_fiscal, $sql_fiscal);

if (empty({ds_fiscal}) || {ds_fiscal} === false) {
    throw new Exception("No se encontró el registro preliminar en offve001 para la factura ID: " . $factura_id);
}

$factura_fiscal_id    = {ds_fiscal}[0][0];
$db_forma_pago        = trim({ds_fiscal}[0][1]);
$monto_subtotal_ves   = abs((float){ds_fiscal}[0][2]);
$monto_gravado_ves    = abs((float){ds_fiscal}[0][3]);
$monto_exento_ves     = abs((float){ds_fiscal}[0][4]);
$monto_iva            = abs((float){ds_fiscal}[0][5]);
$monto_total_con_iva  = abs((float){ds_fiscal}[0][6]);
$monto_igtf           = abs((float){ds_fiscal}[0][7]);
$monto_total_pagar    = abs((float){ds_fiscal}[0][8]);
$db_moneda_trn        = strtoupper(trim({ds_fiscal}[0][9]));
$db_tasa_cambio       = abs((float){ds_fiscal}[0][10]);
$monto_subtotal_trn   = abs((float){ds_fiscal}[0][11]);
$monto_gravable_trn   = abs((float){ds_fiscal}[0][12]);
$monto_exento_trn     = abs((float){ds_fiscal}[0][13]);
$monto_iva_trn        = abs((float){ds_fiscal}[0][14]);
$monto_total_trn      = abs((float){ds_fiscal}[0][15]);
$monto_igtf_trn       = abs((float){ds_fiscal}[0][16]);
$db_total_pagar_trn   = abs((float){ds_fiscal}[0][17]);
$db_fecha_vencimiento = isset({ds_fiscal}[0][18]) ? {ds_fiscal}[0][18] : null;
$db_no_odc            = isset({ds_fiscal}[0][19]) ? trim({ds_fiscal}[0][19]) : '';
$db_no_opp            = isset({ds_fiscal}[0][20]) ? trim({ds_fiscal}[0][20]) : '';

// 4. FORMA DE PAGO
$forma_pago_val = isset({forma_pago}) && !empty({forma_pago}) ? trim({forma_pago}) : $db_forma_pago;
if (empty($forma_pago_val) || $forma_pago_val === "NULL") {
    $forma_pago_val = "01"; // Default a Depósito / Transferencia si aún no se seleccionó
}

$forma_pago_desc = "Depósito en cuenta bancaria";
$sql_desc_fp = "SELECT descripcion FROM offve002 WHERE codigo = '" . addslashes($forma_pago_val) . "' AND activo = 1";
sc_lookup(ds_desc_fp, $sql_desc_fp);
if (!empty({ds_desc_fp}) && {ds_desc_fp} !== false) {
    $forma_pago_desc = {ds_desc_fp}[0][0];
}

// 5. FORMATEO DE CLIENTE Y FECHAS
$tipo_ident = "J";
$id_limpio = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $id_fiscal));
if (in_array(substr($id_limpio, 0, 1), array('V', 'E', 'J', 'G', 'P', 'C'))) {
    $tipo_ident = substr($id_limpio, 0, 1);
    $num_part = substr($id_limpio, 1);
} else {
    $num_part = $id_limpio;
}
$rif_cliente = $tipo_ident . "-" . $num_part;
$fecha_emision_fmt = date("d/m/Y", strtotime($fecha_registro));
$hora_emision_fmt = date("h:i:s a");

// 6. DETALLES DE ÍTEMS (offve011)
$sql_detalles = "SELECT 
                    indicador_bien_servicio, 
                    descripcion, 
                    cantidad, 
                    precio_unitario, 
                    precio_item, 
                    codigo_impuesto, 
                    tasa_iva, 
                    valor_iva, 
                    valor_total_item,
                    codigo_item
                 FROM offve011
                 WHERE factura_fiscal_id = $factura_fiscal_id
                 ORDER BY numero_linea ASC";
sc_lookup(ds_detalles, $sql_detalles);

if (empty({ds_detalles}) || {ds_detalles} === false) {
    throw new Exception("La factura preliminar en offve011 no contiene ítems para previsualizar.");
}

$detalles_items = [];
$linea_count = 1;

foreach ({ds_detalles} as $row) {
    $ind_bien_servicio = $row[0];
    $descripcion       = $row[1];
    $cantidad          = abs((float)$row[2]);
    $precio_un_loc     = abs((float)$row[3]);
    $total_loc         = abs((float)$row[4]);
    $codigo_imp        = $row[5];
    $tasa_iva_val      = abs((int)$row[6]);
    $iva_loc           = abs((float)$row[7]);
    $valor_total_item  = abs((float)$row[8]);
    $codigo_item       = isset($row[9]) ? trim($row[9]) : '';
    
    $item_data = [
        "NumeroLinea" => (string)$linea_count,
        "IndicadorBienoServicio" => $ind_bien_servicio,
        "Descripcion" => trim(preg_replace('/\s+/', ' ', $descripcion)),
        "Cantidad" => number_format($cantidad, 4, '.', ''),
        "UnidadMedida" => "UNI",
        "PrecioUnitario" => number_format($precio_un_loc, 4, '.', ''),
        "PrecioItem" => number_format($total_loc, 2, '.', ''),
        "CodigoImpuesto" => $codigo_imp,
        "TasaIVA" => (string)$tasa_iva_val,
        "ValorIVA" => number_format($iva_loc, 2, '.', ''),
        "ValorTotalItem" => number_format($valor_total_item, 2, '.', '')
    ];

    if (!empty($codigo_item)) {
        $item_data["codigoPLU"] = $codigo_item;
    }

    $detalles_items[] = $item_data;
    $linea_count++;
}

// 7. ENSAMBLAR JSON IDENTIFICACIÓN Y CABECERA
$moneda_fiscal = in_array($moneda_trn, ['USD', 'EUR']) ? $moneda_trn : 'VES';
$identificacion_doc = [
    "TipoDocumento" => $tipo_doc_fiscal,
    "NumeroDocumento" => $num_documento,
    "NumeroControl" => "BORRADOR / PROFORMA",
    "FechaEmision" => $fecha_emision_fmt,
    "HoraEmision" => $hora_emision_fmt,
    "TipoTransaccion" => "01",
    "Moneda" => $moneda_fiscal
];

// Inyectar FechaVencimiento si está disponible en offve001 o en ofcm020
$fecha_vencimiento_final = !empty($db_fecha_vencimiento) && $db_fecha_vencimiento !== '0000-00-00' && $db_fecha_vencimiento !== 'NULL' 
                           ? $db_fecha_vencimiento 
                           : (!empty($erp_fecha_venc) && $erp_fecha_venc !== '0000-00-00' && $erp_fecha_venc !== 'NULL' ? $erp_fecha_venc : null);

if (!empty($fecha_vencimiento_final)) {
    $identificacion_doc["FechaVencimiento"] = date("d/m/Y", strtotime($fecha_vencimiento_final));
}

// Determinar Condición de Pago desde politica_credito (1='Contado', demás='Crédito')
$pol_cred_raw = strtoupper(trim((string)$erp_politica_credito));
if ($pol_cred_raw === '1' || $pol_cred_raw === 'CONTADO') {
    $condicion_pago = "Contado";
} elseif (!empty($pol_cred_raw)) {
    $condicion_pago = "Crédito";
} else {
    if (!empty($fecha_vencimiento_final) && !empty($fecha_registro) && strtotime($fecha_vencimiento_final) > strtotime($fecha_registro)) {
        $condicion_pago = "Crédito";
    } else {
        $condicion_pago = "Contado";
    }
}
$identificacion_doc["TipoDePago"] = $condicion_pago;

$comprador = [
    "TipoIdentificacion" => $tipo_ident,
    "NumeroIdentificacion" => $rif_cliente,
    "RazonSocial" => trim($nombre_fiscal),
    "Direccion" => !empty($direccion) ? trim($direccion) : "CARACAS VENEZUELA",
    "Telefono" => trim($telefono),
    "Email" => !empty($email_adm) ? trim($email_adm) : (!empty($email_compras) ? trim($email_compras) : '')
];

$totales = [
    "Subtotal" => number_format($monto_subtotal_ves, 2, '.', ''),
    "MontoGravadoTotal" => number_format($monto_gravado_ves, 2, '.', ''),
    "MontoExentoTotal" => number_format($monto_exento_ves, 2, '.', ''),
    "TotalIVA" => number_format($monto_iva, 2, '.', ''),
    "TotalAPagar" => number_format($monto_total_pagar, 2, '.', ''),
    "TotalFactura" => number_format($monto_total_con_iva, 2, '.', ''),
    "FormasPago" => [
        [
            "Descripcion" => $forma_pago_desc,
            "Forma" => $forma_pago_val,
            "Monto" => number_format($monto_total_pagar, 2, '.', ''),
            "Moneda" => "VES",
            "TipoCambio" => "0.0000"
        ]
    ]
];

$encabezado = [
    "IdentificacionDocumento" => $identificacion_doc,
    "Comprador" => $comprador,
    "Totales" => $totales
];

// ODC, CC/OPP y Condición de Pago
$info_adicional = [];

// 1. Condición de Pago
$info_adicional[] = [
    "campo" => "Condición de Pago",
    "Campo" => "Condición de Pago",
    "valor" => $condicion_pago,
    "Valor" => $condicion_pago
];

// 2. Nro. de ODC (Resolución en cascada: Formulario -> offve001 -> ofcm020.po_cliente)
$no_odc_final = (isset({no_odc}) && !empty({no_odc})) ? trim({no_odc}) : (!empty($db_no_odc) ? $db_no_odc : $erp_po_cliente);
if (!empty($no_odc_final) && strtoupper($no_odc_final) !== 'N/A' && strtoupper($no_odc_final) !== 'NA' && strtoupper($no_odc_final) !== 'NULL') {
    // Tag "ODC" requerido por la plantilla oficial de The Factory HKA
    $info_adicional[] = [
        "campo" => "ODC",
        "Campo" => "ODC",
        "valor" => $no_odc_final,
        "Valor" => $no_odc_final
    ];
    // Tag descriptivo complementario
    $info_adicional[] = [
        "campo" => "Nro. de ODC",
        "Campo" => "Nro. de ODC",
        "valor" => $no_odc_final,
        "Valor" => $no_odc_final
    ];
}

// 3. CC/OPP (Resolución en cascada: Formulario -> offve001 -> ofcm020.ofgl005_codigo)
$no_opp_final = (isset({no_opp}) && !empty({no_opp})) ? trim({no_opp}) : (!empty($db_no_opp) ? $db_no_opp : $erp_ofgl005);
if (!empty($no_opp_final) && strtoupper($no_opp_final) !== 'N/A' && strtoupper($no_opp_final) !== 'NA' && strtoupper($no_opp_final) !== 'NULL') {
    // Tag "OPP" requerido por la plantilla oficial de The Factory HKA
    $info_adicional[] = [
        "campo" => "OPP",
        "Campo" => "OPP",
        "valor" => $no_opp_final,
        "Valor" => $no_opp_final
    ];
    // Tag descriptivo complementario
    $info_adicional[] = [
        "campo" => "CC/OPP",
        "Campo" => "CC/OPP",
        "valor" => $no_opp_final,
        "Valor" => $no_opp_final
    ];
}

if (!empty($info_adicional)) {
    $encabezado["InfoAdicional"] = $info_adicional;
}

// Multidivisa si aplica (USD o EUR)
if (in_array($moneda_trn, array('USD', 'EUR')) && $db_tasa_cambio > 0) {
    $encabezado["TotalesOtraMoneda"] = [
        "Moneda" => $moneda_trn,
        "moneda" => $moneda_trn,
        "TipoCambio" => number_format($db_tasa_cambio, 4, '.', ''),
        "tipoCambio" => number_format($db_tasa_cambio, 4, '.', ''),
        "Subtotal" => number_format($monto_subtotal_trn, 2, '.', ''),
        "subtotal" => number_format($monto_subtotal_trn, 2, '.', ''),
        "MontoGravadoTotal" => number_format($monto_gravable_trn, 2, '.', ''),
        "montoGravadoTotal" => number_format($monto_gravable_trn, 2, '.', ''),
        "MontoExentoTotal" => number_format($monto_exento_trn, 2, '.', ''),
        "montoExentoTotal" => number_format($monto_exento_trn, 2, '.', ''),
        "TotalIVA" => number_format($monto_iva_trn, 2, '.', ''),
        "totalIVA" => number_format($monto_iva_trn, 2, '.', ''),
        "TotalAPagar" => number_format($db_total_pagar_trn, 2, '.', ''),
        "totalAPagar" => number_format($db_total_pagar_trn, 2, '.', ''),
        "MontoTotalConIVA" => number_format($monto_total_trn, 2, '.', ''),
        "montoTotalConIVA" => number_format($monto_total_trn, 2, '.', ''),
        "totalFactura" => number_format($monto_total_trn, 2, '.', '')
    ];
}

$payload = [
    "documentoElectronico" => [
        "Encabezado" => $encabezado,
        "DetallesItems" => $detalles_items,
        "InfoAdicional" => $info_adicional,
        "infoAdicional" => $info_adicional
    ]
];

// 8. PETICIÓN AL MICROSERVICIO LOCAL PARA GENERAR EL PDF BINARIO
$url_preview = $api_base_url . "/api/v1/vista-previa/pdf?format=binary";
$json_data   = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init($url_preview);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/pdf',
    'Authorization: Bearer ' . $api_token
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$pdf_binary = curl_exec($ch);
$http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err   = curl_error($ch);
curl_close($ch);

if (!empty($curl_err)) {
    throw new Exception("Error de conexión al microservicio de vista previa: " . $curl_err);
}

if ($http_code !== 200 || empty($pdf_binary)) {
    $err_detalle = $pdf_binary ? substr($pdf_binary, 0, 300) : "Respuesta vacía";
    throw new Exception("El microservicio retornó código HTTP $http_code. Detalle: " . $err_detalle);
}

// 9. ENVIAR ARCHIVO AL NAVEGADOR FORZANDO LA DESCARGA
if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="vista_previa_' . $num_documento . '.pdf"');
header('Content-Length: ' . strlen($pdf_binary));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $pdf_binary;
exit;
