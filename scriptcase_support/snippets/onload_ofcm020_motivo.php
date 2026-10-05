<?php
/**
 * =================================================================================
 * EVENTO ONLOAD / ONRECORD - form_ofcm020 (Scriptcase)
 * =================================================================================
 * Este código debe ubicarse en el evento onLoad (y onRecord si aplica) del formulario
 * principal de facturación form_ofcm020.
 * 
 * PROPÓSITO:
 * 1. Inicializar el campo en memoria {motivoAnulacion}.
 * 2. Cargar el motivo de anulación histórico desde offve001 si el documento ya fue anulado
 *    o si existe un motivo previo registrado en el módulo fiscal.
 * 3. Gestionar el estado de lectura (readonly) y la visibilidad del botón de anulación
 *    según el estatus del documento ERP ({status}):
 *      - 'A' (Anulado): Campo solo lectura, botón Anular oculto.
 *      - 'I' (Impreso/Fiscalizado): Campo editable para capturar motivo si se desea anular, botón visible.
 *      - Otros (Borrador/Pendiente): Botón Anular fiscal oculto.
 * =================================================================================
 */

$ofcm020_id = isset({id}) ? {id} : (isset([ofcm020_id]) ? [ofcm020_id] : null);

// 1. Inicializar el campo en memoria vacío
{motivoAnulacion} = '';

if (!empty($ofcm020_id)) {
    // 2. Consultar motivo de anulación guardado en offve001 (Factura tipo '01')
    $sql_motivo = "SELECT motivo_anulacion 
                   FROM offve001 
                   WHERE factura_id = " . $ofcm020_id . " 
                     AND tipo_documento = '01' 
                   LIMIT 1";

    sc_lookup(rs_mot, $sql_motivo);

    if (!empty({rs_mot}) && {rs_mot} !== false) {
        $motivo_historico = {rs_mot}[0][0];
        if (!empty($motivo_historico)) {
            {motivoAnulacion} = $motivo_historico;
        }
    }
}

// 3. Control de visibilidad y permisos según estatus de la factura
$doc_status = isset({status}) ? trim({status}) : '';

if ($doc_status === 'A') {
    // FACTURA YA ANULADA:
    // Proteger el campo para evitar modificaciones y ocultar el botón de anular
    sc_field_readonly({motivoAnulacion}, 'on');
    sc_btn_display('btn_anular_factura', 'off');

} elseif ($doc_status === 'I') {
    // FACTURA EMITIDA FISCALMENTE:
    // El campo permanece disponible para ingresar la causa de anulación y se muestra el botón
    sc_field_readonly({motivoAnulacion}, 'off');
    sc_btn_display('btn_anular_factura', 'on');

} else {
    // DOCUMENTOS EN BORRADOR O SIN EMISIÓN FISCAL:
    // Ocultar botón de anular fiscal ya que no poseen ciclo de vida fiscal
    sc_field_readonly({motivoAnulacion}, 'off');
    sc_btn_display('btn_anular_factura', 'off');
}
