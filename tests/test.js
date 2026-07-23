import axios from 'axios';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import app from '../src/app.js';
import { config } from '../src/config.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const PORT = 8001; // Usamos un puerto alternativo para pruebas

function getFormattedTime() {
  const now = new Date();
  let hours = now.getHours();
  const minutes = String(now.getMinutes()).padStart(2, '0');
  const seconds = String(now.getSeconds()).padStart(2, '0');
  const ampm = hours >= 12 ? 'pm' : 'am';
  hours = hours % 12;
  hours = hours ? hours : 12; // 0 hours becomes 12
  const hoursStr = String(hours).padStart(2, '0');
  return `${hoursStr}:${minutes}:${seconds} ${ampm}`;
}

async function runTests() {
  console.log('--- Iniciando Pruebas de API REST ---');
  
  // Levantar servidor Express en puerto alternativo
  const server = app.listen(PORT, async () => {
    console.log(`[TEST] Servidor temporal levantado en http://localhost:${PORT}`);
    
    try {
      // 1. Probar Endpoint de Healthcheck
      console.log('\n[TEST] 1. Probando GET /health...');
      const healthRes = await axios.get(`http://localhost:${PORT}/health`);
      if (healthRes.status === 200 && healthRes.data.status === 'ok') {
        console.log('✓ Healthcheck exitoso.');
      } else {
        throw new Error('Healthcheck falló.');
      }

      // 2. Validar credenciales de integración
      const isPlaceholder = 
        config.tfhka.usuario === 'TuUsuarioDePruebas' || 
        config.tfhka.clave === 'TuClaveDePruebas' ||
        !config.tfhka.usuario ||
        !config.tfhka.clave;

      if (isPlaceholder) {
        console.log('\n[TEST] ⚠ AVISO: Credenciales por defecto detectadas en .env.');
        console.log('       Las pruebas de emisión e integración real con TFHKA serán omitidas.');
        console.log('       Configura credenciales reales en tu archivo .env para realizar pruebas E2E.');
      } else {
        console.log('\n[TEST] 2. Probando Flujo Completo E2E con TFHKA...');
        
        // Cargar fechas y horas para inyección dinámica
        const today = new Date();
        const dd = String(today.getDate()).padStart(2, '0');
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const yyyy = today.getFullYear();
        const currentDateStr = `${dd}/${mm}/${yyyy}`;
        const currentTimeStr = getFormattedTime();

        // ==========================================
        // A. EMISIÓN DE FACTURA
        // ==========================================
        const invoicePath = path.join(__dirname, './fixtures/factura_01.json');
        if (!fs.existsSync(invoicePath)) {
          throw new Error(`No se encontró el JSON de factura en: ${invoicePath}`);
        }
        const invoicePayload = JSON.parse(fs.readFileSync(invoicePath, 'utf8'));
        const invoiceNum = String(Math.floor(10000 + Math.random() * 90000));
        
        if (invoicePayload.documentoElectronico?.Encabezado?.IdentificacionDocumento) {
          const ident = invoicePayload.documentoElectronico.Encabezado.IdentificacionDocumento;
          ident.FechaEmision = currentDateStr;
          ident.FechaVencimiento = currentDateStr;
          ident.HoraEmision = currentTimeStr;
          ident.NumeroDocumento = invoiceNum;
        }

        console.log(`\n[TEST E2E] A. Enviando Factura (Documento: ${invoiceNum})...`);
        const emitInvoiceRes = await axios.post(`http://localhost:${PORT}/api/v1/emitir/factura`, invoicePayload);
        if (emitInvoiceRes.status === 200 && emitInvoiceRes.data.codigo === '200') {
          console.log(`✓ Factura emitida exitosamente. Nro Control: ${emitInvoiceRes.data.resultado?.numeroControl}`);
        } else {
          throw new Error(`Fallo al emitir factura: ${JSON.stringify(emitInvoiceRes.data)}`);
        }

        // ==========================================
        // B. EMISIÓN DE NOTA DE CRÉDITO
        // ==========================================
        const creditPath = path.join(__dirname, './fixtures/nota_credito_02.json');
        if (!fs.existsSync(creditPath)) {
          throw new Error(`No se encontró el JSON de Nota de Crédito en: ${creditPath}`);
        }
        const creditPayload = JSON.parse(fs.readFileSync(creditPath, 'utf8'));
        const creditNoteNum = String(Math.floor(10000 + Math.random() * 90000));
        
        if (creditPayload.documentoElectronico?.Encabezado?.IdentificacionDocumento) {
          const ident = creditPayload.documentoElectronico.Encabezado.IdentificacionDocumento;
          ident.FechaEmision = currentDateStr;
          ident.FechaVencimiento = currentDateStr;
          ident.HoraEmision = currentTimeStr;
          ident.NumeroDocumento = creditNoteNum;
          // Inyectamos las referencias de la factura afectada generada en el paso anterior
          ident.NumeroFacturaAfectada = invoiceNum;
          ident.FechaFacturaAfectada = currentDateStr;
        }

        console.log(`\n[TEST E2E] B. Enviando Nota de Crédito (Documento: ${creditNoteNum}) afectando Factura: ${invoiceNum}...`);
        const emitCreditRes = await axios.post(`http://localhost:${PORT}/api/v1/emitir/nota-credito`, creditPayload);
        if (emitCreditRes.status === 200 && emitCreditRes.data.codigo === '200') {
          console.log(`✓ Nota de Crédito emitida exitosamente. Nro Control: ${emitCreditRes.data.resultado?.numeroControl}`);
        } else {
          throw new Error(`Fallo al emitir nota de crédito: ${JSON.stringify(emitCreditRes.data)}`);
        }

        // ==========================================
        // C. EMISIÓN DE NOTA DE DÉBITO
        // ==========================================
        const debitPath = path.join(__dirname, './fixtures/nota_debito_03.json');
        if (!fs.existsSync(debitPath)) {
          throw new Error(`No se encontró el JSON de Nota de Débito en: ${debitPath}`);
        }
        const debitPayload = JSON.parse(fs.readFileSync(debitPath, 'utf8'));
        const debitNoteNum = String(Math.floor(10000 + Math.random() * 90000));
        
        if (debitPayload.documentoElectronico?.Encabezado?.IdentificacionDocumento) {
          const ident = debitPayload.documentoElectronico.Encabezado.IdentificacionDocumento;
          ident.FechaEmision = currentDateStr;
          ident.FechaVencimiento = currentDateStr;
          ident.HoraEmision = currentTimeStr;
          ident.NumeroDocumento = debitNoteNum;
          // Inyectamos las referencias de la factura afectada generada en el paso anterior
          ident.NumeroFacturaAfectada = invoiceNum;
          ident.FechaFacturaAfectada = currentDateStr;
        }

        console.log(`\n[TEST E2E] C. Enviando Nota de Débito (Documento: ${debitNoteNum}) afectando Factura: ${invoiceNum}...`);
        const emitDebitRes = await axios.post(`http://localhost:${PORT}/api/v1/emitir/nota-debito`, debitPayload);
        if (emitDebitRes.status === 200 && emitDebitRes.data.codigo === '200') {
          console.log(`✓ Nota de Débito emitida exitosamente. Nro Control: ${emitDebitRes.data.resultado?.numeroControl}`);
        } else {
          throw new Error(`Fallo al emitir nota de débito: ${JSON.stringify(emitDebitRes.data)}`);
        }

        // ==========================================
        // D. ANULACIÓN DE NOTA DE DÉBITO
        // ==========================================
        const cancelPath = path.join(__dirname, './fixtures/anulacion.json');
        if (!fs.existsSync(cancelPath)) {
          throw new Error(`No se encontró el JSON de Anulación en: ${cancelPath}`);
        }
        const cancelPayload = JSON.parse(fs.readFileSync(cancelPath, 'utf8'));
        
        // Inyectamos los parámetros dinámicos de la Nota de Débito a anular
        cancelPayload.numeroDocumento = debitNoteNum;
        cancelPayload.fechaAnulacion = currentDateStr;
        cancelPayload.horaAnulacion = currentTimeStr;

        console.log(`\n[TEST E2E] D. Anulando Nota de Débito (Documento: ${debitNoteNum})...`);
        const cancelRes = await axios.post(`http://localhost:${PORT}/api/v1/anular`, cancelPayload);
        if (cancelRes.status === 200 && cancelRes.data.codigo === '200') {
          console.log(`✓ Nota de Débito Anulada exitosamente. Mensaje: ${cancelRes.data.mensaje}`);
        } else {
          throw new Error(`Fallo al anular Nota de Débito: ${JSON.stringify(cancelRes.data)}`);
        }

        // ==========================================
        // E. DESCARGA DE PDF
        // ==========================================
        console.log(`\n[TEST E2E] E. Esperando 3 segundos para la generación del PDF en el servidor...`);
        await new Promise(resolve => setTimeout(resolve, 3000));
        console.log(`[TEST E2E] Descargando PDF de Factura (Documento: ${invoiceNum})...`);
        const downloadRes = await axios.post(`http://localhost:${PORT}/api/v1/descargar-pdf`, {
          tipoDocumento: '01',
          numeroDocumento: invoiceNum
        });
        
        if (downloadRes.status === 200) {
          if (downloadRes.data.Archivo) {
            console.log(`✓ PDF descargado exitosamente en formato Base64 (Largo: ${downloadRes.data.Archivo.length} caracteres)`);
            
            // Probar también formato binario si el archivo existe
            console.log(`[TEST E2E] E2. Descargando PDF de Factura en formato binario...`);
            const downloadBinRes = await axios.post(
              `http://localhost:${PORT}/api/v1/descargar-pdf?format=binary`,
              { tipoDocumento: '01', numeroDocumento: invoiceNum },
              { responseType: 'arraybuffer' }
            );
            if (downloadBinRes.status === 200 && downloadBinRes.headers['content-type'] === 'application/pdf') {
              console.log(`✓ PDF binario descargado exitosamente (Tamaño: ${downloadBinRes.data.byteLength} bytes)`);
            } else {
              throw new Error(`Fallo al descargar PDF binario`);
            }
          } else if (downloadRes.data.codigo === '201') {
            console.log(`✓ Integración de descarga verificada. TFHKA respondió correctamente: 201 - ${downloadRes.data.mensaje} (Comportamiento esperado en Demo si la plantilla no está pre-renderizada)`);
          } else {
            throw new Error(`Respuesta inesperada al descargar PDF: ${JSON.stringify(downloadRes.data)}`);
          }
        } else {
          throw new Error(`Fallo al conectar con el endpoint descargar-pdf`);
        }
      }
      
      console.log('\n--- Pruebas finalizadas con éxito ---');
    } catch (error) {
      const errorDetail = error.response?.data ? JSON.stringify(error.response.data, null, 2) : (error.message || error);
      console.error('\n✗ Las pruebas fallaron con el siguiente error:', errorDetail);
      process.exitCode = 1;
    } finally {
      // Cerrar el servidor para finalizar el proceso
      server.close(() => {
        console.log('[TEST] Servidor de prueba cerrado.');
      });
    }
  });
}

runTests();
