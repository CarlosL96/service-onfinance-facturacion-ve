# API REST de Facturación Digital TFHKA (Venezuela)

Este proyecto provee un microservicio en Node.js y Express que actúa como broker/wrapper simplificado para comunicarse con la API de Imprenta Digital de **The Factory HKA Venezuela**. 

Se encarga de gestionar de forma automática en segundo plano la autenticación JWT, el almacenamiento del token en caché y la renovación del mismo.

---

## Requisitos de Instalación

1. Asegúrate de tener instalado **Node.js (v18+)** y **npm**.
2. Clona o copia el proyecto en tu máquina.
3. Instala las dependencias necesarias:
   ```bash
   npm install
   ```
4. Copia el archivo `.env.example` como `.env` y edita las variables de entorno con tus credenciales de integración reales:
   ```env
   PORT=8000
   TFHKA_USUARIO=TuUsuarioDePruebas
   TFHKA_CLAVE=TuClaveDePruebas
   TFHKA_BASE_URL=https://demoemisionv2.thefactoryhka.com.ve
   ```

---

## Endpoints Disponibles

### 1. GET `/health`
Verifica el estado del microservicio local.

---

### 2. POST `/api/v1/emitir/factura`
Envía una petición de emisión de factura ordinaria (`TipoDocumento: "01"`).

---

### 3. POST `/api/v1/emitir/nota-credito`
Envía una petición de emisión de nota de crédito (`TipoDocumento: "02"`), validando los campos de factura afectada obligatorios.

---

### 4. POST `/api/v1/emitir/nota-debito`
Envía una petición de emisión de nota de débito (`TipoDocumento: "03"`), con las mismas reglas de validación que la nota de crédito.

---

### 5. POST `/api/v1/anular`
Solicita la anulación de un documento electrónico del sistema.

---

### 6. POST `/api/v1/descargar-pdf`
Descarga el PDF de un documento previamente emitido en TFHKA.
Por defecto devuelve la estructura JSON con el archivo codificado en Base64. Si se llama con el parámetro `?format=binary` o la cabecera `Accept: application/pdf`, devuelve el flujo de datos del archivo binario `.pdf` directamente.

---

## Campos Obligatorios del JSON de Solicitud

A continuación se listan **únicamente los campos requeridos** en el cuerpo de la solicitud JSON para interactuar con la API:

### A. Emisión de Factura (`POST /api/v1/emitir/factura`)

El cuerpo debe contener el objeto `documentoElectronico` con la siguiente estructura y campos obligatorios:

```json
{
  "documentoElectronico": {
    "Encabezado": {
      "IdentificacionDocumento": {
        "TipoDocumento": "01",                   // Fijo "01"
        "NumeroDocumento": "string",             // Número interno correlativo
        "FechaEmision": "DD/MM/AAAA",            // Fecha de emisión
        "HoraEmision": "hh:mm:ss am/pm",         // Hora de emisión (11 caracteres)
        "Serie": "string",                       // Serie. Si no usa, enviar vacío "" o "nulo"
        "TipoDeVenta": "Interna",                // Modalidad de venta
        "Moneda": "VES"                          // Código ISO de moneda
      },
      "Comprador": {
        "TipoIdentificacion": "string",          // "V", "J", "E", "P", etc.
        "NumeroIdentificacion": "string",        // Cédula o RIF del comprador
        "RazonSocial": "string",                 // Nombre o Razón Social
        "Direccion": "string",                   // Dirección física
        "Pais": "VE",                            // Código ISO de país
        "Telefono": ["string"],                  // Obligatorio si se incluye "Correo"
        "Correo": ["string"]                     // Lista de correos
      },
      "Totales": {
        "NroItems": "string",                    // Número de ítems (ej: "1")
        "MontoGravadoTotal": "string",           // Suma de bases imponibles grabadas con IVA > 0%
        "Subtotal": "string",                    // Suma de todas las bases imponibles
        "TotalIVA": "string",                    // Suma total del IVA cobrado
        "MontoTotalConIVA": "string",            // Subtotal + TotalIVA
        "TotalAPagar": "string",                 // Total general a cobrar (incluye IGTF)
        "MontoEnLetras": "string",               // Total general expresado en letras
        "ImpuestosSubtotal": [
          {
            "CodigoTotalImp": "string",          // Código de alícuota (ej: "G" para 16%)
            "AlicuotaImp": "string",             // Porcentaje alícuota (ej: "16.00")
            "ValorTotalImp": "string"            // Monto de IVA total para esta alícuota
          }
        ],
        "FormasPago": [
          {
            "Descripcion": "string",             // Descripción de pago (ej: "Efectivo")
            "Fecha": "DD/MM/AAAA",               // Fecha del pago
            "Forma": "string",                   // Código del método de pago (ej: "01")
            "Monto": "string",                   // Monto asignado a este método de pago
            "Moneda": "VES",                     // Moneda del pago
            "TipoChange": "string"               // Requerido solo si el pago es en divisas
          }
        ]
      }
    },
    "DetallesItems": [
      {
        "NumeroLinea": "string",                 // Correlativo del ítem ("1", "2")
        "IndicadorBienoServicio": "string",      // "1" = Bien, "2" = Servicio
        "Descripcion": "string",                 // Descripción del ítem
        "Cantidad": "string",                    // Cantidad (ej: "1.00")
        "UnidadMedida": "string",                // Unidad (ej: "UNI")
        "PrecioUnitario": "string",              // Precio unitario base
        "PrecioItem": "string",                  // Total base neto (Precio * Cantidad - Desc)
        "CodigoImpuesto": "string",              // Código de IVA aplicado (ej: "G")
        "TasaIVA": "string",                     // Porcentaje del IVA (ej: "16")
        "ValorIVA": "string",                    // Impuesto total para este ítem
        "ValorTotalItem": "string"               // PrecioItem + ValorIVA
      }
    ]
  }
}
```

---

### B. Emisión de Notas de Crédito y Débito (`POST /api/v1/emitir/nota-credito` y `POST /api/v1/emitir/nota-debito`)

Requiere **exactamente la misma estructura y campos de Factura Básica** (con `TipoDocumento` en `"02"` o `"03"`), agregando obligatoriamente los siguientes campos de afectación dentro de `Encabezado.IdentificacionDocumento`:

*   **`NumeroFacturaAfectada`**: `string` - Número de la factura modificada/anulada.
*   **`FechaFacturaAfectada`**: `DD/MM/AAAA` - Fecha de emisión de la factura afectada.
*   **`MontoFacturaAfectada`**: `string` - Monto total con IVA de la factura afectada.
*   **`ComentarioFacturaAfectada`**: `string` - Motivo de la afectación (requerido por el servicio TFHKA).
*   **`SerieFacturaAfectada`**: `string` - Serie de la factura afectada (enviar vacío `""` si no usa series).

---

### C. Anulación de Documentos (`POST /api/v1/anular`)

El cuerpo debe contener un JSON plano con los siguientes campos requeridos:

```json
{
  "tipoDocumento": "string",       // Código del documento a anular ("01"=Factura, "03"=Nota Débito)
  "numeroDocumento": "string",     // Número del documento a anular
  "motivoAnulacion": "string",     // Explicación de la anulación
  "fechaAnulacion": "DD/MM/AAAA",  // Fecha de la anulación
  "horaAnulacion": "hh:mm:ss am/pm",// Hora de la anulación (11 caracteres, formato de 12 horas)
  "serie": "string"                // Enviar vacío "" si no se utiliza
}
```

---

### D. Descarga de PDF (`POST /api/v1/descargar-pdf`)

El cuerpo debe contener un JSON plano con los siguientes campos requeridos:

```json
{
  "tipoDocumento": "string",       // Código del tipo de documento ("01"=Factura, "02"=Nota Crédito, etc.)
  "numeroDocumento": "string",     // Número del documento a descargar
  "serie": "string"                // Enviar vacío "" si no se utiliza
}
```

---

## Ejecución del Proyecto

### Modo Desarrollo (Auto-recarga en cambios)
```bash
npm run dev
```

### Ejecutar Pruebas Automatizadas (E2E)
El proyecto incluye un script de prueba de flujo de facturación extremo a extremo (`Emitir Factura ➔ Emitir Nota de Crédito ➔ Emitir Nota de Débito ➔ Anular Nota de Débito`):
```bash
npm test
```
