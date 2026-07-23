# Soporte de Integración para Scriptcase

Esta carpeta contiene recursos, guías, documentación y snippets de código PHP diseñados exclusivamente como **material de soporte** para la integración del ERP en **Scriptcase** con la API REST local de Facturación Digital.

Dado que el entorno de Scriptcase es de desarrollo visual y no se gestiona de forma directa mediante archivos de código en este repositorio, todo el código PHP, macros de Scriptcase y consultas MySQL de apoyo se guardarán aquí para que el desarrollador pueda copiarlos y pegarlos en sus eventos correspondientes de Scriptcase.

---

## Estructura de la Carpeta

A medida que avancemos en la integración, organizaremos los recursos de la siguiente manera:

*   `/snippets/`: Archivos PHP independientes que contienen la lógica de extracción de base de datos MySQL, serialización a JSON y llamadas HTTP.
*   `/database/`: Scripts SQL para la creación de tablas o alteración de columnas fiscales recomendadas en MySQL.

---

## Configuración Base en Scriptcase

1.  **Conexión SQL**: Asegúrate de tener configurada la conexión principal a tu base de datos MySQL en tu proyecto de Scriptcase.
2.  **Llamada HTTP**: Scriptcase provee la macro `sc_http_request` o `sc_webservice` que se puede usar para consumir la API de Node.js. También puedes utilizar la biblioteca nativa `cURL` de PHP.
3.  **Endpoint Local**:
    *   Facturas: `http://localhost:8000/api/v1/emitir/factura`
    *   Notas de Crédito: `http://localhost:8000/api/v1/emitir/nota-credito`
    *   Notas de Débito: `http://localhost:8000/api/v1/emitir/nota-debito`
    *   Anulación: `http://localhost:8000/api/v1/anular`
    *   Descarga PDF: `http://localhost:8000/api/v1/descargar-pdf`
