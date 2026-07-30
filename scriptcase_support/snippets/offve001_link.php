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

// 4b. VERIFICAR SI YA EXISTE UN REGISTRO PROCESADO (estatus_fiscal = 1)
$strSQL = "SELECT id FROM offve001 WHERE factura_id = $ofcm020_id AND tipo_documento = '$tipo_doc_fiscal'";
sc_lookup(rs_existente, $strSQL);

if (!empty({rs_existente}) && {rs_existente} !== false) {
    $recordId = {rs_existente}[0][0];
    // Redireccionar directamente al formulario de revisión
    sc_redir("form_offve001", invoiceId = $recordId; sHeader = ''; sStatus = 1; sPrefilledCreditNote = 0);
    return;
}

// 5. OBTENER CORRELATIVO Y TOTALES ERP PARA ASIGNAR EN BORRADOR
$strSQL = "SELECT 
               numero, moneda_trn, 
               monto_loc, monto_iva_loc, neto_loc, gravable_loc, exento_loc,
               tasa_cambio, monto_usd, monto_iva_usd, neto_usd, gravable_usd, exento_usd,
               tasa_cambio_eur, monto_eur, monto_iva_eur, neto_eur, gravable_eur, exento_eur
           FROM ofcm020 
           WHERE id = $ofcm020_id";
sc_lookup(rs_fac, $strSQL);
$numero_documento = preg_replace('/[^0-9]/', '', {rs_fac}[0][0]);
$moneda_trn       = strtoupper(trim({rs_fac}[0][1]));

// Montos Locales (VES)
$raw_subtotal = (float){rs_fac}[0][2];
$raw_iva      = (float){rs_fac}[0][3];
$raw_total    = (float){rs_fac}[0][4];
$raw_gravable = (float){rs_fac}[0][5];
$raw_exento   = (float){rs_fac}[0][6];

// Calcular consolidación de IGTF local desde ofcm021
$strSQL_igtf = "SELECT SUM(total_loc), SUM(gravable_loc), SUM(exento_loc) FROM ofcm021 
                WHERE ofcm020_id = $ofcm020_id 
                  AND (ofin009_id LIKE '*IGT%' OR descripcion LIKE '*IGT%' OR descripcion LIKE 'Impuesto a las Grandes Transacciones%')";
sc_lookup(rs_igtf, $strSQL_igtf);

$igtf_amount = 0.0;
$igtf_base   = 0.0;
$igtf_exento = 0.0;
if (!empty({rs_igtf}) && {rs_igtf} !== false) {
    $igtf_amount = (float){rs_igtf}[0][0];
    $igtf_base   = (float){rs_igtf}[0][1];
    $igtf_exento = (float){rs_igtf}[0][2];
}

// Calcular los montos locales finales sanitizados de IGTF
$monto_subtotal    = $raw_subtotal - $igtf_amount;
$monto_gravable    = $raw_gravable - $igtf_base;
$monto_exento      = $raw_exento - $igtf_exento;
$monto_iva         = $raw_iva;
$monto_total       = $raw_total - $igtf_amount; // sin IGTF
$monto_igtf        = $igtf_amount;
$monto_total_pagar = $raw_total; // con IGTF

// Inicializar montos transaccionales en moneda extranjera
$tasa_cambio           = 0.0000;
$monto_subtotal_trn    = 0.0000;
$monto_gravable_trn    = 0.0000;
$monto_exento_trn      = 0.0000;
$monto_iva_trn         = 0.0000;
$monto_total_trn       = 0.0000;
$monto_igtf_trn        = 0.0000;
$monto_total_pagar_trn = 0.0000;

if ($moneda_trn === 'USD') {
    $tasa_cambio        = (float){rs_fac}[0][7];
    $raw_subtotal_trn   = (float){rs_fac}[0][8];
    $raw_iva_trn        = (float){rs_fac}[0][9];
    $raw_total_trn      = (float){rs_fac}[0][10];
    $raw_gravable_trn   = (float){rs_fac}[0][11];
    $raw_exento_trn     = (float){rs_fac}[0][12];

    // IGTF USD
    $strSQL_igtf_usd = "SELECT SUM(total_usd), SUM(gravable_usd), SUM(exento_usd) FROM ofcm021 
                        WHERE ofcm020_id = $ofcm020_id 
                          AND (ofin009_id LIKE '*IGT%' OR descripcion LIKE '*IGT%' OR descripcion LIKE 'Impuesto a las Grandes Transacciones%')";
    sc_lookup(rs_igtf_usd, $strSQL_igtf_usd);
    
    $igtf_amount_trn = 0.0;
    $igtf_base_trn   = 0.0;
    $igtf_exento_trn = 0.0;
    if (!empty({rs_igtf_usd}) && {rs_igtf_usd} !== false) {
        $igtf_amount_trn = (float){rs_igtf_usd}[0][0];
        $igtf_base_trn   = (float){rs_igtf_usd}[0][1];
        $igtf_exento_trn = (float){rs_igtf_usd}[0][2];
    }

    $monto_subtotal_trn    = $raw_subtotal_trn - $igtf_amount_trn;
    $monto_gravable_trn    = $raw_gravable_trn - $igtf_base_trn;
    $monto_exento_trn      = $raw_exento_trn - $igtf_exento_trn;
    $monto_iva_trn         = $raw_iva_trn;
    $monto_total_trn       = $raw_total_trn - $igtf_amount_trn;
    $monto_igtf_trn        = $igtf_amount_trn;
    $monto_total_pagar_trn = $raw_total_trn;

} elseif ($moneda_trn === 'EUR') {
    $tasa_cambio        = (float){rs_fac}[0][13];
    $raw_subtotal_trn   = (float){rs_fac}[0][14];
    $raw_iva_trn        = (float){rs_fac}[0][15];
    $raw_total_trn      = (float){rs_fac}[0][16];
    $raw_gravable_trn   = (float){rs_fac}[0][17];
    $raw_exento_trn     = (float){rs_fac}[0][18];

    // IGTF EUR
    $strSQL_igtf_eur = "SELECT SUM(total_eur), SUM(gravable_eur), SUM(exento_eur) FROM ofcm021 
                        WHERE ofcm020_id = $ofcm020_id 
                          AND (ofin009_id LIKE '*IGT%' OR descripcion LIKE '*IGT%' OR descripcion LIKE 'Impuesto a las Grandes Transacciones%')";
    sc_lookup(rs_igtf_eur, $strSQL_igtf_eur);
    
    $igtf_amount_trn = 0.0;
    $igtf_base_trn   = 0.0;
    $igtf_exento_trn = 0.0;
    if (!empty({rs_igtf_eur}) && {rs_igtf_eur} !== false) {
        $igtf_amount_trn = (float){rs_igtf_eur}[0][0];
        $igtf_base_trn   = (float){rs_igtf_eur}[0][1];
        $igtf_exento_trn = (float){rs_igtf_eur}[0][2];
    }

    $monto_subtotal_trn    = $raw_subtotal_trn - $igtf_amount_trn;
    $monto_gravable_trn    = $raw_gravable_trn - $igtf_base_trn;
    $monto_exento_trn      = $raw_exento_trn - $igtf_exento_trn;
    $monto_iva_trn         = $raw_iva_trn;
    $monto_total_trn       = $raw_total_trn - $igtf_amount_trn;
    $monto_igtf_trn        = $igtf_amount_trn;
    $monto_total_pagar_trn = $raw_total_trn;
}

// 6. INSERTAR REGISTRO DE CABECERA EN offve001 (Estatus: Borrador = 0)
$insert_cabecera = "INSERT INTO offve001 (
                        factura_id, tipo_documento, numero_documento, estatus_fiscal, mensaje_fiscal,
                        monto_subtotal, monto_gravable, monto_exento, monto_iva, monto_total, monto_igtf, monto_total_pagar,
                        moneda_trn, tasa_cambio, monto_subtotal_trn, monto_gravable_trn, monto_exento_trn, monto_iva_trn, monto_total_trn, monto_igtf_trn, monto_total_pagar_trn
                    ) VALUES (
                        $ofcm020_id, '$tipo_doc_fiscal', '" . addslashes($numero_documento) . "', 0, '',
                        $monto_subtotal, $monto_gravable, $monto_exento, $monto_iva, $monto_total, $monto_igtf, $monto_total_pagar,
                        '$moneda_trn', $tasa_cambio, $monto_subtotal_trn, $monto_gravable_trn, $monto_exento_trn, $monto_iva_trn, $monto_total_trn, $monto_igtf_trn, $monto_total_pagar_trn
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
                 LEFT JOIN (
                     SELECT TRIM(codigo) AS codigo, MAX(tipo) AS tipo
                     FROM ofin009
                     GROUP BY TRIM(codigo)
                 ) i ON TRIM(d.ofin009_id) = i.codigo
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
    $strSQL = "SELECT id FROM offve001 WHERE factura_id = $ofcm020_id AND estatus_fiscal != 1";
    sc_lookup(rs_drafts, $strSQL);
    if (!empty({rs_drafts}) && {rs_drafts} !== false) {
        foreach ({rs_drafts} as $row) {
            $draft_id = $row[0];
            sc_exec_sql("DELETE FROM offve011 WHERE factura_fiscal_id = $draft_id");
            sc_exec_sql("DELETE FROM offve001 WHERE id = $draft_id");
        }
    }
}
