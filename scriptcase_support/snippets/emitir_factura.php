<?php
/**
 * =================================================================================
 * SNIPPET DE INTEGRACIÓN - EMISIÓN DESDE SCRIPTCASE CON MULTIDIVISA (VEB/USD/EUR)
 * =================================================================================
 * Este código debe colocarse en el Evento correspondiente (botón PHP) de tu
 * aplicación de Scriptcase.
 * 
 * Requiere la variable local {id_factura} conteniendo el ID de la tabla ofcm020.
 * =================================================================================
 */
// CONFIGURACIÓN DE INTEGRACIÓN
$api_base_url = "http://localhost:8000"; // Cambiar al host/puerto de producción si es necesario

// IMPORTAR LIBRERÍA HTTP PERSONALIZADA (of_http_lib)
sc_include_library("sys", "of_http_lib", "of_http_lib.php", true, true);

// 1. CONSULTAR CABECERA DE LA FACTURA (ofcm020) Y CLIENTE (ofcm001)
$sql_cabecera = "SELECT 
                    f.id,
                    f.tipo_factura, -- 'F'=Factura, 'C'=Nota Crédito, 'D'=Nota Débito
                    f.numero,
                    f.fecha,
                    f.moneda_trn,    -- Moneda de transacción (VEB, USD, EUR)
                    c.id_fiscal,     -- RIF/Cédula (ej. J000723060)
                    c.nombre_fiscal, -- Razón Social
                    c.direccion,
                    c.telf_1,
                    c.email_contacto_adm,
                    c.email_contacto_compras,
                    
                    -- Montos en Moneda Local (VES)
                    f.gravable_loc,
                    f.exento_loc,
                    f.monto_loc,
                    f.monto_iva_loc,
                    f.neto_loc,
                    
                    f.doc_ref,       -- Referencia para Notas de Crédito/Débito
                    
                    -- Tasas y Montos en USD
                    f.tasa_cambio,   
                    f.monto_usd,     
                    f.monto_iva_usd, 
                    f.neto_usd,      
                    f.gravable_usd,  
                    f.exento_usd,    
                    
                    -- Montos en EUR
                    f.monto_eur,     
                    f.monto_iva_eur, 
                    f.neto_eur,      
                    f.gravable_eur,  
                    f.exento_eur,    
                    f.tasa_cambio_eur,
                    
                    -- IGTF si aplica
                    f.igtf_loc,
                    f.igtf_usd
                 FROM ofcm020 f
                 INNER JOIN ofcm001 c ON f.cliente = c.codigo
                 WHERE f.id = " . {id_factura};

sc_lookup(ds_cabecera, $sql_cabecera);

if (empty({ds_cabecera}) || {ds_cabecera} === false) {
    sc_error_message("No se encontró la cabecera de la factura en ofcm020 con ID: " . {id_factura});
    return;
}

// Mapear campos de cabecera a variables PHP
$factura_id       = {ds_cabecera}[0][0];
$tipo_factura_db  = {ds_cabecera}[0][1];
$num_documento    = {ds_cabecera}[0][2];
$fecha_registro   = {ds_cabecera}[0][3];
$moneda_trn       = strtoupper(trim({ds_cabecera}[0][4])); // Moneda de la Transacción (VEB/USD/EUR)
$id_fiscal        = {ds_cabecera}[0][5];
$nombre_fiscal    = {ds_cabecera}[0][6];
$direccion        = {ds_cabecera}[0][7];
$telefono         = {ds_cabecera}[0][8];
$email_adm        = {ds_cabecera}[0][9];
$email_compras    = {ds_cabecera}[0][10];

// Montos en Moneda Local (Siempre VES)
$monto_gravado    = {ds_cabecera}[0][11];
$monto_exento     = {ds_cabecera}[0][12];
$monto_subtotal   = {ds_cabecera}[0][13];
$monto_iva        = {ds_cabecera}[0][14];
$monto_total      = {ds_cabecera}[0][15];
$doc_ref          = {ds_cabecera}[0][16];

// VALIDACIÓN DE MONEDAS SOPORTADAS
if (!in_array($moneda_trn, array('VEB', 'VES', 'USD', 'EUR'))) {
    sc_error_message("Error: La moneda '" . $moneda_trn . "' no está soportada actualmente. Solo se permiten facturas en VEB, USD y EUR.");
    return;
}

// Formatear tipo de documento fiscal
$tipo_doc_fiscal = "01"; // Factura por defecto
if ($tipo_factura_db === 'C') {
    $tipo_doc_fiscal = "02"; // Nota de Crédito
} elseif ($tipo_factura_db === 'D') {
    $tipo_doc_fiscal = "03"; // Nota de Débito
}

// Formatear fecha y hora al estándar de TFHKA
$fecha_emision = date("d/m/Y", strtotime($fecha_registro));
$hora_emision  = date("h:i:s a", strtotime($fecha_registro));

// Normalizar RIF / Cédula del Cliente (ej: J000723060 -> J-00072306-0)
$raw_rif = trim($id_fiscal);
$tipo_ident = strtoupper(substr($raw_rif, 0, 1));
$num_part = substr($raw_rif, 1);
$num_part = str_replace('.', '', $num_part); // Quitar puntos de miles de la Cédula

if (strlen($num_part) >= 9) {
    $cuerpo_rif = substr($num_part, 0, -1);
    $digito_ver = substr($num_part, -1);
    $rif_cliente = $tipo_ident . "-" . $cuerpo_rif . "-" . $digito_ver;
} else {
    $rif_cliente = $tipo_ident . "-" . $num_part;
}

// Consolidar teléfono y correo del cliente
$tel_cliente = trim($telefono);
$correo_cliente = !empty($email_adm) ? trim($email_adm) : (!empty($email_compras) ? trim($email_compras) : '');

// 2. OBTENER DETALLE DE ÍTEMS DE LA FACTURA (ofcm021)
// Nota: en detalles extraemos precio_un_loc y total_loc (los montos locales convertidos a VES)
// ya que la lista de ítems en el JSON de TFHKA debe ir siempre en moneda nacional.
$sql_detalles = "SELECT 
                    d.id,
                    d.ofin009_id, -- Código del ítem
                    d.descripcion,
                    d.cantidad,
                    d.precio_un_loc,
                    d.total_loc,
                    d.iva_loc,
                    d.gravable_loc,
                    d.exento_loc,
                    i.tipo,        -- Para determinar bien o servicio
                    d.total_usd,
                    d.gravable_usd,
                    d.total_eur,
                    d.gravable_eur
                 FROM ofcm021 d
                 LEFT JOIN ofin009 i ON d.ofin009_id = i.codigo
                 WHERE d.ofcm020_id = " . $factura_id . "
                 ORDER BY d.id ASC";

sc_lookup(ds_detalles, $sql_detalles);

if (empty({ds_detalles}) || {ds_detalles} === false) {
    sc_error_message("La factura en ofcm021 debe contener al menos un detalle o ítem.");
    return;
}

$detalles_items = [];
$linea_count = 1;

$has_igtf = false;
$igtf_amount_loc = 0.0;
$igtf_base_loc = 0.0;
$igtf_exento_loc = 0.0;

$igtf_amount_usd = 0.0;
$igtf_base_usd = 0.0;

$igtf_amount_eur = 0.0;
$igtf_base_eur = 0.0;

foreach ({ds_detalles} as $row) {
    $item_id         = $row[0];
    $codigo_item     = $row[1];
    $descripcion     = $row[2];
    $cantidad        = (float)$row[3];
    $precio_un_loc   = (float)$row[4];
    $total_loc       = (float)$row[5];
    $iva_loc         = (float)$row[6];
    $gravable_loc    = (float)$row[7];
    $exento_loc      = (float)$row[8];
    $item_tipo       = $row[9];
    $total_usd       = (float)$row[10];
    $gravable_usd    = (float)$row[11];
    $total_eur       = (float)$row[12];
    $gravable_eur    = (float)$row[13];
    
    // Si la línea es de IGTF, la extraemos de los detalles y la consolidamos en los totales
    if (strpos($codigo_item, '*IGT') === 0) {
        $has_igtf = true;
        
        $igtf_amount_loc += $total_loc;
        $igtf_base_loc   += $gravable_loc;
        $igtf_exento_loc += $exento_loc;
        
        $igtf_amount_usd += $total_usd;
        $igtf_base_usd   += $gravable_usd;
        
        $igtf_amount_eur += $total_eur;
        $igtf_base_eur   += $gravable_eur;
        
        continue; // Excluir de DetallesItems
    }
    
    // Determinar si es Bien o Servicio (tipo = 'S' -> Servicio (2), otro -> Bien (1))
    $ind_bien_servicio = ($item_tipo === 'S') ? '2' : '1';
    
    // Calcular alícuota de IVA dinámicamente basándonos en los montos calculados por el ERP
    if ($gravable_loc > 0 && $iva_loc > 0) {
        $tasa_iva_val = round(($iva_loc / $gravable_loc) * 100);
        $codigo_imp = "G"; // Tasa General (16%)
    } else {
        $tasa_iva_val = 0;
        $codigo_imp = "E"; // Exento
    }
    
    $detalles_items[] = [
        "NumeroLinea" => (string)$linea_count,
        "IndicadorBienoServicio" => $ind_bien_servicio,
        "Descripcion" => trim(preg_replace('/\s+/', ' ', $descripcion)), // Sanitizar saltos de línea
        "Cantidad" => number_format($cantidad, 2, '.', ''),
        "UnidadMedida" => "UNI",
        "PrecioUnitario" => number_format($precio_un_loc, 2, '.', ''),
        "PrecioItem" => number_format($total_loc, 2, '.', ''),
        "CodigoImpuesto" => $codigo_imp,
        "TasaIVA" => (string)$tasa_iva_val,
        "ValorIVA" => number_format($iva_loc, 2, '.', ''),
        "ValorTotalItem" => number_format($total_loc + $iva_loc, 2, '.', '')
    ];
    $linea_count++;
}



// 3. AJUSTAR LOS TOTALES CONSOLIDANDO EL IGTF SI CORRESPONDE (EN BOLÍVARES)
$monto_gravado_ves   = (float)$monto_gravado - $igtf_base_loc;
$monto_exento_ves    = (float)$monto_exento - $igtf_exento_loc;
$monto_subtotal_ves  = (float)$monto_subtotal - $igtf_amount_loc;
$monto_total_con_iva = (float)$monto_total - $igtf_amount_loc; // montoTotalConIVA (excluye IGTF)
$monto_total_pagar   = (float)$monto_total; // totalAPagar (incluye IGTF)

$impuestos_subtotal_ves = [];
if ($monto_gravado_ves > 0) {
    $impuestos_subtotal_ves[] = [
        "CodigoTotalImp" => "G",
        "AlicuotaImp" => "16.00",
        "BaseImponibleImp" => number_format($monto_gravado_ves, 2, '.', ''),
        "ValorTotalImp" => number_format((float)$monto_iva, 2, '.', '')
    ];
}

// Inyectar IGTF en VES si existe
if ($has_igtf && $igtf_amount_loc > 0) {
    $base_igtf_calculada = ($igtf_base_loc > 0) ? $igtf_base_loc : round($igtf_amount_loc / 0.03, 2);
    $impuestos_subtotal_ves[] = [
        "CodigoTotalImp" => "IGTF",
        "AlicuotaImp" => "3.00",
        "BaseImponibleImp" => number_format($base_igtf_calculada, 2, '.', ''),
        "ValorTotalImp" => number_format($igtf_amount_loc, 2, '.', '')
    ];
}

// 4. CONSTRUIR NODO DE COMPRADOR DINÁMICO
$comprador_payload = [
    "TipoIdentificacion" => $tipo_ident,
    "NumeroIdentificacion" => $rif_cliente,
    "RazonSocial" => $nombre_fiscal,
    "Direccion" => !empty($direccion) ? $direccion : "CARACAS VENEZUELA",
    "Pais" => "VE"
];

if (!empty($correo_cliente)) {
    $comprador_payload["Correo"] = [$correo_cliente];
    $comprador_payload["Telefono"] = [!empty($tel_cliente) ? $tel_cliente : "0212-000-0000"];
} elseif (!empty($tel_cliente)) {
    $comprador_payload["Telefono"] = [$tel_cliente];
}

// 5. ESTRUCTURAR EL PAYLOAD JSON BÁSICO (EN BOLÍVARES)
$payload = [
    "documentoElectronico" => [
        "Encabezado" => [
            "IdentificacionDocumento" => [
                "TipoDocumento" => $tipo_doc_fiscal,
                "NumeroDocumento" => (string)$num_documento,
                "FechaEmision" => $fecha_emision,
                "HoraEmision" => $hora_emision,
                "Serie" => "",
                "TipoDeVenta" => "Interna",
                "Moneda" => "VES" // La moneda base del ticket fiscal es siempre VES
            ],
            "Comprador" => $comprador_payload,
            "Totales" => [
                "NroItems" => (string)count($detalles_items),
                "MontoGravadoTotal" => number_format($monto_gravado_ves, 2, '.', ''),
                "MontoExentoTotal" => number_format($monto_exento_ves, 2, '.', ''),
                "Subtotal" => number_format($monto_subtotal_ves, 2, '.', ''),
                "TotalIVA" => number_format((float)$monto_iva, 2, '.', ''),
                "MontoTotalConIVA" => number_format($monto_total_con_iva, 2, '.', ''),
                "TotalAPagar" => number_format($monto_total_pagar, 2, '.', ''),
                "ImpuestosSubtotal" => $impuestos_subtotal_ves,
                "FormasPago" => [
                    [
                        "Descripcion" => "Efectivo",
                        "Fecha" => $fecha_emision,
                        "Forma" => "01",
                        "Monto" => number_format($monto_total_pagar, 2, '.', ''),
                        "Moneda" => "VES",
                        "TipoCambio" => "0.0000"
                    ]
                ]
            ]
        ],
        "DetallesItems" => $detalles_items
    ]
];

// 6. INYECTAR MULTIDIVISA (SI moneda_trn ES USD O EUR)
$totales_otra_moneda = null;

if ($moneda_trn === 'USD') {
    $tasa_cambio      = (float){ds_cabecera}[0][17];
    $monto_subtotal_u = (float){ds_cabecera}[0][18];
    $monto_iva_u      = (float){ds_cabecera}[0][19];
    $monto_total_u    = (float){ds_cabecera}[0][20];
    $monto_gravado_u  = (float){ds_cabecera}[0][21];
    $monto_exento_u   = (float){ds_cabecera}[0][22];
    
    // Ajustar montos USD restando el IGTF
    $monto_gravado_u_net   = $monto_gravado_u - $igtf_base_usd;
    $monto_exento_u_net    = $monto_exento_u; 
    $monto_subtotal_u_net  = $monto_subtotal_u - $igtf_amount_usd;
    $monto_total_u_con_iva = $monto_total_u - $igtf_amount_usd;
    $monto_total_u_pagar   = $monto_total_u;
    
    $impuestos_subtotal_otra = [];
    if ($monto_gravado_u_net > 0) {
        $impuestos_subtotal_otra[] = [
            "CodigoTotalImp" => "G",
            "AlicuotaImp" => "16.00",
            "BaseImponibleImp" => number_format($monto_gravado_u_net, 2, '.', ''),
            "ValorTotalImp" => number_format($monto_iva_u, 2, '.', '')
        ];
    }
    
    // Inyectar IGTF en USD si existe
    if ($has_igtf && $igtf_amount_usd > 0) {
        $base_igtf_calculada_usd = ($igtf_base_usd > 0) ? $igtf_base_usd : round($igtf_amount_usd / 0.03, 2);
        $impuestos_subtotal_otra[] = [
            "CodigoTotalImp" => "IGTF",
            "AlicuotaImp" => "3.00",
            "BaseImponibleImp" => number_format($base_igtf_calculada_usd, 2, '.', ''),
            "ValorTotalImp" => number_format($igtf_amount_usd, 2, '.', '')
        ];
    }
    
    $totales_otra_moneda = [
        "moneda" => "USD",
        "tipoCambio" => number_format($tasa_cambio, 4, '.', ''),
        "montoGravadoTotal" => number_format($monto_gravado_u_net, 2, '.', ''),
        "montoExentoTotal" => number_format($monto_exento_u_net, 2, '.', ''),
        "MontoPercibidoTotal" => "0.00",
        "subtotal" => number_format($monto_subtotal_u_net, 2, '.', ''),
        "totalAPagar" => number_format($monto_total_u_pagar, 2, '.', ''),
        "totalIVA" => number_format($monto_iva_u, 2, '.', ''),
        "montoTotalIVAyOTI" => number_format($monto_total_u_pagar, 2, '.', ''),
        "MontoTotalOTI" => "0.00",
        "montoTotalConIVA" => number_format($monto_total_u_con_iva, 2, '.', ''),
        "totalDescuento" => "0.00",
        "ImpuestosSubtotal" => $impuestos_subtotal_otra
    ];
} elseif ($moneda_trn === 'EUR') {
    $monto_subtotal_e = (float){ds_cabecera}[0][23];
    $monto_iva_e      = (float){ds_cabecera}[0][24];
    $monto_total_e    = (float){ds_cabecera}[0][25];
    $monto_gravado_e  = (float){ds_cabecera}[0][26];
    $monto_exento_e   = (float){ds_cabecera}[0][27];
    $tasa_cambio_e    = (float){ds_cabecera}[0][28];
    
    // Ajustar montos EUR restando el IGTF
    $monto_gravado_e_net   = $monto_gravado_e - $igtf_base_eur;
    $monto_exento_e_net    = $monto_exento_e;
    $monto_subtotal_e_net  = $monto_subtotal_e - $igtf_amount_eur;
    $monto_total_e_con_iva = $monto_total_e - $igtf_amount_eur;
    $monto_total_e_pagar   = $monto_total_e;
    
    $impuestos_subtotal_otra = [];
    if ($monto_gravado_e_net > 0) {
        $impuestos_subtotal_otra[] = [
            "CodigoTotalImp" => "G",
            "AlicuotaImp" => "16.00",
            "BaseImponibleImp" => number_format($monto_gravado_e_net, 2, '.', ''),
            "ValorTotalImp" => number_format($monto_iva_e, 2, '.', '')
        ];
    }
    
    // Inyectar IGTF en EUR si existe
    if ($has_igtf && $igtf_amount_eur > 0) {
        $base_igtf_calculada_eur = ($igtf_base_eur > 0) ? $igtf_base_eur : round($igtf_amount_eur / 0.03, 2);
        $impuestos_subtotal_otra[] = [
            "CodigoTotalImp" => "IGTF",
            "AlicuotaImp" => "3.00",
            "BaseImponibleImp" => number_format($base_igtf_calculada_eur, 2, '.', ''),
            "ValorTotalImp" => number_format($igtf_amount_eur, 2, '.', '')
        ];
    }
    
    $totales_otra_moneda = [
        "moneda" => "EUR",
        "tipoCambio" => number_format($tasa_cambio_e, 4, '.', ''),
        "montoGravadoTotal" => number_format($monto_gravado_e_net, 2, '.', ''),
        "montoExentoTotal" => number_format($monto_exento_e_net, 2, '.', ''),
        "MontoPercibidoTotal" => "0.00",
        "subtotal" => number_format($monto_subtotal_e_net, 2, '.', ''),
        "totalAPagar" => number_format($monto_total_e_pagar, 2, '.', ''),
        "totalIVA" => number_format($monto_iva_e, 2, '.', ''),
        "montoTotalIVAyOTI" => number_format($monto_total_e_pagar, 2, '.', ''),
        "MontoTotalOTI" => "0.00",
        "montoTotalConIVA" => number_format($monto_total_e_con_iva, 2, '.', ''),
        "totalDescuento" => "0.00",
        "ImpuestosSubtotal" => $impuestos_subtotal_otra
    ];
}

// Inyectar el nodo TotalesOtraMoneda en Encabezado si corresponde
if ($totales_otra_moneda !== null) {
    $payload["documentoElectronico"]["Encabezado"]["TotalesOtraMoneda"] = $totales_otra_moneda;
}

// 7. INYECTAR REFERENCIAS DE DOCUMENTO AFECTADO (NOTAS DE CRÉDITO/DÉBITO)
if ($tipo_doc_fiscal === "02" || $tipo_doc_fiscal === "03") {
    $fecha_fac_afectada = $fecha_emision;
    $monto_fac_afectada = number_format((float)$monto_total, 2, '.', '');
    
    // Buscar datos históricos en ofcm020 si doc_ref no está vacío
    if (!empty($doc_ref)) {
        $sql_ref = "SELECT fecha, neto_loc FROM ofcm020 WHERE numero = '" . addslashes($doc_ref) . "'";
        sc_lookup(ds_ref, $sql_ref);
        if (!empty({ds_ref}) && {ds_ref} !== false) {
            $fecha_fac_afectada = date("d/m/Y", strtotime({ds_ref}[0][0]));
            $monto_fac_afectada = number_format((float){ds_ref}[0][1], 2, '.', '');
        }
    }
    
    $ident_ref = &$payload["documentoElectronico"]["Encabezado"]["IdentificacionDocumento"];
    $ident_ref["NumeroFacturaAfectada"] = $doc_ref;
    $ident_ref["FechaFacturaAfectada"] = $fecha_fac_afectada;
    $ident_ref["MontoFacturaAfectada"] = $monto_fac_afectada;
    $ident_ref["SerieFacturaAfectada"] = "";
    $ident_ref["ComentarioFacturaAfectada"] = ($tipo_doc_fiscal === "02") ? "Nota de credito por devolucion o ajuste" : "Nota de debito por cargo adicional";
}

$json_data = json_encode($payload);

// 8. CONFIGURAR ENDPOINT SEGÚN EL TIPO DE DOCUMENTO FISCAL
$endpoint = "/api/v1/emitir/factura";
if ($tipo_doc_fiscal === "02") {
    $endpoint = "/api/v1/emitir/nota-credito";
} elseif ($tipo_doc_fiscal === "03") {
    $endpoint = "/api/v1/emitir/nota-debito";
}
$url = $api_base_url . $endpoint;

// Consumo a través de la librería HTTP personalizada (cURL)
$http_res = of_http_lib::post_json($url, $json_data, 30);
$response_raw    = $http_res['body'];
$http_status     = $http_res['status'];
$response_error  = $http_res['error'];

// 9. PROCESAR RESPUESTA Y REGISTRAR LOG DE INTEGRACIÓN (ofint001)
if ($response_raw === null || $http_res['success'] === false) {
    // Registrar error de red en logs
    $err_msg = !empty($response_error) ? addslashes($response_error) : 'No se pudo conectar con el servicio local de facturación';
    $insert_log_sql = "INSERT INTO ofint001 (
                         origen, endpoint, tipo_documento, numero_documento, referencia_id, 
                         peticion_json, respuesta_json, codigo_respuesta, exito
                       ) VALUES (
                         'Scriptcase', '" . $endpoint . "', '" . $tipo_doc_fiscal . "', '" . addslashes($num_documento) . "', " . $factura_id . ",
                         '" . addslashes($json_data) . "', '" . $err_msg . "', '" . $http_status . "', 0
                       )";
    sc_exec_sql($insert_log_sql);

    // Escribir en cabecera fiscal offve001
    $insert_err_conn = "INSERT INTO offve001 (
                            factura_id, tipo_documento, numero_documento, estatus_fiscal, mensaje_fiscal
                         ) VALUES (
                            " . $factura_id . ", '" . $tipo_doc_fiscal . "', '" . addslashes($num_documento) . "', 'Error', 
                            'No se pudo conectar con el servicio local de facturación: " . $err_msg . "'
                         )";
    sc_exec_sql($insert_err_conn);

    sc_error_message("Error: No hay conexión con el servicio local de facturación. Detalle: " . $response_error);
    return;
}

$response = json_decode($response_raw, true);
$codigo_respuesta = isset($response['codigo']) ? $response['codigo'] : $http_status;
$exito = ($http_status === 200 && $codigo_respuesta === '200') ? 1 : 0;

// Registrar Log de transacción (ofint001)
$insert_log_sql = "INSERT INTO ofint001 (
                     origen, endpoint, tipo_documento, numero_documento, referencia_id, 
                     peticion_json, respuesta_json, codigo_respuesta, exito
                   ) VALUES (
                     'Scriptcase', '" . $endpoint . "', '" . $tipo_doc_fiscal . "', '" . addslashes($num_documento) . "', " . $factura_id . ",
                     '" . addslashes($json_data) . "', '" . addslashes($response_raw) . "', '" . addslashes($codigo_respuesta) . "', " . $exito . "
                   )";
sc_exec_sql($insert_log_sql);

if ($exito === 1) {
    // EMISIÓN FISCAL EXITOSA
    $resultado = $response['resultado'];
    $nro_control = $resultado['numeroControl'];
    $fecha_asig  = $resultado['fechaAsignacion'] . ' ' . $resultado['horaAsignacion'];
    $url_web     = $resultado['urlConsulta'];
    
    // 1. Insertar el encabezado fiscal en offve001
    $insert_fiscal_sql = "INSERT INTO offve001 (
                            factura_id, 
                            tipo_documento, 
                            numero_documento, 
                            numero_control, 
                            estatus_fiscal, 
                            fecha_asignacion, 
                            url_consulta, 
                            mensaje_fiscal
                          ) VALUES (
                            " . $factura_id . ",
                            '" . $tipo_doc_fiscal . "',
                            '" . addslashes($num_documento) . "',
                            '" . addslashes($nro_control) . "',
                            'Procesado',
                            STR_TO_DATE('" . addslashes($fecha_asig) . "', '%d/%m/%Y %h:%i:%s %p'),
                            '" . addslashes($url_web) . "',
                            'Documento procesado correctamente'
                          )";
    sc_exec_sql($insert_fiscal_sql);
    
    // Obtener ID insertado
    sc_lookup(ds_last_id, "SELECT LAST_INSERT_ID()");
    $factura_fiscal_id = {ds_last_id}[0][0];
    
    // 2. Insertar los ítems fiscales en offve011 (En VES local)
    foreach ($detalles_items as $item) {
        $insert_item_sql = "INSERT INTO offve011 (
                              factura_fiscal_id,
                              numero_linea,
                              indicador_bien_servicio,
                              descripcion,
                              cantidad,
                              unidad_medida,
                              precio_unitario,
                              precio_item,
                              codigo_impuesto,
                              tasa_iva,
                              valor_iva,
                              valor_total_item
                            ) VALUES (
                              " . $factura_fiscal_id . ",
                              " . $item['NumeroLinea'] . ",
                              '" . $item['IndicadorBienoServicio'] . "',
                              '" . addslashes($item['Descripcion']) . "',
                              " . $item['Cantidad'] . ",
                              '" . addslashes($item['UnidadMedida']) . "',
                              " . $item['PrecioUnitario'] . ",
                              " . $item['PrecioItem'] . ",
                              '" . addslashes($item['CodigoImpuesto']) . "',
                              " . $item['TasaIVA'] . ",
                              " . $item['ValorIVA'] . ",
                              " . $item['ValorTotalItem'] . "
                            )";
        sc_exec_sql($insert_item_sql);
    }
    
    echo "<script>alert('Documento Fiscal emitido y registrado con éxito. Nro Control: " . $nro_control . "');</script>";

} else {
    // EMISIÓN RECHAZADA POR LA API / TFHKA
    $mensaje_error = isset($response['message']) ? $response['message'] : 'Error de validación fiscal';
    if (isset($response['validations']) && is_array($response['validations']) && count($response['validations']) > 0) {
        $mensaje_error .= " | Detalles: " . implode(", ", $response['validations']);
    }
    $mensaje_error_db = addslashes($mensaje_error);
    
    // Registrar el error en offve001 para auditoría histórica
    $insert_error_sql = "INSERT INTO offve001 (
                            factura_id, 
                            tipo_documento, 
                            numero_documento, 
                            numero_control, 
                            estatus_fiscal, 
                            mensaje_fiscal
                          ) VALUES (
                            " . $factura_id . ",
                            '" . $tipo_doc_fiscal . "',
                            '" . addslashes($num_documento) . "',
                            NULL,
                            'Error',
                            '" . $mensaje_error_db . "'
                          )";
    sc_exec_sql($insert_error_sql);
    
    sc_error_message("Error en Emisión Fiscal: " . $mensaje_error);
}
