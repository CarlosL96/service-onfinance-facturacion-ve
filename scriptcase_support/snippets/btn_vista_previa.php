<?php
/**
 * =================================================================================
 * BOTÓN DE SCRIPTCASE - btn_vista_previa (Evento onExecute / Click)
 * =================================================================================
 * Este código debe colocarse en el evento de ejecución del botón "btn_vista_previa"
 * del formulario form_offve001 en Scriptcase.
 * 
 * Invoca el método PHP del formulario "vista_previa_factura()" dentro de un
 * bloque try-catch para generar y previsualizar en el navegador el PDF preliminar
 * sin consumir folio ni comunicarse con el portal fiscal de The Factory HKA.
 * =================================================================================
 */

try {
    // Invocar el método PHP del formulario que genera y transmite el PDF de vista previa
    vista_previa_factura();
} catch (Exception $e) {
    // Capturar y mostrar el error de forma limpia y legible
    echo "<div style='color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 14px; margin: 12px 0; border-radius: 6px; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);'>";
    echo "<div style='font-weight: bold; margin-bottom: 4px;'><i class='fa fa-exclamation-triangle'></i> Error al Generar Vista Previa del PDF</div>";
    echo "<span>" . htmlspecialchars($e->getMessage()) . "</span>";
    echo "</div>";
}
