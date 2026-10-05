# Soporte de Integración para Scriptcase

Esta carpeta contiene recursos, guías, documentación y snippets de código PHP diseñados exclusivamente como **material de soporte** para la integración del ERP en **Scriptcase** con la API REST local de Facturación Digital.

Dado que el entorno de Scriptcase es de desarrollo visual y no se gestiona de forma directa mediante archivos de código en este repositorio, todo el código PHP, macros de Scriptcase y consultas MySQL de apoyo se guardarán aquí para que el desarrollador pueda copiarlos y pegarlos en sus eventos correspondientes de Scriptcase.

---

## Estructura de la Carpeta

A medida que avancemos en la integración, organizaremos los recursos de la siguiente manera:

*   `/snippets/`: Archivos PHP independientes que contienen la lógica de extracción de base de datos MySQL, serialización a JSON, llamadas HTTP y procesos del ERP:
    *   `offve001_link.php`: Creación de borrador fiscal e inicialización del flujo.
    *   `vista_previa_factura.php`: Generación y descarga directa del PDF preliminar (Draft).
    *   `emitir_factura.php`: Orquestación y envío fiscal a la API Node.js / TFHKA, actualización de estatus a 'I'.
    *   `descarga_documento_pdf.php`: Descarga del PDF oficial emitido con número de control.
    *   `btn_vista_previa.php`, `btn_facturar.php`, `btn_descarga_pdf.php`: Controladores de botones en `form_offve001`.
    *   `anular_factura_erp.php`: Método PHP para anular la factura en el ERP (`status = 'A'`) validando N/C en `ofcm020` y `offve001`.
    *   `btn_anular_factura.php`: Evento de ejecución para el botón "Anular" en `form_ofcm020`.
    *   `onload_ofcm020_motivo.php`: Evento `onLoad`/`onRecord` en `form_ofcm020` para cargar `{motivoAnulacion}` y controlar permisos.
*   `/database/`: Scripts SQL para la creación de tablas o alteración de columnas fiscales recomendadas en MySQL (`schema_updates.sql`).

---

## Configuración Base en Scriptcase

1.  **Conexión SQL**: Asegúrate de tener configurada la conexión principal a tu base de datos MySQL en tu proyecto de Scriptcase.
2.  **Llamada HTTP**: Scriptcase provee la macro `sc_http_request` o `sc_webservice` que se puede usar para consumir la API de Node.js. También puedes utilizar la biblioteca nativa `cURL` de PHP.
3.  **Endpoint Local**:
    *   Facturas: `http://localhost:8000/api/v1/emitir/factura`
    *   Notas de Crédito: `http://localhost:8000/api/v1/emitir/nota-credito`
    *   Notas de Débito: `http://localhost:8000/api/v1/emitir/nota-debito`
    *   Vista Previa PDF: `http://localhost:8000/api/v1/vista-previa-pdf`
    *   Anulación Técnica TFHKA: `http://localhost:8000/api/v1/anular`
    *   Descarga PDF Oficial: `http://localhost:8000/api/v1/descargar-pdf`

