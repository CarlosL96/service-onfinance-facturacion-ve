<?php
/**
 * =================================================================================
 * BLANK APP - offve001_link (Homólogo de offpa001_link para Venezuela)
 * =================================================================================
 * Este script se ejecuta al presionar el botón de Facturación Electrónica de Venezuela
 * en el formulario form_ofcm020.
 * 
 * Crea un registro preliminar (Borrador) en offve001 y offve011, limpia borradores
 * anteriores del mismo documento, y redirecciona al formulario de revisión form_offve001.
 * =================================================================================
 */

// 1. OBTENER VARIABLES DE SCRIPTCASE
$ofcm020_id = [ofcm020_id];
$ofcm001_codigo = [ofcm001_codigo];
$ofcm020_tipo_factura = [ofcm020_tipo_factura];

// 2. VERIFICAR QUE EL CLIENTE EXISTA EN EL ERP Y POSEA RIF/CÉDULA
$strSQL = "SELECT nombre_fiscal, id_fiscal FROM ofcm001 WHERE codigo = '$ofcm001_codigo'";
sc_lookup(rs_cliente, $strSQL);

if (empty({rs_cliente}) || {rs_cliente} === false) {
    echo "Los datos de facturación del cliente no están registrados en el maestro ofcm001.";
    return;
}

$id_fiscal = {rs_cliente}[0][1];
if (empty($id_fiscal) || trim($id_fiscal) == '' || trim($id_fiscal) == 'NULL') {
    echo "El cliente no posee un RIF o Cédula registrado en ofcm001, no se puede facturar electrónicamente.";
    return;
}

// 3. ELIMINAR BORRADORES PREVIOS DE ESTA FACTURA
clear_drafts_ve($ofcm020_id);

// 4. DETERMINAR TIPO DE DOCUMENTO FISCAL VENEZUELA
if ($ofcm020_tipo_factura == "F") {
$tipo_doc_fiscal = "01"; // Factura
}
elseif ($ofcm020_tipo_factura == "C") {
    $tipo_doc_fiscal = "02"; // Nota de Crédito
} elseif ($ofcm020_tipo_factura == "D") {
    $tipo_doc_fiscal = "03"; // Nota de Débito
} else {
    echo "El tipo de documento '" . $ofcm020_tipo_factura . "' no es soportado por este módulo fiscal.";
    return;
}

// 5. OBTENER CORRELATIVO ERP PARA ASIGNAR EN BORRADOR
$strSQL = "SELECT numero FROM ofcm020 WHERE id = $ofcm020_id";
sc_lookup(rs_fac, $strSQL);
$numero_documento = {rs_fac}[0][0];

// 6. INSERTAR REGISTRO DE CABECERA EN offve001 (Estatus: Borrador)
$insert_cabecera = "INSERT INTO offve001 (
                        factura_id, tipo_documento, numero_documento, estatus_fiscal, mensaje_fiscal
                    ) VALUES (
                        $ofcm020_id, '$tipo_doc_fiscal', '" . addslashes($numero_documento) . "', 'Borrador', 'Borrador preliminar generado'
                    )";
sc_exec_sql($insert_cabecera);

// Obtener ID del borrador creado
sc_lookup(rs_last, "SELECT LAST_INSERT_ID()");
$recordId = {rs_last}[0][0];

// 7. COPIAR ÍTEMS DESDE ofcm021 HACIA LA VISTA PREVIA offve011
// Se hace un left join con ofin009 para determinar si es Bien o Servicio en caliente
$sql_detalles = "SELECT 
                    d.descripcion,
                    d.cantidad,
                    d.precio_un_loc,
                    d.total_loc,
                    d.iva_loc,
                    d.gravable_loc,
                    i.tipo
                 FROM ofcm021 d
                 LEFT JOIN ofin009 i ON d.ofin009_id = i.codigo
                 WHERE d.ofcm020_id = $ofcm020_id";
sc_lookup(rs_det, $sql_detalles);

if (!empty({rs_det}) && {rs_det} !== false) {
    $linea_count = 1;
    foreach ({rs_det} as $row) {
        $descripcion   = sc_sql_injection($row[0]);
        $cantidad      = $row[1];
        $precio_un     = $row[2];
        $total_loc     = $row[3];
        $iva_loc       = $row[4];
        $gravable_loc  = $row[5];
        $item_tipo     = $row[6];
        
        // Excluir líneas de IGTF del borrador de items (se reportan consolidadas en Totales)
        if (strpos($row[0], '*IGT') !== false || strpos($row[0], 'Impuesto a las Grandes Transacciones') !== false) {
            continue;
        }

        $ind_bien_servicio = ($item_tipo === 'S') ? '2' : '1';
        
        if ($gravable_loc > 0 && $iva_loc > 0) {
            $tasa_iva = round(($iva_loc / $gravable_loc) * 100);
            $codigo_imp = "G";
        } else {
            $tasa_iva = 0;
            $codigo_imp = "E";
        }

        $insert_item = "INSERT INTO offve011 (
                            factura_fiscal_id, numero_linea, indicador_bien_servicio, descripcion,
                            cantidad, precio_unitario, precio_item, codigo_impuesto, tasa_iva, valor_iva, valor_total_item
                        ) VALUES (
                            $recordId, $linea_count, '$ind_bien_servicio', $descripcion,
                            $cantidad, $precio_un, $total_loc, '$codigo_imp', $tasa_iva, $iva_loc, " . ($total_loc + $iva_loc) . "
                        )";
        sc_exec_sql($insert_item);
        $linea_count++;
    }
}

// 8. REDIRECCIONAR AL FORMULARIO DE REVISIÓN DE VENEZUELA (form_offve001)
sc_redir("form_offve001", invoiceId = $recordId; sHeader = ''; sStatus = -1; sPrefilledCreditNote = 0);

/**
 * Función auxiliar para limpiar borradores antiguos del mismo documento fiscal.
 */
function clear_drafts_ve($ofcm020_id) {
    $strSQL = "SELECT id FROM offve001 WHERE factura_id = $ofcm020_id AND estatus_fiscal = 'Borrador'";
    sc_lookup(rs_drafts, $strSQL);
    if (!empty({rs_drafts}) && {rs_drafts} !== false) {
        foreach ({rs_drafts} as $row) {
            $draft_id = $row[0];
            sc_exec_sql("DELETE FROM offve011 WHERE factura_fiscal_id = $draft_id");
            sc_exec_sql("DELETE FROM offve001 WHERE id = $draft_id");
        }
    }
}
