# Integración de Facturación Electrónica - Referencia de Panamá (OFFPA)

Este directorio contiene la documentación, scripts y archivos de referencia relacionados con la integración de facturación electrónica de **Panamá**.

---

## Propósito y Contexto

Durante el desarrollo de la facturación electrónica para Venezuela, utilizaremos la estructura, flujos y patrones de la integración de Panamá (`offpa`) como **punto de referencia técnico**. 

Aunque los marcos regulatorios y los proveedores de firma fiscal son diferentes en cada país (TFHKA en Venezuela vs. regulaciones PAC en Panamá), existen similitudes arquitectónicas en el ERP que podemos reutilizar:
1.  **Estructura de Flujo**: La forma de procesar cabeceras, detalles e interactuar con Scriptcase.
2.  **Mapeo de Datos**: Los métodos de almacenamiento intermedio en tablas del ERP (como `offve001` y `offve011`).
3.  **Relación con Formas de Pago**: La obtención y normalización de información desde tablas comunes del sistema.

---

## Flujo de Trabajo en Scriptcase (Referencia Panamá)

### 1. Botón de Enlace (`facturacion_electronica_pa`)
En el formulario de Scriptcase `form_ofcm020` se cuenta con un botón de tipo enlace llamado `facturacion_electronica_pa` que direcciona a la aplicación de tipo Blank **`offpa001_link`**.

### 2. Snippet en Evento `onExecute` (en `offpa001_link`)
```php
// Rutina direccionamiento al formulario de facturación electrónica de Panamá
$ofcm020_id = [ofcm020_id];
$ofcm001_codigo = [ofcm001_codigo];
$ofcm020_tipo_factura = [ofcm020_tipo_factura];

// Factura de operación interna tipoDocumento: 01
if ($ofcm020_tipo_factura == "F") {
    offpa001_add_01($ofcm020_id, $ofcm001_codigo);    
}
// Notas de crédito tipoDocumento: 04
elseif ($ofcm020_tipo_factura == "C") {
    offpa001_add_04($ofcm020_id, $ofcm001_codigo);    
} else {
    echo "El tipo de factura no es soportado por este módulo.";
}
```

---

## Métodos PHP de `offpa001_link` (A definir por el usuario)

> [!NOTE]
> Coloca debajo los métodos internos de PHP definidos en la aplicación `offpa001_link` (por ejemplo, `offpa001_add_01`, `offpa001_add_04`, etc.) para que podamos analizarlos y diseñar la versión homóloga para la integración de Venezuela.

*   *(Agrega aquí los métodos de PHP...)*

---

## Contenido del Directorio
*   *(Los archivos descriptivos, JSONs de muestra y scripts específicos de Panamá se irán organizando en este espacio a medida que se agreguen al espacio de trabajo).*
