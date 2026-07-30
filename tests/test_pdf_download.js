import axios from 'axios';
import app from '../src/app.js';
import dotenv from 'dotenv';

dotenv.config();

const PORT = 8002;

async function runPdfTest() {
  console.log('--- Iniciando Prueba de Descarga de PDF ---');
  console.log('Documento objetivo: 00000474, Tipo: 01 (Factura)');

  // Levantar servidor temporal
  const server = app.listen(PORT, async () => {
    console.log(`[TEST] Servidor de prueba en ejecución en http://localhost:${PORT}`);

    try {
      console.log('[TEST] 1. Solicitando PDF en formato JSON (Base64)...');
      const response = await axios.post(`http://localhost:${PORT}/api/v1/descargar-pdf`, {
        tipoDocumento: '01',
        numeroDocumento: '00000474',
        serie: ''
      });

      if (response.status === 200) {
        const data = response.data;
        const pdfBase64 = data.Archivo || data.archivo;
        
        console.log('Respuesta de la API (Metadatos):', {
          codigo: data.codigo,
          mensaje: data.mensaje,
          tieneArchivo: !!pdfBase64,
          longitudArchivo: pdfBase64 ? pdfBase64.length : 0
        });

        if (pdfBase64 && pdfBase64.length > 0) {
          console.log('✓ Prueba JSON (Base64) exitosa.');
        } else {
          throw new Error('La respuesta de la API no contiene el archivo PDF.');
        }
      } else {
        throw new Error(`Respuesta HTTP inesperada: ${response.status}`);
      }

      console.log('\n[TEST] 2. Solicitando PDF en formato Binario directo...');
      const binResponse = await axios.post(
        `http://localhost:${PORT}/api/v1/descargar-pdf?format=binary`,
        {
          tipoDocumento: '01',
          numeroDocumento: '00000474',
          serie: ''
        },
        { responseType: 'arraybuffer' }
      );

      if (binResponse.status === 200 && binResponse.headers['content-type'] === 'application/pdf') {
        console.log(`✓ Prueba Binario exitosa. Tamaño del buffer: ${binResponse.data.byteLength} bytes.`);
        console.log('--- Prueba finalizada con éxito ---');
      } else {
        throw new Error(`Fallo al descargar binario. Content-Type: ${binResponse.headers['content-type']}`);
      }

    } catch (error) {
      const errorDetail = error.response?.data ? JSON.stringify(error.response.data, null, 2) : (error.message || error);
      console.error('\n✗ La prueba de descarga falló:', errorDetail);
    } finally {
      server.close(() => {
        console.log('[TEST] Servidor de prueba cerrado.');
      });
    }
  });
}

runPdfTest();
