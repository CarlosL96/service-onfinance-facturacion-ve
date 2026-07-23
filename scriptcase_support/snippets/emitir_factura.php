<?php
/**
 * =================================================================================
 * SNIPPET DE INTEGRACIÓN - EMISIÓN DESDE SCRIPTCASE CON TABLAS REALES (ofcm020/ofcm021/ofcm001)
 * =================================================================================
 * Este código debe colocarse en el Evento correspondiente (botón PHP) de tu
 * aplicación de Scriptcase.
 * 
 * Requiere la variable local {id_factura} conteniendo el ID de la tabla ofcm020.
 * =================================================================================
 */

// 1. CONSULTAR CABECERA DE LA FACTURA (ofcm020) Y CLIENTE (ofcm001)
$sql_cabecera = "SELECT 
                    f.id,
                    f.tipo_factura, -- 'F'=Factura, 'C'=Nota Crédito, 'D'=Nota Débito
                    f.numero,
                    f.fecha,
                    f.moneda_trn,
                    c.id_fiscal,     -- RIF/Cédula (ej. J000723060)
                    c.nombre_fiscal, -- Razón Social
                    c.direccion,
                    c.telf_1,
                    c.email_contacto_adm,
                    c.email_contacto_compras,
                    f.gravable_loc,
                    f.exento_loc,
                    f.monto_loc,
                    f.monto_iva_loc,
                    f.neto_loc,
                    f.doc_ref        -- Referencia para Notas de Crédito/Débito
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
$moneda           = {ds_cabecera}[0][4];
$id_fiscal        = {ds_cabecera}[0][5];
$nombre_fiscal    = {ds_cabecera}[0][6];
$direccion        = {ds_cabecera}[0][7];
$telefono         = {ds_cabecera}[0][8];
$email_adm        = {ds_cabecera}[0][9];
$email_compras    = {ds_cabecera}[0][10];
$monto_gravado    = {ds_cabecera}[0][11];
$monto_exento     = {ds_cabecera}[0][12];
$monto_subtotal   = {ds_cabecera}[0][13];
$monto_iva        = {ds_cabecera}[0][14];
$monto_total      = {ds_cabecera}[0][15];
$doc_ref          = {ds_cabecera}[0][16];

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

// Consolidar teléfono y correo
$tel_cliente = trim($telefono);
$correo_cliente = !empty($email_adm) ? trim($email_adm) : (!empty($email_compras) ? trim($email_compras) : '');

// 2. OBTENER DETALLE DE ÍTEMS DE LA FACTURA (ofcm021)
$sql_detalles = "SELECT 
                    id,
                    ofin009_id, -- Código del ítem
                    descripcion,
                    cantidad,
                    precio_un_loc,
                    total_loc,
                    iva_loc,
                    gravable_loc,
                    exento_loc
                 FROM ofcm021
                 WHERE ofcm020_id = " . $factura_id . "
                 ORDER BY id ASC";

sc_lookup(ds_detalles, $sql_detalles);

if (empty({ds_detalles}) || {ds_detalles} === false) {
    sc_error_message("La factura en ofcm021 debe contener al menos un detalle o ítem.");
    return;
}

$detalles_items = [];
$linea_count = 1;

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
        "IndicadorBienoServicio" => "1", // 1=Bien por defecto (ajusta según tus reglas)
        "Descripcion" => trim(preg_replace('/\s+/', ' ', $descripcion)), // Quitar saltos de línea molestos
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

// 3. ESTRUCTURAR EL PAYLOAD JSON COMPLETO
$comprador_payload = [
    "TipoIdentificacion" => $tipo_ident,
    "NumeroIdentificacion" => $rif_cliente,
    "RazonSocial" => $nombre_fiscal,
    "Direccion" => !empty($direccion) ? $direccion : "CARACAS VENEZUELA",
    "Pais" => "VE"
];

// Inyectar opcionales dinámicamente según la disponibilidad en BD
if (!empty($correo_cliente)) {
    $comprador_payload["Correo"] = [$correo_cliente];
    // Teléfono es requerido por TFHKA solo si se provee Correo
    $comprador_payload["Telefono"] = [!empty($tel_cliente) ? $tel_cliente : "0212-000-0000"];
} elseif (!empty($tel_cliente)) {
    $comprador_payload["Telefono"] = [$tel_cliente];
}

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
                "Moneda" => "VES" // Siempre VES en fiscal nacional
            ],
            "Comprador" => $comprador_payload,
            "Totales" => [
                "NroItems" => (string)count($detalles_items),
                "MontoGravadoTotal" => number_format((float)$monto_gravado, 2, '.', ''),
                "MontoExentoTotal" => number_format((float)$monto_exento, 2, '.', ''),
                "Subtotal" => number_format((float)$monto_subtotal, 2, '.', ''),
                "TotalIVA" => number_format((float)$monto_iva, 2, '.', ''),
                "MontoTotalConIVA" => number_format((float)$monto_total, 2, '.', ''),
                "TotalAPagar" => number_format((float)$monto_total, 2, '.', ''),
                "MontoEnLetras" => "MONTO EN LETRAS AUTOMATICO",
                "ImpuestosSubtotal" => [
                    [
                        "CodigoTotalImp" => "G",
                        "AlicuotaImp" => "16.00",
                        "BaseImponibleImp" => number_format((float)$monto_gravado, 2, '.', ''),
                        "ValorTotalImp" => number_format((float)$monto_iva, 2, '.', '')
                    ]
                ],
                "FormasPago" => [
                    [
                        "Descripcion" => "Efectivo",
                        "Fecha" => $fecha_emision,
                        "Forma" => "01",
                        "Monto" => number_format((float)$monto_total, 2, '.', ''),
                        "Moneda" => "VES",
                        "TipoCambio" => "0.0000"
                    ]
                ]
            ]
        ],
        "DetallesItems" => $detalles_items
    ]
];

// Si es Nota de Crédito (02) o Débito (03), inyectar obligatoriamente referencias del documento afectado
if ($tipo_doc_fiscal === "02" || $tipo_doc_fiscal === "03") {
    $fecha_fac_afectada = $fecha_emision;
    $monto_fac_afectada = number_format((float)$monto_total, 2, '.', '');
    
    // Buscar la factura afectada en ofcm020 si existe doc_ref
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

// 4. CONFIGURAR ENDPOINT SEGÚN EL TIPO DE DOCUMENTO
$endpoint = "/api/v1/emitir/factura";
if ($tipo_doc_fiscal === "02") {
    $endpoint = "/api/v1/emitir/nota-credito";
} elseif ($tipo_doc_fiscal === "03") {
    $endpoint = "/api/v1/emitir/nota-debito";
}
$url = "http://localhost:8000" . $endpoint;

// Consumo con macro sc_http_request (Scriptcase)
$options = array(
    'method' => 'POST',
    'header' => "Content-Type: application/json",
    'content' => $json_data,
    'timeout' => 30
);
$response_raw = sc_http_request($url, $options);
$http_status = 200;

// 5. PROCESAR RESPUESTA Y ESCRIBIR LOG DE INTEGRACIÓN (ofint001)
if ($response_raw === false) {
    // Registrar error de red en logs
    $insert_log_sql = "INSERT INTO ofint001 (
                         origen, endpoint, tipo_documento, numero_documento, referencia_id, 
                         peticion_json, respuesta_json, codigo_respuesta, exito
                       ) VALUES (
                         'Scriptcase', '" . $endpoint . "', '" . $tipo_doc_fiscal . "', '" . addslashes($num_documento) . "', " . $factura_id . ",
                         '" . addslashes($json_data) . "', 'No se pudo conectar con el servicio local de facturación (Node.js)', '500', 0
                       )";
    sc_exec_sql($insert_log_sql);

    // Escribir en cabecera fiscal offve001
    $insert_err_conn = "INSERT INTO offve001 (
                            factura_id, tipo_documento, numero_documento, estatus_fiscal, mensaje_fiscal
                         ) VALUES (
                            " . $factura_id . ", '" . $tipo_doc_fiscal . "', '" . addslashes($num_documento) . "', 'Error', 
                            'No se pudo conectar con el servicio local de facturación digital'
                         )";
    sc_exec_sql($insert_err_conn);

    sc_error_message("Error: No hay conexión con el servicio local de facturación digital.");
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
    
    // 2. Insertar los ítems fiscales en offve011
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
