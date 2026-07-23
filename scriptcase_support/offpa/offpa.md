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

- _(Agrega aquí los métodos de PHP...)_

===offpa001_add_01===

//VERIFICAR SI EL CLIENTE ESTÁ REGISTRADO

$strSQL = "SELECT 
    a.id,
	a.tipoClienteFE	
FROM
    offpa002 a
LEFT JOIN
    ofcm001 b ON a.ofcm001_id = b.id
WHERE
    b.codigo = '$ofcm001_codigo' AND a.activeStatus = 1";

sc_lookup(rs, $strSQL);
$offpa002_id = {rs[0][0]};
$offpa002_tipoClienteFE = {rs[0][1]};

//CLIENTE NO ENCONTRADO

if(is_null($offpa002_id)){
echo "Los datos de facturación del cliente aún no están registrados, no es posible realizar la facturación en este momento";
return;
}

//ELIMINAR BORRADORES PREVIOS
clear_drafts($ofcm020_id);

//VERIFICAR SI YA EXISTE REGISTRO DE FE
$strSQL = "SELECT id, offpa002_id FROM offpa001 WHERE ofcm020_id = $ofcm020_id AND tipoDocumento IN ('01', '10') ORDER BY id DESC";
sc_lookup(rs, $strSQL);
$recordId = {rs[0][0]};
$prevoffpa002_id = {rs[0][1]};

if(is_null($recordId)){	
		
	$strSQL = "INSERT INTO
				offpa001 (ofcm020_id)
				VALUES ($ofcm020_id)";
sc_exec_sql($strSQL);
	
	$strSQL = "SELECT id FROM offpa001 WHERE ofcm020_id = $ofcm020_id";
	sc_lookup(rs, $strSQL, "conn_mysql");
	$recordId = {rs[0][0]};
	
	$strSQL = "SELECT MAX(numeroDocumentoFiscal) + 1  from offpa001;";
	sc_lookup(rs, $strSQL, "conn_mysql");
	$recordNum = {rs[0][0]};
	
	$recordNum = ($recordNum <= 0) ? 1 : $recordNum;  
	
	$recordNum = str_pad(strval($recordNum), 10, '0', STR_PAD_LEFT);
$destinoOperacion = ($offpa002_tipoClienteFE == '04') ? '2': '1' ; //1: Panamá. 2: Extranjero.
$tipoDocumento = ($offpa002_tipoClienteFE == '04') ? '10': '01' ; // 01: Factura de operación interna 10: Factura de operación extranjera
$tasa_cambio = null;
	$monedaOperExportacion = null;
	
	if ($destinoOperacion == '2'){
$strSQL = "SELECT tasa_cambio, moneda_trn FROM ofcm020 WHERE id = $ofcm020_id";
sc_lookup(rs, $strSQL);
$tasa_cambio = {rs[0][0]};
$monedaOperExportacion = {rs[0][1]};
}

    $strSQL = "UPDATE
    			offpa001
    			SET
    			numeroDocumentoFiscal = '$recordNum',
    			offpa002_id = $offpa002_id,
    			destinoOperacion = '$destinoOperacion',
    			tipoDocumento = '$tipoDocumento'
    			WHERE id = $recordId
    			";
    if (isset($tasa_cambio)){
    	// Sanitizacion de datos
    	$tipoDeCambio = sc_sql_injection($tasa_cambio);
    	$monedaOperExportacion = sc_sql_injection($monedaOperExportacion);
    	$strSQL = "UPDATE
    				offpa001
    				SET
    				numeroDocumentoFiscal = '$recordNum',
    				offpa002_id = $offpa002_id,
    				destinoOperacion = '$destinoOperacion',
    				tipoDocumento = '$tipoDocumento',
    				tipoDeCambio = $tipoDeCambio,
    				monedaOperExportacion = $monedaOperExportacion
    				WHERE id = $recordId
    				";
    }

    sc_exec_sql($strSQL);


    insertLineItems($recordId, $ofcm020_id);


}
elseif(($prevoffpa002_id != $offpa002_id) && !is_null($prevoffpa002_id)){
$strSQL = "UPDATE
				offpa001
				SET				
				offpa002_id = $offpa002_id		
				WHERE id = $recordId	
				";	
	
	sc_exec_sql($strSQL);

}

function insertLineItems($offpa001Id, $ofcm020Id){	
	$strSQL = "SELECT descripcion, cantidad, moneda, precio_un_loc, total_loc, iva_loc, iva FROM ofcm021 WHERE ofcm020_id = $ofcm020Id";
	sc_lookup(rs, $strSQL);
	if (isset({rs}) && !empty({rs})) {
		// Iterar sobre cada fila devuelta por el sc_lookup
		foreach ({rs} as $row) {
			$descripcion = sc_sql_injection($row[0]);
$cantidad = $row[1]; 
			$moneda = sc_sql_injection($row[2]);
$precioUnitario = $row[3];
			$precioItem = $row[4];			
			$valorITBMS = $row[5];
			$tasaITBMS = ($row[6] == 'EXENTO') ? '00' : '01'; //tasa del ITBMS aplicable al ítem. 00:0% (exento) 01:7%
$valorTotal = $precioItem + $valorITBMS; 
			// Insertar en la tabla de destino
			$insert_sql = "INSERT INTO offpa011 (ofcm020_id, offpa001_id, descripcion,
							cantidad, moneda, precioUnitario, precioItem, tasaITBMS, valorITBMS, valorTotal) 
						   VALUES ($ofcm020Id, $offpa001Id, $descripcion,
						   $cantidad, $moneda, $precioUnitario, $precioItem , '$tasaITBMS',
$valorITBMS, $valorTotal )";
			// Ejecutar el insert
			//echo $insert_sql;
			sc_exec_sql($insert_sql);
}
}
}

sc_redir("form_offpa001", invoiceId = $recordId; sHeader= ''; sStatus= -1; sPrefilledCreditNote = 0);

===offpa001_add_04==

//ELIMINAR BORRADORES PREVIOS
clear_drafts($ofcm020_id);

//VERIFICAR SI YA EXISTE REGISTRO DE FE
$strSQL = "SELECT id FROM offpa001 WHERE ofcm020_id = $ofcm020_id AND tipoDocumento = '04' ORDER BY id DESC";
sc_lookup(rs, $strSQL);
$recordId = {rs[0][0]};

if(!(is_null($recordId))){
//sc_redir("form_offpa001_notasCredito", id = $recordId);
sc_redir("form_offpa001", invoiceId = $recordId; sHeader= ''; sStatus= -1; sPrefilledCreditNote = 0);
return;

}

//Obtener el id del documento de referencia y el tipo de cambio de la nota de crédito
$strSQL = "SELECT doc_ref, tasa_cambio, moneda_trn FROM ofcm020 WHERE id = '$ofcm020_id'";
sc_lookup(rs, $strSQL , "conn_mysql");
$doc_ref = {rs[0][0]};
$tipoDeCambio = {rs[0][1]};
$monedaOperExportacion = {rs[0][2]};

//Obtener el id del doc_ref
$strSQL = "SELECT id FROM ofcm020 WHERE numero = '$doc_ref'";
sc_lookup(rs, $strSQL , "conn_mysql");
$ofcm020_id_ref = {rs[0][0]};
//Obtener CUFE del doc_ref
$strSQL = "SELECT id, cufeDocumento, fechaEmision, offpa002_id, destinoOperacion, condicionesEntrega FROM offpa001 WHERE ofcm020_id= '$ofcm020_id_ref'";
sc_lookup(rs, $strSQL , "conn_mysql");

$offpa001_id_ref = {rs[0][0]};
$cufe = {rs[0][1]};
$strDocDate = {rs[0][2]};
$offpa002_id = {rs[0][3]};
$destinoOperacion = {rs[0][4]};
$condicionesEntrega = {rs[0][5]};

//VALIDAR CUFE Referencia

if(is_null($cufe)){
echo "Aún no se ha emitido el documento de referencia, no es posible emitir la nota de crédito en este momento.";
return;
}

//Obtener el último id
$strSQL = "SELECT max(id) + 1 FROM offpa001";
sc_lookup(rs, $strSQL , "conn_mysql");
$lastId = {rs[0][0]};

$strSQL = "SELECT MAX(numeroDocumentoFiscal)+ 1  from offpa001;";
sc_lookup(rs, $strSQL, "conn_mysql");
$documentNumber = {rs[0][0]};

$documentNumber = str_pad(strval($documentNumber), 10, '0', STR_PAD_LEFT);

$strSQL = "INSERT INTO
			offpa001
			(tipoDocumento, numeroDocumentoFiscal, offpa002_id, ofcm020_id, offpa001_id_documentoRef,
			cufeFEReferenciada, fechaEmisionDocFiscalReferenciado, destinoOperacion, condicionesEntrega, monedaOperExportacion, 				tipoDeCambio)
			VALUES
			(
				'04',
				'$documentNumber',
'$offpa002_id',
				'$ofcm020_id',
'$offpa001_id_ref',
				'$cufe',
'$strDocDate',
				'$destinoOperacion',
'$condicionesEntrega'
)
";

if(isset($tipoDeCambio)){	
	// Sanitizacion de datos
	$monedaOperExportacion = sc_sql_injection($monedaOperExportacion);
$strSQL = "INSERT INTO
			offpa001
			(tipoDocumento, numeroDocumentoFiscal, offpa002_id, ofcm020_id, offpa001_id_documentoRef,
			cufeFEReferenciada, fechaEmisionDocFiscalReferenciado, destinoOperacion, condicionesEntrega, monedaOperExportacion, 				tipoDeCambio)
			VALUES
			(
				'04',
				'$documentNumber',
'$offpa002_id',
				'$ofcm020_id',
'$offpa001_id_ref',
				'$cufe',
'$strDocDate',
				'$destinoOperacion',
'$condicionesEntrega',
$monedaOperExportacion,
$tipoDeCambio
)
";

}

//INSERTAR NUEVO REGISTRO PARA NOTA DE CRÉDITO
sc_exec_sql($strSQL, "conn_mysql");

//OBTENER EL id DEL NUEVO REGISTRO
$strSQL = "SELECT id FROM offpa001 WHERE numeroDocumentoFiscal = '$documentNumber'";
sc_lookup(rs, $strSQL , "conn_mysql");
$newId = {rs[0][0]};

function insertLineItemsCreditNotes($offpa001Id, $ofcm020Id){	
	$strSQL = "SELECT descripcion, cantidad, moneda, precio_un_loc, total_loc, iva_loc, iva FROM ofcm021 WHERE ofcm020_id = $ofcm020Id";
	sc_lookup(rs, $strSQL);
	if (isset({rs}) && !empty({rs})) {
		// Iterar sobre cada fila devuelta por el sc_lookup
		foreach ({rs} as $row) {
			$descripcion = sc_sql_injection($row[0]);
$cantidad = $row[1]; 
			$moneda = sc_sql_injection($row[2]);
$precioUnitario = $row[3];
			$precioItem = abs($row[4]);
$valorITBMS = abs($row[5]);
$tasaITBMS = ($row[6] == 'EXENTO') ? '00' : '01';
$valorTotal = $precioItem + $valorITBMS; 			
			// Insertar en la tabla de destino
			$insert_sql = "INSERT INTO offpa011 (ofcm020_id, offpa001_id, descripcion,
							cantidad, moneda, precioUnitario, precioItem, tasaITBMS, valorITBMS, valorTotal) 
						   VALUES ($ofcm020Id, $offpa001Id, $descripcion,
						   $cantidad, $moneda, $precioUnitario, $precioItem , '$tasaITBMS',
$valorITBMS, $valorTotal )";
			// Ejecutar el insert
			//echo $insert_sql;
			sc_exec_sql($insert_sql);
}
}
}

//INSERTAR LINE ITEMS
insertLineItemsCreditNotes($newId, $ofcm020_id);

sc_redir("form_offpa001", invoiceId = $newId; sHeader= ''; sStatus= -1; sPrefilledCreditNote =1);

//sc_redir("form_offpa001_notasCredito", id = $newId);

===clear_drafts===

// GET Previous Drafts
$strSQL = "SELECT id FROM offpa001 WHERE ofcm020_id = '$ofcm020_id' AND facturacionStatus = 0";

sc_lookup(rs,$strSQL);

if(!isset({rs[0][0]})){
return;
}

foreach ({rs} as $row) {
			$offpa001_id = $row[0]; 
			$strSQL = "DELETE FROM offpa011 WHERE offpa001_id = $offpa001_id";
			sc_exec_sql($strSQL);
$strSQL = "DELETE FROM offpa001 WHERE id = $offpa001_id";
			sc_exec_sql($strSQL);
}

---

## Contenido del Directorio

- _(Los archivos descriptivos, JSONs de muestra y scripts específicos de Panamá se irán organizando en este espacio a medida que se agreguen al espacio de trabajo)._
