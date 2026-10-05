import axios from 'axios';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import app from '../src/app.js';
import { config } from '../src/config.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const PORT = 8003;

async function runPreviewTest() {
  console.log('--- Iniciando Prueba de Endpoint de Vista Previa PDF ---');

  const server = app.listen(PORT, async () => {
    console.log(`[TEST] Servidor de prueba levantado en http://localhost:${PORT}`);

    try {
      if (config.apiKey) {
        axios.defaults.headers.common['x-api-key'] = config.apiKey;
      }

      // 1. Probar con Factura de 1 Ítem (factura_01.json)
      console.log('\n[TEST 1] Generando vista previa para Factura estándar (1 ítem)...');
      const fixturePath = path.join(__dirname, 'fixtures', 'factura_01.json');
      const payload1 = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));

      const res1 = await axios.post(`http://localhost:${PORT}/api/v1/vista-previa/pdf`, payload1);
      if (res1.status === 200 && res1.data.status === 'ok' && res1.data.archivo) {
        console.log(`✓ Vista previa 1 generada en Base64 (Largo: ${res1.data.archivo.length} chars).`);
      } else {
        throw new Error(`Fallo en vista previa 1: ${JSON.stringify(res1.data)}`);
      }

      // 2. Probar con Factura Multipágina (27 ítems de HPE) en formato binario
      console.log('\n[TEST 2] Generando vista previa multipágina (27 ítems HPE) en formato binario...');
      
      // Construir payload con los 27 ítems del CSV de HPE
      const csvPath = path.join(__dirname, '..', 'scriptcase_support', 'database', 'ofcm021_nota_credito.csv');
      const csvContent = fs.readFileSync(csvPath, 'utf8');
      const csvLines = csvContent.trim().split('\n').slice(1); // Omitir header

      const hpeItems = csvLines.map((line, idx) => {
        const parts = line.split(',');
        return {
          NumeroLinea: String(idx + 1),
          codigoPLU: parts[3],
          IndicadorBienoServicio: '1',
          Descripcion: parts[4],
          Cantidad: parts[5],
          UnidadMedida: 'UNI',
          PrecioUnitario: parts[7],
          PrecioItem: parts[10],
          CodigoImpuesto: 'G',
          TasaIVA: '16.00',
          ValorIVA: parts[16],
          ValorTotalItem: String((parseFloat(parts[10]) + parseFloat(parts[16])).toFixed(2))
        };
      });

      const payloadMulti = {
        documentoElectronico: {
          Encabezado: {
            IdentificacionDocumento: {
              TipoDocumento: '01',
              NumeroDocumento: '00000863',
              FechaEmision: '01/10/2026',
              HoraEmision: '01:30:00 pm',
              Serie: '',
              TipoDeVenta: 'Interna',
              Moneda: 'VES',
              FechaVencimiento: '16/10/2026'
            },
            Comprador: {
              TipoIdentificacion: 'J',
              NumeroIdentificacion: 'J-00070230-6',
              RazonSocial: 'FUNDACIÓN UNIVERSIDAD METROPOLITANA',
              Direccion: 'CAMPUS UNIMET, DISTRIBUIDOR PETARE-GUARENAS, EDIF. FUNDAMET, TERRAZAS DEL ÁVILA',
              Pais: 'VE',
              Telefono: ['(0212) 2403776'],
              Correo: ['lparra@fundamet.org.ve']
            },
            InfoAdicional: [
              { campo: 'Nro. de ODC', valor: '5387' },
              { campo: 'CC/OPP', valor: '0100-000812' }
            ],
            Totales: {
              NroItems: String(hpeItems.length),
              MontoGravadoTotal: '19045261.78',
              MontoExentoTotal: '0.00',
              Subtotal: '19045261.78',
              TotalIVA: '3047241.89',
              MontoTotalConIVA: '22092503.67',
              TotalAPagar: '22092503.67',
              FormasPago: [
                {
                  Descripcion: 'Transferencia de fondos',
                  Fecha: '01/10/2026',
                  Forma: '03',
                  Monto: '25683.72',
                  Moneda: 'USD',
                  TipoCambio: '860.175300'
                }
              ]
            },
            TotalesOtraMoneda: {
              moneda: 'USD',
              tipoCambio: '860.175300',
              subtotal: '22141.14',
              totalIVA: '3542.58',
              totalAPagar: '25683.72'
            }
          },
          DetallesItems: hpeItems
        }
      };

      const res2 = await axios.post(
        `http://localhost:${PORT}/api/v1/vista-previa/pdf?format=binary`,
        payloadMulti,
        { responseType: 'arraybuffer' }
      );

      if (res2.status === 200 && res2.headers['content-type'] === 'application/pdf') {
        const outPath = path.join(__dirname, 'output_preview_sample.pdf');
        fs.writeFileSync(outPath, Buffer.from(res2.data));
        console.log(`✓ Vista previa multipágina binaria generada exitosamente (Tamaño: ${res2.data.length} bytes).`);
        console.log(`  Guardado en: ${outPath}`);
      } else {
        throw new Error(`Fallo en vista previa binaria: ${res2.status}`);
      }

      // 3. Probar con Nota de Crédito (nota_credito_02.json) para validar 'APLICA A FACTURA'
      console.log('\n[TEST 3] Generando vista previa para Nota de Crédito con documento afectado...');
      const ncPath = path.join(__dirname, 'fixtures', 'nota_credito_02.json');
      const payloadNC = JSON.parse(fs.readFileSync(ncPath, 'utf8'));

      const res3 = await axios.post(
        `http://localhost:${PORT}/api/v1/vista-previa/pdf?format=binary`,
        payloadNC,
        { responseType: 'arraybuffer' }
      );

      if (res3.status === 200 && res3.headers['content-type'] === 'application/pdf') {
        const outNCPath = path.join(__dirname, 'output_preview_nc.pdf');
        fs.writeFileSync(outNCPath, Buffer.from(res3.data));
        console.log(`✓ Vista previa de Nota de Crédito generada exitosamente (Tamaño: ${res3.data.length} bytes).`);
      } else {
        throw new Error(`Fallo en vista previa de Nota de Crédito: ${res3.status}`);
      }

      console.log('\n--- Todas las pruebas del Endpoint de Vista Previa finalizaron con ÉXITO ---');
    } catch (err) {
      console.error('\n❌ Error en pruebas:', err.response?.data || err.message);
      process.exitCode = 1;
    } finally {
      server.close();
      console.log('[TEST] Servidor de prueba cerrado.');
    }
  });
}

runPreviewTest();
