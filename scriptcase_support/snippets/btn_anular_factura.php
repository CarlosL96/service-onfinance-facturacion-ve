<?php
/**
 * =================================================================================
 * BOTÓN DE SCRIPTCASE - btn_anular_factura (Evento onExecute / Click)
 * =================================================================================
 * Este código debe colocarse en el evento onExecute del botón "btn_anular_factura"
 * del formulario form_ofcm020 en Scriptcase.
 * 
 * Invoca el método PHP del formulario "anular_factura_erp()" dentro de un bloque try-catch.
 * En caso de que no cumpla las validaciones de negocio, atrapa la excepción y muestra
 * un cuadro de alerta elegante en el formulario sin romper el flujo.
 * =================================================================================
 */

try {
    // 1. Invocar la lógica de anulación en el ERP
    anular_factura_erp();

} catch (Exception $e) {
    // 2. Capturar y presentar el error de validación de manera limpia en Scriptcase
    $mensaje_error = $e->getMessage();
    
    echo "<div style='color: #842029; background-color: #f8d7da; border: 1px solid #f5c2c7; padding: 14px 18px; margin: 12px 0; border-radius: 6px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; font-size: 13px; line-height: 1.5;'>";
    echo "<div style='display: flex; align-items: center; margin-bottom: 6px;'>";
    echo "<svg style='width: 20px; height: 20px; margin-right: 8px; fill: currentColor;' viewBox='0 0 20 20'><path fill-rule='evenodd' d='M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z' clip-rule='evenodd'></path></svg>";
    echo "<strong>Atención - No es posible anular la factura:</strong>";
    echo "</div>";
    echo "<div style='padding-left: 28px;'>" . $mensaje_error . "</div>";
    echo "</div>";
}
