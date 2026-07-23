# Dudas de Negocio y Configuración ERP para el Equipo Directivo

Este documento recopila las definiciones de negocio, fiscales y técnicas del ERP que deben ser validadas con el equipo directivo o el área contable para culminar con éxito la integración de Facturación Digital.

---

## 1. Clasificación de Ítems: Bien o Servicio (`IndicadorBienoServicio`)

- **Duda**: ¿El ERP cuenta actualmente con un campo en el catálogo de productos/servicios para diferenciar si un ítem es un **Bien físico** (`1`) o un **Servicio** (`2`)?
- **Contexto Técnico**: La API de TFHKA exige clasificar cada línea detallada del documento de forma obligatoria en la propiedad `IndicadorBienoServicio` con los códigos `"1"` (Bienes) o `"2"` (Servicios).
- **Estado en el Script**: Actualmente el snippet está configurado por defecto con valor `"1"` (Bien).
- **Puntos a decidir**:
  - Si el ERP **tiene** la diferenciación (ej. a través de una categoría de producto o el código de cuenta de ingresos en `ofin009`): Necesitamos conocer qué columna identifica esto para mapearlo en la consulta de `ofcm021`.
  - Si el ERP **no tiene** la diferenciación: ¿Se debe añadir un campo/bandera en la base de datos de productos para clasificarlo, o es aceptable asumir un valor por defecto fijo para todas las facturas de la empresa?

## Respuesta -> información contenida en la tabla ofin009 (ya está en la carpeta database) si tipo = 'S' entonces es Servicio, cualquier otro es Bien

## 2. Reporte y Tratamiento del IGTF (3%)

- **Duda**: ¿Cómo se reportará y mostrará el IGTF (Impuesto a las Grandes Transacciones Financieras) en las facturas digitales?
- **Contexto Técnico**: En los ejemplos de base de datos (`ofcm021_sample.csv`), el IGTF del 3% figura como una **línea de detalle** más en la factura (código `*IGT-001`). Sin embargo, el estándar de facturación electrónica de TFHKA tiene un nodo específico en los totales llamado `TotalIGTF` y `TotalIGTF_VES` para reportar este impuesto.
- **Puntos a decidir**:
  - ¿El IGTF debe reportarse como una línea de ítem más (exento de IVA)? (Opción simple).
  - ¿O debe excluirse de las líneas de detalle y cargarse en el nodo de impuestos de totales de la API de TFHKA? (Opción recomendada fiscalmente si el sistema emisor lo soporta).

## Respuesta -> Debe excluirse de las líneas de detalle y cargarse en el nodo de impuestos de totales de la API de TFHKA? (Opción recomendada fiscalmente si el sistema emisor lo soporta)

## 3. Mapeo de Formas de Pago

- **Duda**: ¿Cómo se relacionarán las formas de pago internas de la empresa con los códigos del catálogo fiscal de Venezuela?
- **Contexto Técnico**: La API requiere que cada factura indique al menos una forma de pago con su respectivo código (Catálogo 6 de TFHKA). En el snippet actual se encuentra quemado como `"01"` (Efectivo).
- **Puntos a decidir**:
  - Necesitamos definir la equivalencia para las formas de pago comunes de la empresa:
    - Transferencias Bancarias (nacionales e internacionales).
    - Depósitos.
    - Pagos en divisas (efectivo USD).
    - Créditos / Cuentas por cobrar.

Respuesta -> lo tomaremos de la tabla offve001, para ello se modificará el flujo, por ahora este cambio déjalo como pendiente.
