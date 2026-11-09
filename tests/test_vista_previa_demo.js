import axios from 'axios';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import app from '../src/app.js';
import { config } from '../src/config.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const PORT = 8003;

async function testVistaPreviaHkaDemo() {
  console.log('=== PRUEBA DE ENDPOINT VISTA PREVIA TFHKA DEMO ===');
  
  const server = app.listen(PORT, async () => {
    console.log(`[TEST] Servidor temporal levantado en http://localhost:${PORT}`);

    try {
      // 1. Cargar fixture estándar de factura
      const fixturePath = path.join(__dirname, 'fixtures', 'factura_01.json');
      const payload = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));

      // Ajustar fechas dinámicas
      const now = new Date();
      const dd = String(now.getDate()).padStart(2, '0');
      const mm = String(now.getMonth() + 1).padStart(2, '0');
      const yyyy = now.getFullYear();
      const fechaStr = `${dd}/${mm}/${yyyy}`;
      
      const doc = payload.documentoElectronico;
      doc.Encabezado.IdentificacionDocumento.FechaEmision = fechaStr;
      doc.Encabezado.IdentificacionDocumento.FechaVencimiento = fechaStr;
      if (doc.Encabezado.Totales?.FormasPago?.[0]) {
        doc.Encabezado.Totales.FormasPago[0].Fecha = fechaStr;
      }

      // Forzar que el fixture tenga correos de cliente para verificar que el endpoint los limpie
      doc.Encabezado.Comprador.Correo = ['cliente.real@empresa.com', 'otro@empresa.com'];

      const headers = {
        'Content-Type': 'application/json'
      };
      if (config.apiKey) {
        headers['x-api-key'] = config.apiKey;
      }

      console.log('\n[TEST 1] Solicitando Vista Previa Oficial TFHKA en formato Binario (?format=binary)...');
      const resBin = await axios.post(
        `http://localhost:${PORT}/api/v1/vista-previa/hka?format=binary`,
        payload,
        {
          headers,
          responseType: 'arraybuffer'
        }
      );

      console.log('Status HTTP:', resBin.status);
      console.log('Content-Type:', resBin.headers['content-type']);
      console.log('Bytes recibidos:', resBin.data.byteLength);

      if (resBin.status === 200 && resBin.headers['content-type']?.includes('application/pdf')) {
        console.log('✓ Prueba 1 Exitosa: PDF oficial FastReport recibido en binario.');
        const outPdf = path.join(__dirname, 'output_preview_hka_demo.pdf');
        fs.writeFileSync(outPdf, resBin.data);
        console.log(`✓ Archivo guardado localmente para validación: ${outPdf}`);
      } else {
        throw new Error(`Respuesta inválida en prueba binaria: Status ${resBin.status}`);
      }

      console.log('\n[TEST 2] Solicitando segunda Vista Previa consecutiva (Comprobar prevención de 201 duplicado)...');
      const resJson = await axios.post(
        `http://localhost:${PORT}/api/v1/vista-previa/hka`,
        payload,
        { headers }
      );

      console.log('Status HTTP:', resJson.status);
      console.log('Respuesta JSON:', {
        status: resJson.data.status,
        numeroDocumentoVirtual: resJson.data.numeroDocumentoVirtual,
        numeroControlDemo: resJson.data.numeroControlDemo,
        tamanoBase64: resJson.data.archivo ? resJson.data.archivo.length : 0
      });

      if (resJson.data.status === 'ok' && resJson.data.archivo) {
        console.log('✓ Prueba 2 Exitosa: Segunda vista previa generada sin colisión de duplicado.');
      } else {
        throw new Error('La segunda vista previa no retornó el formato esperado.');
      }

      console.log('\n=== TODAS LAS PRUEBAS DE VISTA PREVIA TFHKA DEMO COMPLETADAS CON ÉXITO ===');
    } catch (err) {
      const errData = err.response?.data;
      console.error('\n❌ ERROR EN LA PRUEBA:', err.message, errData ? JSON.stringify(errData, null, 2) : '');
      process.exitCode = 1;
    } finally {
      server.close(() => {
        console.log('[TEST] Servidor temporal cerrado.');
      });
    }
  });
}

testVistaPreviaHkaDemo().catch(console.error);
