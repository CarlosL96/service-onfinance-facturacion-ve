<?php
/**
 * =================================================================================
 * BOTÓN DE SCRIPTCASE - btn_descarga_pdf (Evento onExecute / Click)
 * =================================================================================
 * Este código debe colocarse en el código de ejecución del botón "btn_descarga_pdf"
 * del formulario form_offve001 en Scriptcase.
 * 
 * Invoca el método PHP del formulario "descarga_documento_pdf()" dentro de un
 * bloque try-catch. Si ocurre un error, muestra el error de forma directa en pantalla.
 * =================================================================================
 */

try {
    // Invocar el método PHP del formulario que descarga y sirve el PDF
    descarga_documento_pdf();
} catch (Exception $e) {
    // Capturar y mostrar el error de forma detallada para depuración
    echo "<div style='color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 12px; margin: 10px 0; border-radius: 4px; font-family: Arial, sans-serif; font-size: 14px;'>";
    echo "<strong>Error al Descargar PDF:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "<small style='color: #555;'>Ocurrido en: " . htmlspecialchars($e->getFile()) . " línea " . $e->getLine() . "</small>";
    echo "</div>";
}
