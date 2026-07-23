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

## Contenido del Directorio
*   *(Los archivos descriptivos, JSONs de muestra y scripts específicos de Panamá se irán organizando en este espacio a medida que se agreguen al espacio de trabajo).*
