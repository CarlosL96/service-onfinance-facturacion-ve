import express from 'express';
import { config } from './config.js';
import { tfhkaClient, tfhkaProdClient, tfhkaDemoClient } from './client.js';
import { renderPreviewPDF } from './previewRenderer.js';
import { filterDevAllowedEmails } from './utils/devEmailFilter.js';

const app = express();
app.use(express.json({ limit: '10mb' }));

// Middleware para registrar la actividad de la API en consola (PM2 captura esto en sus logs)
app.use((req, res, next) => {
  const start = Date.now();
  const { method, url } = req;
  const ip = req.headers['x-forwarded-for'] || req.socket.remoteAddress;

  res.on('finish', () => {
    const duration = Date.now() - start;
    const status = res.statusCode;
    
    // Logs simplificados de healthcheck para evitar spam en logs
    if (url === '/health') {
      console.log(`[HEALTHCHECK] ${method} ${url} - Status: ${status} (${duration}ms)`);
    } else {
      console.log(`[API REQUEST] ${method} ${url} - Status: ${status} - IP: ${ip} - Duration: ${duration}ms`);
    }
  });

  next();
});

// Middleware para formatear las respuestas de error
const errorHandler = (err, req, res, next) => {
  console.error('Express Error Handler:', err);
  const status = err.response?.status || 500;
  const message = err.response?.data?.mensaje || err.message || 'Error interno del servidor';
  const validations = err.response?.data?.validaciones || [];
  
  res.status(status).json({
    status: 'error',
    message,
    validations
  });
};

// Endpoint de Healthcheck (Público)
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    timestamp: new Date(),
    uptime: process.uptime(),
    env: process.env.NODE_ENV || 'development'
  });
});

// Middleware de autenticación mediante API Key para rutas protegidas
app.use((req, res, next) => {
  // Si no se configuró API_KEY en el entorno, permitir acceso (modo abierto)
  if (!config.apiKey) {
    return next();
  }

  // Se acepta la clave en cabecera 'x-api-key' o 'Authorization: Bearer <key>'
  const clientKey = req.headers['x-api-key'] ||
    (req.headers['authorization']?.startsWith('Bearer ') ? req.headers['authorization'].slice(7).trim() : null);

  if (!clientKey || clientKey !== config.apiKey) {
    return res.status(401).json({
      status: 'error',
      message: 'No autorizado: API Key no proporcionada o inválida.',
      validations: ['Cabecera x-api-key requerida']
    });
  }

  next();
});

// Helper para establecer el tipo de documento respetando la capitalización original (PascalCase o camelCase)
function setTipoDocumento(doc, tipo) {
  if (doc.Encabezado) {
    if (!doc.Encabezado.IdentificacionDocumento) {
      doc.Encabezado.IdentificacionDocumento = {};
    }
    doc.Encabezado.IdentificacionDocumento.TipoDocumento = tipo;
  } else {
    if (!doc.encabezado) doc.encabezado = {};
    if (!doc.encabezado.identificacionDocumento) {
      doc.encabezado.identificacionDocumento = {};
    }
    doc.encabezado.identificacionDocumento.tipoDocumento = tipo;
  }
}

// Helper para obtener el nodo IdentificacionDocumento sin importar capitalización
function getIdentificacionDocumento(doc) {
  const encabezado = doc.Encabezado || doc.encabezado || {};
  return encabezado.IdentificacionDocumento || encabezado.identificacionDocumento || {};
}

// Helper para aplicar directiva de seguridad de correos en ambiente de desarrollo
function applyDevEmailInterception(doc, req) {
  const clientEnv = (req.headers['x-environment'] || req.headers['x-client-env'] || 'dev').toLowerCase();
  
  if (clientEnv === 'dev' || config.nodeEnv !== 'production' || config.tfhka.baseUrl?.includes('demo')) {
    const encabezado = doc.Encabezado || doc.encabezado;
    if (encabezado) {
      const comp = encabezado.Comprador || encabezado.comprador;
      if (comp) {
        const raw = comp.Correo || comp.correo;
        const filtered = filterDevAllowedEmails(raw);
        console.log(`[DIRECTIVA DESARROLLO] Filtrando correos para dominio ${config.devAllowedDomain}: [${filtered.join(', ')}] (Originales: [${Array.isArray(raw) ? raw.join(', ') : (raw || 'ninguno')}])`);
        
        if (comp.Correo !== undefined) comp.Correo = filtered;
        if (comp.correo !== undefined) comp.correo = filtered;
        if (!comp.Correo && !comp.correo) comp.Correo = filtered;
      }
    }
  }
}

/**
 * Endpoint para emitir Facturas (tipoDocumento = "01")
 */
app.post('/api/v1/emitir/factura', async (req, res, next) => {
  try {
    const rawBody = req.body;

    if (!rawBody || Object.keys(rawBody).length === 0) {
      return res.status(400).json({
        status: 'error',
        message: 'El cuerpo de la petición no puede estar vacío.'
      });
    }

    const documentoElectronico = rawBody.documentoElectronico || rawBody;

    // Forzar tipo de documento de factura
    setTipoDocumento(documentoElectronico, '01');

    // Interceptar correo en desarrollo si aplica
    applyDevEmailInterception(documentoElectronico, req);

    const ident = getIdentificacionDocumento(documentoElectronico);
    console.log(`[API] => EMISIÓN FACTURA - Solicitando emisión para Documento Nro: ${ident.NumeroDocumento || ident.numeroDocumento}`);
    
    const result = await tfhkaClient.emitirDocumento(documentoElectronico);
    res.status(200).json(result);
  } catch (error) {
    next(error);
  }
});

/**
 * Endpoint para emitir Notas de Crédito (tipoDocumento = "02")
 */
app.post('/api/v1/emitir/nota-credito', async (req, res, next) => {
  try {
    const rawBody = req.body;

    if (!rawBody || Object.keys(rawBody).length === 0) {
      return res.status(400).json({
        status: 'error',
        message: 'El cuerpo de la petición no puede estar vacío.'
      });
    }

    const documentoElectronico = rawBody.documentoElectronico || rawBody;
    const ident = getIdentificacionDocumento(documentoElectronico);

    const numFactura = ident.NumeroFacturaAfectada || ident.numeroFacturaAfectada;
    const fechaFactura = ident.FechaFacturaAfectada || ident.fechaFacturaAfectada;
    const montoFactura = ident.MontoFacturaAfectada || ident.montoFacturaAfectada;
    
    // Validar campos obligatorios de factura afectada para notas de crédito
    if (!numFactura || !fechaFactura || !montoFactura) {
      return res.status(400).json({
        status: 'error',
        message: 'Para emitir una Nota de Crédito se requieren obligatoriamente los campos de afectación: NumeroFacturaAfectada, FechaFacturaAfectada y MontoFacturaAfectada.'
      });
    }

    // Forzar tipo de documento de nota de crédito
    setTipoDocumento(documentoElectronico, '02');

    // Interceptar correo en desarrollo si aplica
    applyDevEmailInterception(documentoElectronico, req);

    console.log(`[API] => EMISIÓN NOTA CRÉDITO - Documento Nro: ${ident.NumeroDocumento || ident.numeroDocumento} - Afecta Factura Nro: ${numFactura}`);

    const result = await tfhkaClient.emitirDocumento(documentoElectronico);
    res.status(200).json(result);
  } catch (error) {
    next(error);
  }
});

/**
 * Endpoint para emitir Notas de Débito (tipoDocumento = "03")
 */
app.post('/api/v1/emitir/nota-debito', async (req, res, next) => {
  try {
    const rawBody = req.body;

    if (!rawBody || Object.keys(rawBody).length === 0) {
      return res.status(400).json({
        status: 'error',
        message: 'El cuerpo de la petición no puede estar vacío.'
      });
    }

    const documentoElectronico = rawBody.documentoElectronico || rawBody;
    const ident = getIdentificacionDocumento(documentoElectronico);

    const numFactura = ident.NumeroFacturaAfectada || ident.numeroFacturaAfectada;
    const fechaFactura = ident.FechaFacturaAfectada || ident.fechaFacturaAfectada;
    const montoFactura = ident.MontoFacturaAfectada || ident.montoFacturaAfectada;
    
    // Validar campos obligatorios de factura afectada para notas de débito
    if (!numFactura || !fechaFactura || !montoFactura) {
      return res.status(400).json({
        status: 'error',
        message: 'Para emitir una Nota de Débito se requieren obligatoriamente los campos de afectación: NumeroFacturaAfectada, FechaFacturaAfectada y MontoFacturaAfectada.'
      });
    }

    // Forzar tipo de documento de nota de débito
    setTipoDocumento(documentoElectronico, '03');

    // Interceptar correo en desarrollo si aplica
    applyDevEmailInterception(documentoElectronico, req);

    console.log(`[API] => EMISIÓN NOTA DÉBITO - Documento Nro: ${ident.NumeroDocumento || ident.numeroDocumento} - Afecta Factura Nro: ${numFactura}`);

    const result = await tfhkaClient.emitirDocumento(documentoElectronico);
    res.status(200).json(result);
  } catch (error) {
    next(error);
  }
});

/**
 * Endpoint para Anular un documento
 */
app.post('/api/v1/anular', async (req, res, next) => {
  try {
    const datosAnulacion = req.body;

    // Validar campos mínimos requeridos para anulación
    const camposRequeridos = ['tipoDocumento', 'numeroDocumento', 'motivoAnulacion', 'fechaAnulacion', 'horaAnulacion'];
    const camposFaltantes = camposRequeridos.filter(campo => !datosAnulacion[campo]);

    if (camposFaltantes.length > 0) {
      return res.status(400).json({
        status: 'error',
        message: `Faltan los siguientes campos obligatorios para anulación: ${camposFaltantes.join(', ')}`
      });
    }

    // Si no se suministra la serie, se envía como cadena vacía obligatoria
    if (datosAnulacion.serie === undefined) {
      datosAnulacion.serie = '';
    }

    console.log(`[API] => ANULACIÓN - Solicitando anulación para Documento Nro: ${datosAnulacion.numeroDocumento} - Tipo: ${datosAnulacion.tipoDocumento} - Motivo: ${datosAnulacion.motivoAnulacion}`);

    const result = await tfhkaClient.anularDocumento(datosAnulacion);
    res.status(200).json(result);
  } catch (error) {
    next(error);
  }
});

/**
 * Endpoint para obtener/descargar el archivo PDF de un documento
 */
app.post('/api/v1/descargar-pdf', async (req, res, next) => {
  try {
    const datosDescarga = req.body;

    const tipoDocumento = datosDescarga.tipoDocumento || datosDescarga.TipoDocumento;
    const numeroDocumento = datosDescarga.numeroDocumento || datosDescarga.NumeroDocumento;
    const serie = datosDescarga.serie !== undefined ? datosDescarga.serie : (datosDescarga.Serie !== undefined ? datosDescarga.Serie : '');

    if (!tipoDocumento || !numeroDocumento) {
      return res.status(400).json({
        status: 'error',
        message: 'Faltan los campos obligatorios: tipoDocumento y numeroDocumento.'
      });
    }

    console.log(`[API] => DESCARGA PDF - Solicitando archivo para Documento Nro: ${numeroDocumento} - Tipo: ${tipoDocumento}`);

    const result = await tfhkaClient.descargarArchivo({
      tipoDocumento,
      numeroDocumento,
      serie
    });

    // Si el cliente pide explícitamente binario (ej. query params o header), enviamos el PDF decodificado
    const pdfBase64 = result ? (result.Archivo || result.archivo) : null;
    if (pdfBase64) {
      if (req.query.format === 'binary' || req.headers.accept === 'application/pdf') {
        const pdfBuffer = Buffer.from(pdfBase64, 'base64');
        res.setHeader('Content-Type', 'application/pdf');
        res.setHeader('Content-Disposition', `attachment; filename=documento_${numeroDocumento}.pdf`);
        return res.send(pdfBuffer);
      }
    }

    // Por defecto, enviamos el JSON original de TFHKA (que contiene la representación base64)
    res.status(200).json(result);
  } catch (error) {
    next(error);
  }
});

/**
 * Helper para generar un correlativo único virtual en TFHKA Demo
 * Base: 90000000 + id (con sufijo de versión si hay reintentos)
 */
function buildDemoVirtualDocumentNumber(originalDocNum, attempt = 0) {
  const numericOnly = String(originalDocNum || '').replace(/[^0-9]/g, '');
  const baseNum = numericOnly ? parseInt(numericOnly, 10) : Math.floor(1000 + Math.random() * 9000);
  
  if (attempt === 0) {
    // Primer intento: base virtual 90000000 + número original (módulo 10 millones)
    const virtualNum = 90000000 + (baseNum % 10000000);
    return String(virtualNum).padStart(8, '0');
  }
  
  // Reintentos si TFHKA Demo responde 201 (documento duplicado):
  // Combinar base con timestamp y número de intento para correlativo único garantizado
  const timeSlice = Date.now() % 100000;
  const retryNum = 90000000 + ((baseNum + timeSlice + attempt) % 10000000);
  return String(retryNum).padStart(8, '0');
}

/**
 * Endpoint para generar la Vista Previa (Preview/Proforma) OFICIAL en PDF
 * utilizando el motor FastReport de The Factory HKA (TFHKA Demo Sandbox).
 * 
 * - NO altera la base de datos de producción (offve001).
 * - Utiliza correlativo virtual (base 90000000 + id) con reintento automático si ya existe.
 * - Limpia estrictamente los correos (Comprador.Correo = []) para cero envíos a clientes.
 * - Descarga y entrega el PDF oficial de TFHKA en binario o JSON Base64.
 */
app.post('/api/v1/vista-previa/hka', async (req, res, next) => {
  try {
    const rawBody = req.body;
    if (!rawBody || Object.keys(rawBody).length === 0) {
      return res.status(400).json({
        status: 'error',
        message: 'El cuerpo de la petición no puede estar vacío.'
      });
    }

    // Clonar profundamente el documento para no mutar el payload recibido
    const docClonado = JSON.parse(JSON.stringify(rawBody.documentoElectronico || rawBody));
    const encabezado = docClonado.Encabezado || docClonado.encabezado;
    if (!encabezado) {
      return res.status(400).json({
        status: 'error',
        message: 'El documento electrónico no contiene la sección Encabezado.'
      });
    }

    const identDoc = encabezado.IdentificacionDocumento || encabezado.identificacionDocumento || {};
    const comprador = encabezado.Comprador || encabezado.comprador || {};
    const tipoDoc = identDoc.TipoDocumento || identDoc.tipoDocumento || '01';
    const originalDocNum = identDoc.NumeroDocumento || identDoc.numeroDocumento || '00000001';

    // 1. POLÍTICA DE SEGURIDAD ESTRICTA PARA VISTA PREVIA:
    // Vaciar completamente la lista de correos para garantizar que TFHKA Demo no envíe ningún email
    comprador.Correo = [];
    if (comprador.correo !== undefined) {
      comprador.correo = [];
    }

    console.log(`[API] => VISTA PREVIA TFHKA DEMO - Iniciando generación para Documento Original: ${originalDocNum} (Tipo: ${tipoDoc})`);

    // 2. EMISIÓN EN TFHKA DEMO CON REINTENTO AUTOMÁTICO DE CORRELATIVO
    let emitidaExitosa = false;
    let demoDocNum = '';
    let emisionResult = null;
    const maxIntentos = 5;

    for (let intento = 0; intento < maxIntentos; intento++) {
      demoDocNum = buildDemoVirtualDocumentNumber(originalDocNum, intento);
      identDoc.NumeroDocumento = demoDocNum;
      if (identDoc.numeroDocumento !== undefined) {
        identDoc.numeroDocumento = demoDocNum;
      }

      console.log(`[API] => VISTA PREVIA TFHKA DEMO - Intento ${intento + 1}/${maxIntentos} con Nro Virtual: ${demoDocNum}...`);

      try {
        emisionResult = await tfhkaDemoClient.emitirDocumento(docClonado);

        const codigoResp = String(emisionResult?.codigo || '');
        const mensajeResp = String(emisionResult?.mensaje || '');
        const esDuplicado = codigoResp === '201' || 
                            codigoResp === '99' || 
                            mensajeResp.toLowerCase().includes('ya ha sido enviado') ||
                            mensajeResp.toLowerCase().includes('duplicado') ||
                            mensajeResp.toLowerCase().includes('ya existe');

        if (codigoResp === '200') {
          console.log(`[API] => VISTA PREVIA TFHKA DEMO - Documento ${demoDocNum} aceptado exitosamente por Demo (Código 200).`);
          emitidaExitosa = true;
          break;
        } else if (esDuplicado) {
          // Documento duplicado en Demo: reintentar con correlativo fresco
          console.log(`[API] => VISTA PREVIA TFHKA DEMO - Documento ${demoDocNum} ya existía en Demo (Código ${codigoResp}: "${mensajeResp}"). Generando nuevo correlativo...`);
        } else {
          // Error fiscal específico retornado por HKA Demo (ej. 0102 formato)
          const errorHka = emisionResult?.mensaje || 'Error no especificado por TFHKA Demo';
          return res.status(422).json({
            status: 'error',
            codigo: codigoResp,
            message: `TFHKA Demo rechazó el documento: ${errorHka}`,
            resultado: emisionResult?.resultado || null
          });
        }
      } catch (errEmit) {
        const errData = errEmit.response?.data;
        // TFHKA devuelve los errores de validación en la propiedad "validaciones" o "errores"
        const validaciones = errData?.validaciones || errData?.errores || errData?.observaciones || [];
        const validacionesStr = Array.isArray(validaciones) && validaciones.length > 0
          ? validaciones.join('; ')
          : (errData?.mensaje || errEmit.message);

        console.error(`[API] => VISTA PREVIA TFHKA DEMO - Error en intento ${intento + 1}:`, validacionesStr);

        // Si es un error de validación fiscal (400 / Código 203), no tiene sentido reintentar con otro número
        if (errEmit.response?.status === 400 || errData?.codigo === '203') {
          return res.status(422).json({
            status: 'error',
            codigo: errData?.codigo || '203',
            message: `TFHKA Demo rechazó el documento: ${validacionesStr}`,
            validaciones: validaciones,
            detalle: errData || null
          });
        }

        if (intento === maxIntentos - 1) {
          return res.status(502).json({
            status: 'error',
            message: `Fallo de comunicación con TFHKA Demo: ${validacionesStr}`,
            validaciones: validaciones,
            detalle: errData || null
          });
        }
      }
    }

    if (!emitidaExitosa) {
      return res.status(409).json({
        status: 'error',
        message: 'No fue posible asignar un correlativo libre en TFHKA Demo tras múltiples reintentos.'
      });
    }

    // 3. DESCARGA DEL PDF OFICIAL DE TFHKA FASTREPORT (con breve sondeo para compilación)
    console.log(`[API] => VISTA PREVIA TFHKA DEMO - Esperando generación de PDF para ${demoDocNum}...`);
    let pdfBase64 = null;
    const maxSondeos = 4;

    for (let s = 0; s < maxSondeos; s++) {
      // Esperar 1.5s entre consultas para dar tiempo al generador de reportes de TFHKA
      await new Promise(r => setTimeout(r, 1500));

      try {
        const descargaResp = await tfhkaDemoClient.descargarArchivo({
          tipoDocumento: tipoDoc,
          numeroDocumento: demoDocNum,
          serie: identDoc.Serie || identDoc.serie || ''
        });

        pdfBase64 = descargaResp?.archivo || descargaResp?.Archivo || null;
        if (pdfBase64 && pdfBase64.length > 0) {
          console.log(`[API] => VISTA PREVIA TFHKA DEMO - PDF descargado exitosamente (${pdfBase64.length} caracteres).`);
          break;
        }
      } catch (errDesc) {
        console.log(`[API] => VISTA PREVIA TFHKA DEMO - Sondeo ${s + 1}/${maxSondeos} no disponible aún:`, errDesc.message);
      }
    }

    if (!pdfBase64) {
      return res.status(504).json({
        status: 'error',
        message: 'El documento fue procesado en TFHKA Demo pero el PDF aún no estuvo listo para su descarga.'
      });
    }

    // 4. RETORNAR EL ARCHIVO SEGÚN EL FORMATO SOLICITADO
    if (req.query.format === 'binary' || req.headers.accept === 'application/pdf') {
      const pdfBuffer = Buffer.from(pdfBase64, 'base64');
      res.setHeader('Content-Type', 'application/pdf');
      res.setHeader('Content-Disposition', `inline; filename=vista_previa_${originalDocNum}.pdf`);
      return res.send(pdfBuffer);
    }

    res.status(200).json({
      status: 'ok',
      mensaje: 'Vista previa oficial de TFHKA generada exitosamente.',
      numeroDocumentoVirtual: demoDocNum,
      numeroControlDemo: emisionResult?.resultado?.numeroControl || null,
      archivo: pdfBase64
    });

  } catch (error) {
    next(error);
  }
});

/**
 * Endpoint para generar la Vista Previa (Preview/Proforma) en PDF de un documento antes de emitirlo
 */
app.post('/api/v1/vista-previa/pdf', async (req, res, next) => {
  try {
    const rawBody = req.body;
    if (!rawBody || Object.keys(rawBody).length === 0) {
      return res.status(400).json({
        status: 'error',
        message: 'El cuerpo de la petición no puede estar vacío.'
      });
    }

    const documentoElectronico = rawBody.documentoElectronico || rawBody;
    const ident = getIdentificacionDocumento(documentoElectronico);
    const numDoc = ident.NumeroDocumento || ident.numeroDocumento || '00000000';

    console.log(`[API] => VISTA PREVIA PDF - Generando vista previa para Documento Nro: ${numDoc}`);

    const pdfBuffer = await renderPreviewPDF(documentoElectronico);

    // Si el cliente pide binario, enviar stream directo
    if (req.query.format === 'binary' || req.headers.accept === 'application/pdf') {
      res.setHeader('Content-Type', 'application/pdf');
      res.setHeader('Content-Disposition', `inline; filename=proforma_${numDoc}.pdf`);
      return res.send(pdfBuffer);
    }

    // Por defecto, devolver JSON con base64 para máxima interoperabilidad
    res.status(200).json({
      status: 'ok',
      mensaje: 'Vista previa generada exitosamente.',
      archivo: pdfBuffer.toString('base64')
    });
  } catch (error) {
    next(error);
  }
});

// Registrar manejador de errores global
app.use(errorHandler);

export default app;
