<?php
/**
 * =================================================================================
 * MÉTODO PHP - anular_factura_erp (Para formulario form_ofcm020 en Scriptcase)
 * =================================================================================
 * Este método se encarga de realizar la anulación interna en el ERP (status = 'A')
 * de una Factura previamente emitida ('I').
 * 
 * REGLAS DE NEGOCIO:
 * 1. NO invoca la API externa de TFHKA (las N/C ya fueron transmitidas individualmente).
 * 2. Valida que el campo virtual en memoria {motivoAnulacion} contenga una justificación.
 * 3. Identifica la moneda base de la factura (VES, USD o EUR).
 *    Si la factura es en MONEDA EXTRANJERA (USD o EUR), los montos a cuadrar y validar
 *    serán los de la MONEDA EXTRANJERA (evitando distorsiones por variación de tasa BCV
 *    entre la fecha de la factura y la fecha de emisión de la Nota de Crédito).
 * 4. Localiza todas las Notas de Crédito ('C') en ofcm020 asociadas a esta factura (doc_ref).
 * 5. Valida que cada Nota de Crédito exista en offve001 y tenga estatus_fiscal = 1 (Emitida/Control SENIAT).
 * 6. Valida que la sumatoria acumulada de las N/C cubra el 100% del monto total de la factura
 *    en la moneda de validación correspondiente.
 * 7. Registra el motivo y el extracto de auditoría de las N/C en offve001.motivo_anulacion.
 * 8. Actualiza ofcm020.status = 'A' y bloquea el formulario.
 * =================================================================================
 */

// 1. OBTENER VARIABLES DEL FORMULARIO ofcm020
$factura_id     = isset({id}) ? {id} : (isset([ofcm020_id]) ? [ofcm020_id] : null);
$factura_numero = isset({numero}) ? trim({numero}) : '';
$motivo_usuario = isset({motivoAnulacion}) ? trim({motivoAnulacion}) : '';

if (empty($factura_id)) {
    throw new Exception("No se pudo identificar el ID de la factura a anular.");
}

// ---------------------------------------------------------------------------------
// 2. VALIDAR QUE EL USUARIO HAYA INGRESADO UN MOTIVO DE ANULACIÓN
// ---------------------------------------------------------------------------------
if (empty($motivo_usuario)) {
    throw new Exception("Debe ingresar un 'Motivo de Anulación' antes de anular el documento.");
}

// ---------------------------------------------------------------------------------
// 3. CONSULTAR MONEDA BASE Y TOTALES DE LA FACTURA (ofcm020 Y offve001)
// ---------------------------------------------------------------------------------
$sql_fac = "SELECT 
                UPPER(TRIM(f.moneda_trn)) AS fac_moneda_erp,
                ABS(f.neto_loc) AS fac_neto_loc,
                ABS(f.neto_usd) AS fac_neto_usd,
                ABS(f.neto_eur) AS fac_neto_eur,
                ABS(v.monto_total_pagar) AS offve_tot_loc,
                ABS(v.monto_total_pagar_trn) AS offve_tot_trn,
                UPPER(TRIM(v.moneda_trn)) AS offve_moneda_trn
            FROM ofcm020 f
            LEFT JOIN offve001 v ON v.factura_id = f.id AND v.tipo_documento = '01'
            WHERE f.id = " . (int)$factura_id;

sc_lookup(rs_fac, $sql_fac);

if (empty({rs_fac}) || {rs_fac} === false) {
    throw new Exception("No se encontró el registro de la factura ID: " . $factura_id . " en ofcm020.");
}

$fac_moneda_erp = !empty({rs_fac}[0][0]) ? {rs_fac}[0][0] : 'VES';
$fac_neto_loc   = (float){rs_fac}[0][1];
$fac_neto_usd   = (float){rs_fac}[0][2];
$fac_neto_eur   = (float){rs_fac}[0][3];
$offve_tot_loc  = (float){rs_fac}[0][4];
$offve_tot_trn  = (float){rs_fac}[0][5];
$offve_moneda   = !empty({rs_fac}[0][6]) ? {rs_fac}[0][6] : $fac_moneda_erp;

// Determinar Moneda Base de Validación y Total de la Factura
$moneda_validacion = 'VES';
$total_factura     = 0.0;
$total_referencia_loc = ($fac_neto_loc > 0) ? $fac_neto_loc : $offve_tot_loc;

if ($fac_moneda_erp === 'USD' || $offve_moneda === 'USD') {
    $moneda_validacion = 'USD';
    if ($fac_neto_usd > 0) {
        $total_factura = $fac_neto_usd;
    } elseif ($offve_tot_trn > 0) {
        $total_factura = $offve_tot_trn;
    } elseif (isset({neto_usd}) && (float){neto_usd} > 0) {
        $total_factura = abs((float){neto_usd});
    }
} elseif ($fac_moneda_erp === 'EUR' || $offve_moneda === 'EUR') {
    $moneda_validacion = 'EUR';
    if ($fac_neto_eur > 0) {
        $total_factura = $fac_neto_eur;
    } elseif ($offve_tot_trn > 0) {
        $total_factura = $offve_tot_trn;
    } elseif (isset({neto_eur}) && (float){neto_eur} > 0) {
        $total_factura = abs((float){neto_eur});
    }
} else {
    // Moneda local (VES / VEB)
    $moneda_validacion = 'VES';
    if ($fac_neto_loc > 0) {
        $total_factura = $fac_neto_loc;
    } elseif ($offve_tot_loc > 0) {
        $total_factura = $offve_tot_loc;
    } elseif (isset({neto_loc}) && (float){neto_loc} > 0) {
        $total_factura = abs((float){neto_loc});
    }
}

if ($total_factura <= 0) {
    throw new Exception("El monto total de la factura a anular es 0 o no pudo ser determinado en {$moneda_validacion}.");
}

// ---------------------------------------------------------------------------------
// 4. CONSULTAR NOTAS DE CRÉDITO ASOCIADAS EN ofcm020 Y offve001
// ---------------------------------------------------------------------------------
$clean_num = preg_replace('/[^0-9]/', '', $factura_numero);

$sql_nc = "SELECT 
               c.id AS nc_id,
               c.numero AS nc_numero,
               c.fecha AS nc_fecha,
               ABS(c.neto_loc) AS nc_monto_loc,
               ABS(c.neto_usd) AS nc_monto_usd,
               ABS(c.neto_eur) AS nc_monto_eur,
               UPPER(TRIM(c.moneda_trn)) AS nc_moneda_erp,
               c.status AS nc_status_erp,
               v.id AS offve001_id,
               v.estatus_fiscal,
               v.numero_control,
               v.mensaje_fiscal,
               ABS(v.monto_total_pagar) AS offve_nc_tot_loc,
               ABS(v.monto_total_pagar_trn) AS offve_nc_tot_trn,
               UPPER(TRIM(v.moneda_trn)) AS offve_nc_moneda
           FROM ofcm020 c
           LEFT JOIN offve001 v ON v.factura_id = c.id AND v.tipo_documento = '02'
           WHERE c.tipo_factura = 'C'
             AND (c.doc_ref = '" . addslashes($factura_numero) . "' OR c.doc_ref = '" . addslashes($clean_num) . "')
             AND c.status != 'A'
           ORDER BY c.fecha ASC, c.id ASC";

sc_lookup(rs_nc, $sql_nc);

if (empty({rs_nc}) || {rs_nc} === false) {
    throw new Exception("No existen Notas de Crédito registradas que compensen la factura Nro: " . $factura_numero . ". No se puede anular un documento fiscal sin su Nota de Crédito de soporte.");
}

// ---------------------------------------------------------------------------------
// 5. VALIDAR ESTATUS FISCAL DE CADA N/C Y SUMATORIA EN MONEDA DE VALIDACIÓN
// ---------------------------------------------------------------------------------
$suma_nc_total      = 0.0;
$detalles_auditoria = [];
$nc_pendientes      = [];

foreach ({rs_nc} as $nc) {
    $nc_id             = $nc[0];
    $nc_num            = $nc[1];
    $nc_fecha          = $nc[2];
    $nc_neto_loc       = (float)$nc[3];
    $nc_neto_usd       = (float)$nc[4];
    $nc_neto_eur       = (float)$nc[5];
    $nc_moneda_erp     = !empty($nc[6]) ? $nc[6] : 'VES';
    $nc_status_erp     = $nc[7];
    $offve001_id       = $nc[8];
    $estatus_fiscal    = isset($nc[9]) ? (int)$nc[9] : null;
    $nro_control       = !empty($nc[10]) ? $nc[10] : 'SIN CONTROL';
    $nc_offve_loc      = (float)$nc[12];
    $nc_offve_trn      = (float)$nc[13];
    $nc_offve_moneda   = !empty($nc[14]) ? $nc[14] : $nc_moneda_erp;

    // Verificar si la Nota de Crédito fue emitida fiscalmente ante TFHKA
    if ($estatus_fiscal !== 1) {
        $nc_pendientes[] = "• N/C Nro {$nc_num}: Pendiente de emisión fiscal ante TFHKA (Estatus actual: " . ($estatus_fiscal === 0 ? "Borrador" : "No procesada") . ")";
    }

    // Seleccionar el monto de la N/C en la moneda base de validación
    $nc_monto = 0.0;
    if ($moneda_validacion === 'USD') {
        $nc_monto = ($nc_neto_usd > 0) ? $nc_neto_usd : $nc_offve_trn;
    } elseif ($moneda_validacion === 'EUR') {
        $nc_monto = ($nc_neto_eur > 0) ? $nc_neto_eur : $nc_offve_trn;
    } else {
        $nc_monto = ($nc_neto_loc > 0) ? $nc_neto_loc : $nc_offve_loc;
    }

    $suma_nc_total += $nc_monto;
    $detalles_auditoria[] = "• N/C: {$nc_num} | Control SENIAT: {$nro_control} | Monto: " . number_format($nc_monto, 2, ',', '.') . " {$moneda_validacion} | Fecha: {$nc_fecha}";
}

// Si hay notas de crédito sin emitir fiscalmente, se aborta la anulación
if (!empty($nc_pendientes)) {
    $msg_err = "No se puede completar la anulación. Las siguientes Notas de Crédito asociadas no han sido transmitidas fiscalmente ante TFHKA:<br><br>" 
             . implode("<br>", $nc_pendientes) 
             . "<br><br>Por favor emita fiscalmente todas las Notas de Crédito antes de anular la factura.";
    throw new Exception($msg_err);
}

// ---------------------------------------------------------------------------------
// 6. VALIDAR CUADRE DE TOTALES (Factura vs Suma de N/C) EN LA MONEDA CORRESPONDIENTE
// ---------------------------------------------------------------------------------
$diferencia = round(abs($total_factura - $suma_nc_total), 2);

// Tolerancia de 2 centavos por redondeos de centavos en base de datos
if ($diferencia > 0.02) {
    $txt_fac = number_format($total_factura, 2, ',', '.');
    $txt_nc  = number_format($suma_nc_total, 2, ',', '.');
    $txt_dif = number_format($diferencia, 2, ',', '.');
    
    if ($suma_nc_total < $total_factura) {
        throw new Exception("El monto acumulado de las Notas de Crédito ({$moneda_validacion} {$txt_nc}) no cubre el total de la factura ({$moneda_validacion} {$txt_fac}).<br>Resta por compensar: {$moneda_validacion} {$txt_dif}. Debe emitir una Nota de Crédito por el saldo restante.");
    } else {
        throw new Exception("El monto acumulado de las Notas de Crédito ({$moneda_validacion} {$txt_nc}) supera el total de la factura ({$moneda_validacion} {$txt_fac}).<br>Excedente: {$moneda_validacion} {$txt_dif}. Verifique las Notas de Crédito asociadas.");
    }
}

// ---------------------------------------------------------------------------------
// 7. GENERAR EXTRACTO DE AUDITORÍA Y ACTUALIZAR BD
// ---------------------------------------------------------------------------------
$fecha_anulacion = date('d/m/Y h:i:s A');
$usuario_actual  = isset([usr_login]) ? [usr_login] : (isset($_SESSION['usr_login']) ? $_SESSION['usr_login'] : 'SISTEMA');

$texto_auditoria  = "=====================================================\n";
$texto_auditoria .= "ANULACIÓN DE FACTURA EN ERP - " . $fecha_anulacion . "\n";
$texto_auditoria .= "Usuario: " . $usuario_actual . "\n";
$texto_auditoria .= "Motivo: " . $motivo_usuario . "\n";
$texto_auditoria .= "Moneda de Validación: " . $moneda_validacion . "\n";
$texto_auditoria .= "-----------------------------------------------------\n";
$texto_auditoria .= "Documentos de respaldo aplicados (" . count($detalles_auditoria) . " N/C):\n";
$texto_auditoria .= implode("\n", $detalles_auditoria) . "\n";
$texto_auditoria .= "Total Factura: " . number_format($total_factura, 2, ',', '.') . " {$moneda_validacion}";
if ($moneda_validacion !== 'VES' && $total_referencia_loc > 0) {
    $texto_auditoria .= " (Ref. Legal: " . number_format($total_referencia_loc, 2, ',', '.') . " VES)";
}
$texto_auditoria .= "\n";
$texto_auditoria .= "Total N/C Respaldo: " . number_format($suma_nc_total, 2, ',', '.') . " {$moneda_validacion}\n";
$texto_auditoria .= "=====================================================";

// Consultar longitud y tipo de la columna motivo_anulacion en la BD para evitar error "Data too long"
$max_len_bd = 250; // Fallback por defecto seguro para VARCHAR(255)
$sql_len = "SELECT CHARACTER_MAXIMUM_LENGTH, DATA_TYPE 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_NAME = 'offve001' AND COLUMN_NAME = 'motivo_anulacion' 
            LIMIT 1";
sc_lookup(rs_len, $sql_len);
if (!empty({rs_len}) && {rs_len} !== false) {
    $tipo_col = strtolower(trim({rs_len}[0][1]));
    if (in_array($tipo_col, ['text', 'mediumtext', 'longtext'])) {
        $max_len_bd = 65000; // Es tipo TEXT, admite la bitácora completa
    } elseif (!empty({rs_len}[0][0]) && (int){rs_len}[0][0] > 0) {
        $max_len_bd = (int){rs_len}[0][0];
    }
}

// Preparar el texto a persistir en la base de datos
$texto_bd = $texto_auditoria;
$len_actual = function_exists('mb_strlen') ? mb_strlen($texto_bd, 'UTF-8') : strlen($texto_bd);

if ($len_actual > $max_len_bd) {
    // Si la columna es VARCHAR y no cabe el bloque completo, generar una versión sintetizada compacta
    $lista_nc_corta = [];
    foreach ({rs_nc} as $nc_item) {
        $lista_nc_corta[] = $nc_item[1]; // Nro de N/C
    }
    $texto_compacto = "[ANULADA " . date('d/m/Y') . "] Motivo: " . $motivo_usuario . " | N/C: " . implode(',', $lista_nc_corta) . " (" . number_format($suma_nc_total, 2, ',', '.') . " " . $moneda_validacion . ")";
    
    $len_compacto = function_exists('mb_strlen') ? mb_strlen($texto_compacto, 'UTF-8') : strlen($texto_compacto);
    if ($len_compacto > $max_len_bd) {
        $texto_bd = function_exists('mb_substr') ? mb_substr($texto_compacto, 0, $max_len_bd - 3, 'UTF-8') . '...' : substr($texto_compacto, 0, $max_len_bd - 3) . '...';
    } else {
        $texto_bd = $texto_compacto;
    }
}

// A. Guardar motivo y bitácora en la cabecera fiscal offve001 de la factura
$sql_update_offve = "UPDATE offve001 
                     SET motivo_anulacion = '" . addslashes($texto_bd) . "' 
                     WHERE factura_id = " . $factura_id . " 
                       AND tipo_documento = '01'";
sc_exec_sql($sql_update_offve);

// B. Actualizar estatus del ERP en ofcm020 a 'A' (Anulado)
$sql_update_erp = "UPDATE ofcm020 SET status = 'A' WHERE id = " . $factura_id;
sc_exec_sql($sql_update_erp);

// ---------------------------------------------------------------------------------
// 8. ACTUALIZAR INTERFAZ SCRIPTCASE EN MEMORIA
// ---------------------------------------------------------------------------------
if (isset({status})) {
    {status} = 'A';
}
if (isset({motivoAnulacion})) {
    {motivoAnulacion} = $texto_auditoria;
}

// Proteger el campo como solo lectura y ocultar el botón
sc_field_readonly({motivoAnulacion}, 'on');
sc_btn_display('btn_anular_factura', 'off');

// Notificación de éxito al usuario
echo "<script>alert('Factura anulada con éxito en el ERP.\\n\\nSe validaron " . count($detalles_auditoria) . " Nota(s) de Crédito con respaldo fiscal SENIAT/TFHKA por un total de " . number_format($suma_nc_total, 2, ',', '.') . " " . $moneda_validacion . ".');</script>";
