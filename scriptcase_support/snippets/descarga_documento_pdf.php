<?php
/**
 * =================================================================================
 * METODO PHP - descarga_documento_pdf (Homólogo para Venezuela)
 * =================================================================================
 * Este script se coloca como un PHP Method en el formulario form_offve001 de Scriptcase.
 * 
 * Obtiene el PDF en base64 (ya sea desde el caché local de offve001 o descargándolo
 * desde la API si aún no se ha guardado). Luego, decodifica el archivo y lo envía al
 * navegador forzando la descarga del archivo PDF.
 * =================================================================================
 */

// 1. OBTENER ID DEL BORRADOR/REGISTRO ACTIVO EN EL FORMULARIO
$draft_id = null;
if (isset({id}) && !empty({id})) {
    $draft_id = {id};
} elseif (isset({factura_id}) && !empty({factura_id})) {
    // Si no está el ID primario, buscar usando factura_id
    $tipo_doc = "01";
    if (isset({tipo_documento})) {
        $tipo_doc = {tipo_documento};
    }
    $sql_check = "SELECT id FROM offve001 WHERE factura_id = " . {factura_id} . " AND tipo_documento = '" . $tipo_doc . "'";
    sc_lookup(ds_exist, $sql_check);
    if (!empty({ds_exist}) && {ds_exist} !== false) {
        $draft_id = {ds_exist}[0][0];
    }
}

if ($draft_id === null) {
    throw new Exception("No se pudo identificar el registro fiscal activo para la descarga.");
}

// 2. CONSULTAR CABECERA FISCAL offve001
$sql_cab = "SELECT tipo_documento, numero_documento, estatus_fiscal, pdf_base64 FROM offve001 WHERE id = $draft_id";
sc_lookup(ds_cab, $sql_cab);

if (empty({ds_cab}) || {ds_cab} === false) {
    throw new Exception("No se encontraron los datos del registro fiscal con ID: " . $draft_id);
}

$tipo_documento   = {ds_cab}[0][0];
$numero_documento = {ds_cab}[0][1];
$estatus_fiscal   = (int){ds_cab}[0][2];
$pdf_base64       = {ds_cab}[0][3];

// 3. VALIDAR QUE EL DOCUMENTO HAYA SIDO EMITIDO EXITOSAMENTE
if ($estatus_fiscal !== 1) {
    throw new Exception("El documento fiscal no se encuentra en estado 'Procesado' (emisión exitosa), por lo que no posee un PDF disponible.");
}

// 4. SI EL PDF NO ESTÁ CACHEADO EN LA BD, DESCARGARLO DESDE LA API
if (empty($pdf_base64) || $pdf_base64 === 'NULL') {
    // URL del API de integración
    $api_base_url = "http://localhost:8000"; // Ajustar si es necesario
    $url = $api_base_url . "/api/v1/descargar-pdf";

    // Cuerpo de la petición
    $payload = [
        "tipoDocumento" => $tipo_documento,
        "numeroDocumento" => $numero_documento,
        "serie" => ""
    ];

    // Realizar llamada POST a la API
    $http_res = of_http_lib::post_json($url, $payload, 30);
    $response_raw = $http_res['body'];
    
    // Registrar Log de integración para auditoría/depuración
    $insert_log_sql = "INSERT INTO ofint001 (
                         origen, endpoint, tipo_documento, numero_documento, referencia_id, 
                         peticion_json, respuesta_json, codigo_respuesta, exito
                       ) VALUES (
                         'Scriptcase-PDF', '/api/v1/descargar-pdf', '" . $tipo_documento . "', '" . addslashes($numero_documento) . "', " . (int)$draft_id . ",
                         '" . addslashes(json_encode($payload)) . "', '" . addslashes($response_raw) . "', '" . $http_res['status'] . "', " . ($http_res['success'] ? 1 : 0) . "
                       )";
    sc_exec_sql($insert_log_sql);

    if (empty($response_raw)) {
        throw new Exception("No se recibió respuesta del servicio de facturación local al descargar el PDF. Detalle: " . $http_res['error']);
    }

    $response = json_decode($response_raw, true);
    
    $archivo_base64 = '';
    if (isset($response['Archivo']) && !empty($response['Archivo'])) {
        $archivo_base64 = $response['Archivo'];
    } elseif (isset($response['archivo']) && !empty($response['archivo'])) {
        $archivo_base64 = $response['archivo'];
    }

    if (!empty($archivo_base64)) {
        $pdf_base64 = $archivo_base64;
        
        // Guardar el PDF en base de datos para no tener que consultarlo de nuevo
        $update_pdf = "UPDATE offve001 SET pdf_base64 = '" . addslashes($pdf_base64) . "' WHERE id = $draft_id";
        sc_exec_sql($update_pdf);
    } else {
        $msg_err = isset($response['mensaje']) ? $response['mensaje'] : (isset($response['message']) ? $response['message'] : "Archivo PDF no devuelto por el servicio.");
        throw new Exception("Error al descargar PDF desde el portal fiscal: " . $msg_err);
    }
}

// 5. ENVIAR ARCHIVO AL NAVEGADOR
$pdf_binary = base64_decode($pdf_base64);

if (empty($pdf_binary)) {
    throw new Exception("El archivo PDF decodificado está vacío o corrupto.");
}

// Limpiar buffers de salida para evitar corrupción del PDF
if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="factura_fiscal_' . $numero_documento . '.pdf"');
header('Content-Length: ' . strlen($pdf_binary));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $pdf_binary;
exit;
