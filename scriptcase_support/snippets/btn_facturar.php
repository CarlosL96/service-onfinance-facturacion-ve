<?php
/**
 * =================================================================================
 * BOTÓN DE SCRIPTCASE - btn_facturar (Evento onExecute / Click)
 * =================================================================================
 * Este código debe colocarse en el código de ejecución del botón "btn_facturar"
 * del formulario form_offve001 en Scriptcase.
 * 
 * Invoca el método PHP del formulario "emitir_factura()" dentro de un bloque try-catch.
 * Si ocurre un error, atrapa la excepción y lo muestra en pantalla con un estilo limpio.
 * =================================================================================
 */

try {
    // Invocar la lógica de emisión fiscal (método PHP de la aplicación)
    emitir_factura();
} catch (Exception $e) {
    // Capturar y mostrar el mensaje de error arrojado por la excepción
    echo "<div style='color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 12px; margin: 10px 0; border-radius: 4px; font-family: Arial, sans-serif; font-size: 14px;'>";
    echo "<strong>Error en Emisión Fiscal:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
