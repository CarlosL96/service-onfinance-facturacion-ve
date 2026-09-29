import express from 'express';
import { config } from './config.js';
import { tfhkaClient } from './client.js';

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

// Helper para interceptar y sustituir el correo del comprador en ambiente de desarrollo
function applyDevEmailInterception(doc, req) {
  const clientEnv = (req.headers['x-environment'] || 'dev').toLowerCase();
  
  if (config.devInterceptEmail && clientEnv === 'dev') {
    const encabezado = doc.Encabezado || doc.encabezado;
    if (encabezado) {
      if (encabezado.Comprador) {
        console.log(`[DEV INTERCEPT] Sustituyendo correos originales [${encabezado.Comprador.Correo || 'ninguno'}] por: ${config.devInterceptEmail}`);
        encabezado.Comprador.Correo = [config.devInterceptEmail];
      } else if (encabezado.comprador) {
        console.log(`[DEV INTERCEPT] Sustituyendo correos originales [${encabezado.comprador.correo || 'ninguno'}] por: ${config.devInterceptEmail}`);
        encabezado.comprador.correo = [config.devInterceptEmail];
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

// Registrar manejador de errores global
app.use(errorHandler);

export default app;
