import PDFDocument from 'pdfkit';
import QRCode from 'qrcode';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

/**
 * Carga la configuración visual corporativa de la empresa
 */
function loadCompanyConfig() {
  const configPath = path.join(rootDir, 'assets', 'company.json');
  if (fs.existsSync(configPath)) {
    try {
      const raw = fs.readFileSync(configPath, 'utf8');
      return JSON.parse(raw);
    } catch (e) {
      console.warn('[PREVIEW] Error leyendo assets/company.json, usando defaults:', e.message);
    }
  }
  return {
    emisor: {
      razonSocial: 'ASIMETRIX SOLUCIONES TECNÓLOGICAS, C.A.',
      rif: 'J-50704779-2',
      domicilioFiscal: 'AV FRANCISCO DE MIRANDA EDIF TORRE 19-02 PISO 7 OF NO. 7 URB CHACAO CARACAS (CHACAO) MIRANDA ZONA POSTAL 1060',
      codigoOperacion: '6209'
    },
    estilo: {
      logoPath: 'assets/images/logo.png',
      logoWidth: 155,
      colorPrimario: '#3D3A86',
      colorAcento: '#F7BA00',
      colorRojoControl: '#D32F2F',
      colorSlate: '#64748B',
      colorTexto: '#334155',
      colorLineas: '#E2E8F0',
      colorZebra: '#F8FAFC',
      marcaDeAguaTexto: 'VISTA PREVIA / PROFORMA - NO VÁLIDO COMO DOCUMENTO FISCAL',
      marcaDeAguaColor: '#94A3B8',
      marcaDeAguaOpacidad: 0.30
    },
    textosLegales: {
      clausulaIgtf: 'Este pago estará sujeto al cobro adicional del 3% del Impuesto a las Grandes Transacciones Financieras (IGTF), de conformidad con la Providencia Administrativa SNAT/2022/000013 publicada en la G.O.N 42.339 del 17-03-2022, en caso de ser cancelado en divisas. No aplica en pago en Bs.',
      clausulaBimonetaria: 'Este documento se expresa en Bolívares con su equivalente en [MONEDA] al tipo de cambio corriente del mercado a la fecha de su emisión, según lo establecido en el articulo 13 numeral 14 de la Providencia Administrativa SNAT /2011/0071 (..) en concordancia con el artículo 128 de la Ley del Banco Central de Venezuela (BCV); artículo 25 de la Ley que establece el Impuesto al Valor Agregado (IVA) y 38 del Reglamento General de la Ley que establece el Impuesto al Valor Agregado (RLIVA).',
      leyendaSeniat: 'DOCUMENTO EMITIDO CONFORME A LA PROVIDENCIA ADMINISTRATIVA SNAT/2024/000102',
      pieImprenta: 'THE FACTORY HKA VENEZUELA, C.A. J-402385358, AV DON DIEGO CISNEROS EDIF SIEMENS TORRE SUR PISO 2 OF S/N URB LOS RUICES CARACAS MIRANDA ZONA POSTAL 1071 +58(212)202-0811. Imprenta Digital Autorizada mediante Providencia SENIAT/INTI/XXXXXXX de fecha 09/09/2022 Nros de Control desde el N° 00-00000001 hasta el N° 00-00100000 generados digitalmente.'
    }
  };
}

/**
 * Formatea un número según las convenciones fiscales venezolanas (1.234,56)
 */
function formatNumber(num, decimals = 2) {
  if (num === null || num === undefined || isNaN(num)) return (0).toFixed(decimals).replace('.', ',');
  const n = parseFloat(num);
  const parts = n.toFixed(decimals).split('.');
  parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return parts.join(',');
}

/**
 * Sanitiza valores de arrays o cadenas
 */
function firstValue(val, fallback = '') {
  if (Array.isArray(val)) return val.length > 0 ? String(val[0]) : fallback;
  if (val !== null && val !== undefined) return String(val);
  return fallback;
}

/**
 * Genera el documento PDF de Vista Previa idéntico a TFHKA
 * @param {Object} rawDoc Objeto de petición (documentoElectronico)
 * @returns {Promise<Buffer>} Buffer del archivo PDF generado
 */
export async function renderPreviewPDF(rawDoc) {
  return new Promise(async (resolve, reject) => {
    try {
      const companyConfig = loadCompanyConfig();
      const docData = rawDoc.documentoElectronico || rawDoc;
      const encabezado = docData.Encabezado || docData.encabezado || {};
      const ident = encabezado.IdentificacionDocumento || encabezado.identificacionDocumento || {};
      const comprador = encabezado.Comprador || encabezado.comprador || {};
      const totales = encabezado.Totales || encabezado.totales || {};
      const otraMoneda = encabezado.TotalesOtraMoneda || encabezado.totalesOtraMoneda || docData.TotalesOtraMoneda || docData.totalesOtraMoneda || null;
      const items = docData.DetallesItems || docData.detallesItems || [];
      const infoAdicional = docData.InfoAdicional || docData.infoAdicional || encabezado.InfoAdicional || encabezado.infoAdicional || [];

      // Determinar Tipo de Documento
      const tipoDoc = String(ident.TipoDocumento || ident.tipoDocumento || '01');
      let tipoDocTitulo = 'FACTURA';
      if (tipoDoc === '02') tipoDocTitulo = 'NOTA DE CRÉDITO';
      else if (tipoDoc === '03') tipoDocTitulo = 'NOTA DE DÉBITO';

      // Datos de Emisor
      const emisor = {
        razonSocial: companyConfig.emisor.razonSocial,
        rif: companyConfig.emisor.rif,
        domicilioFiscal: companyConfig.emisor.domicilioFiscal,
        codigoOperacion: companyConfig.emisor.codigoOperacion || '6209'
      };

      // Extracción de campos adicionales (ODC, CC/OPP, Condición de Pago)
      let nroOdc = '';
      let ccOpp = '';
      let condicionPagoInfo = '';
      if (Array.isArray(infoAdicional)) {
        for (const info of infoAdicional) {
          const campo = (info.campo || info.Campo || '').toUpperCase();
          const valor = info.valor || info.Valor || '';
          if (campo.includes('ODC') || campo.includes('ORDEN DE COMPRA')) nroOdc = valor;
          if (campo.includes('CC') || campo.includes('OPP')) ccOpp = valor;
          if (campo.includes('CONDICION') || campo.includes('CONDICIÓN')) condicionPagoInfo = valor;
        }
      }

      // Datos de documento afectado (para Notas de Crédito / Débito)
      const numFacturaAfectada = ident.NumeroFacturaAfectada || ident.numeroFacturaAfectada || ident.DocRef || ident.doc_ref || '';
      const montoFacturaAfectada = ident.MontoFacturaAfectada || ident.montoFacturaAfectada || '';
      const fechaFacturaAfectada = ident.FechaFacturaAfectada || ident.fechaFacturaAfectada || '';
      const comentarioFacturaAfectada = ident.ComentarioFacturaAfectada || ident.comentarioFacturaAfectada || 'Nota de crédito de prueba';
      const esDocAjusteConReferencia = (tipoDoc === '02' || tipoDoc === '03') && Boolean(numFacturaAfectada);

      // Datos del cliente
      const razonSocialCliente = comprador.RazonSocial || comprador.razonSocial || 'CLIENTE GENERAL';
      
      // Sanitizar RIF para evitar prefijos dobles como J-J-
      let rawRif = String(comprador.NumeroIdentificacion || comprador.numeroIdentificacion || comprador.Rif || '00000000').trim();
      let tipoId = String(comprador.TipoIdentificacion || comprador.tipoIdentificacion || 'J').trim().toUpperCase();
      let rifCliente = rawRif;
      if (rawRif.toUpperCase().startsWith(tipoId + '-')) {
        rifCliente = rawRif;
      } else if (/^[VEJPG]\d/i.test(rawRif)) {
        rifCliente = rawRif.charAt(0).toUpperCase() + '-' + rawRif.slice(1);
      } else {
        rifCliente = tipoId + '-' + rawRif;
      }

      const dirCliente = comprador.Direccion || comprador.direccion || 'CARACAS VENEZUELA';
      const telCliente = firstValue(comprador.Telefono || comprador.telefono, '0212-000-0000');
      const emailCliente = firstValue(comprador.Correo || comprador.correo, '');
      const condicionPago = ident.TipoDePago || ident.tipoDePago || ident.CondicionPago || condicionPagoInfo || 'Contado';

      // Pre-generar QR Code en memoria (buffer PNG)
      let qrBuffer = null;
      try {
        const qrContent = `RIF:${emisor.rif}|DOC:${ident.NumeroDocumento || '00000000'}|CONTROL:00-00000000|FECHA:${ident.FechaEmision || ''}|TOTAL:${totales.TotalAPagar || 0}|VISTA-PREVIA`;
        qrBuffer = await QRCode.toBuffer(qrContent, { margin: 0, width: 51 });
      } catch (qrErr) {
        console.warn('[PREVIEW] Error generando QR code:', qrErr.message);
      }

      // Inicializar PDFKit (LETTER con márgenes exactos de 14.17 pt = 5mm de HKA)
      const doc = new PDFDocument({
        size: 'LETTER',
        margins: { top: 14.173, bottom: 15, left: 14.173, right: 14.173 },
        autoFirstPage: true,
        bufferPages: true
      });

      const buffers = [];
      doc.on('data', chunk => buffers.push(chunk));
      doc.on('end', () => resolve(Buffer.concat(buffers)));
      doc.on('error', err => reject(err));

      const pageWidth = 612;
      const pageHeight = 792;
      const leftMargin = 14.173;
      const rightMargin = 597.827;
      const contentWidth = 583.654; // 612 - 28.346

      // Paleta oficial calibrada
      const cPrimario = companyConfig.estilo.colorPrimario || '#3D3A86'; // Indigo Corporativo HKA
      const cAcento = companyConfig.estilo.colorAcento || '#F7BA00';     // Franja Dorada
      const cRojo = companyConfig.estilo.colorRojoControl || '#D32F2F';   // Nro Control
      const cSlate = companyConfig.estilo.colorSlate || '#64748B';       // Labels
      const cTexto = companyConfig.estilo.colorTexto || '#334155';       // Values
      const cLineas = companyConfig.estilo.colorLineas || '#E2E8F0';     // Borders
      const cZebra = companyConfig.estilo.colorZebra || '#F8FAFC';       // Zebra rows

      // Anchos milimétricos exactos de las columnas de TFHKA
      const cols = [
        { title: 'CÓDIGO', width: 70.04, align: 'left' },
        { title: 'DESCRIPCIÓN', width: 245.13, align: 'left' },
        { title: 'CANT.', width: 46.69, align: 'center' },
        { title: 'PRECIO UNIT.(Bs.)', width: 81.71, align: 'right' },
        { title: 'ALÍCUOTA IVA', width: 58.37, align: 'center' },
        { title: 'TOTAL (Bs.)', width: 81.71, align: 'right' }
      ];

      // Calcular posiciones X absolutas
      let runningX = leftMargin;
      cols.forEach(col => {
        col.x = runningX;
        runningX += col.width;
      });

      // -------------------------------------------------------------
      // DIBUJAR ENCABEZADO SUPERIOR (PÁGINA 1)
      // -------------------------------------------------------------
      function drawFirstPageHeader() {
        const logoAbsPath = path.isAbsolute(companyConfig.estilo.logoPath) 
          ? companyConfig.estilo.logoPath 
          : path.join(rootDir, companyConfig.estilo.logoPath);

        // 1. Logo oficial (Izquierda)
        let logoBottomY = 16;
        if (fs.existsSync(logoAbsPath)) {
          try {
            doc.image(logoAbsPath, leftMargin, 16, { width: 215 });
            logoBottomY = 16 + 40;
          } catch (e) {
            console.warn('[PREVIEW] Error cargando logo:', e.message);
          }
        }

        // Datos del Emisor (debajo del logo)
        const emisorY = Math.max(logoBottomY + 5, 62);
        doc.font('Helvetica-Bold').fontSize(10).fillColor(cPrimario);
        doc.text(emisor.razonSocial, leftMargin, emisorY);

        doc.font('Helvetica-Bold').fontSize(9).fillColor(cSlate);
        doc.text(emisor.rif, leftMargin, doc.y + 1);

        doc.font('Helvetica').fontSize(7).fillColor(cSlate);
        doc.text(emisor.domicilioFiscal, leftMargin, doc.y + 1, { width: 320 });
        if (emisor.codigoOperacion) {
          doc.text(`Cód. Operación: ${emisor.codigoOperacion}`, leftMargin, doc.y + 1);
        }

        // 2. Metadatos del Documento (Derecha - Formato Limpio HKA sin caja pesada)
        const rightAreaX = 350;
        const rightAreaWidth = rightMargin - rightAreaX;

        // Título del Documento
        doc.font('Helvetica-Bold').fontSize(12).fillColor(cPrimario);
        doc.text(tipoDocTitulo, rightAreaX, 16, { width: rightAreaWidth, align: 'right' });

        // Tabla de Metadatos
        let metaY = 32;
        const labelW = 125;
        const valueW = 95;
        const metaXLabel = rightMargin - valueW - labelW;
        const metaXValue = rightMargin - valueW;

        function drawMetaLine(label, value, isRed = false) {
          doc.font('Helvetica').fontSize(7).fillColor(cSlate);
          doc.text(label, metaXLabel, metaY, { width: labelW, align: 'right' });

          doc.font('Helvetica-Bold').fontSize(7).fillColor(isRed ? cRojo : cTexto);
          doc.text(value, metaXValue, metaY, { width: valueW, align: 'right' });
          metaY += 11;
        }

        drawMetaLine('N° de Documento:', ident.NumeroDocumento || ident.numeroDocumento || '00000000');
        drawMetaLine('Fecha de Emisión:', ident.FechaEmision || ident.fechaEmision || new Date().toLocaleDateString('es-VE'));
        if (ident.FechaVencimiento || ident.fechaVencimiento) {
          drawMetaLine('Fecha de Vencimiento:', ident.FechaVencimiento || ident.fechaVencimiento);
        }
        drawMetaLine('Hora de Emisión:', ident.HoraEmision || ident.horaEmision || '12:00:00 pm');
        drawMetaLine('N° de Control:', 'BORRADOR / PROFORMA', true);
        drawMetaLine('Fecha de Asig.:', ident.FechaEmision || ident.fechaEmision || new Date().toLocaleDateString('es-VE'));

        // 3. Bloque del Receptor ("DATOS DEL CLIENTE") - Distribución vertical en columna izquierda
        const receptorY = 122;
        const boxHeight = 124;

        if (esDocAjusteConReferencia) {
          // Caso Nota de Crédito / Débito: Dos cajas contiguas
          const leftBoxW = 396.88;
          const rightBoxW = 175.10;
          const rightBoxX = leftMargin + leftBoxW + 11.67;

          // Caja Izquierda: Datos del Cliente (Todos en el lado izquierdo distribuidos verticalmente)
          doc.roundedRect(leftMargin, receptorY, leftBoxW, boxHeight, 3).lineWidth(0.6).strokeColor(cLineas).stroke();
          doc.font('Helvetica-Bold').fontSize(8).fillColor(cPrimario).text('DATOS DEL CLIENTE', leftMargin + 10, receptorY + 7);

          // 1. Razón Social
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Razón Social:', leftMargin + 10, receptorY + 20);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(razonSocialCliente, leftMargin + 72, receptorY + 20, { width: leftBoxW - 82, lineBreak: false });

          // 2. R.I.F.
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('R.I.F.:', leftMargin + 10, receptorY + 32);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(rifCliente, leftMargin + 46, receptorY + 32, { width: leftBoxW - 56, lineBreak: false });

          // 3. Telf
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Telf:', leftMargin + 10, receptorY + 44);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(telCliente, leftMargin + 42, receptorY + 44, { width: leftBoxW - 52, lineBreak: false });

          // 4. Dirección (Espacio de 2 líneas)
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Dirección:', leftMargin + 10, receptorY + 56);
          doc.font('Helvetica').fontSize(7.3).fillColor(cTexto).text(dirCliente, leftMargin + 58, receptorY + 56, { width: leftBoxW - 68, height: 20, lineGap: 1 });

          // 5. Correo
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Correo:', leftMargin + 10, receptorY + 77);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(emailCliente || 'N/A', leftMargin + 50, receptorY + 77, { width: leftBoxW - 60, lineBreak: false });

          // 6. Condición de Pago
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Condición de Pago:', leftMargin + 10, receptorY + 89);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(condicionPago, leftMargin + 96, receptorY + 89, { width: leftBoxW - 106, lineBreak: false });

          // 7. Nro. de ODC
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Nro. de ODC:', leftMargin + 10, receptorY + 101);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(nroOdc || 'N/A', leftMargin + 72, receptorY + 101, { width: leftBoxW - 82, lineBreak: false });

          // 8. CC/OPP
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('CC/OPP:', leftMargin + 10, receptorY + 113);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(ccOpp || 'N/A', leftMargin + 54, receptorY + 113, { width: leftBoxW - 64, lineBreak: false });

          // Caja Derecha: APLICA A FACTURA
          doc.roundedRect(rightBoxX, receptorY, rightBoxW, boxHeight, 3).lineWidth(0.6).strokeColor(cLineas).stroke();
          doc.font('Helvetica-Bold').fontSize(8).fillColor(cPrimario).text('APLICA A FACTURA', rightBoxX + 10, receptorY + 7);

          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Factura:', rightBoxX + 10, receptorY + 22);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(numFacturaAfectada, rightBoxX + 54, receptorY + 22, { width: rightBoxW - 64, lineBreak: false });

          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Monto:', rightBoxX + 10, receptorY + 38);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text('Bs. ' + (montoFacturaAfectada ? formatNumber(montoFacturaAfectada, 2) : formatNumber(totales.TotalAPagar || 0, 2)), rightBoxX + 48, receptorY + 38, { width: rightBoxW - 58, lineBreak: false });

          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Fecha:', rightBoxX + 10, receptorY + 54);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(fechaFacturaAfectada || ident.FechaEmision || '', rightBoxX + 48, receptorY + 54, { width: rightBoxW - 58, lineBreak: false });

          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Motivo:', rightBoxX + 10, receptorY + 70);
          doc.font('Helvetica').fontSize(7.3).fillColor(cTexto).text(comentarioFacturaAfectada, rightBoxX + 48, receptorY + 70, { width: rightBoxW - 58, height: 35, lineGap: 1 });

        } else {
          // Caso Factura estándar: Caja ancha unificada (583.65 pt) con todos los datos distribuidos verticalmente a la izquierda
          doc.roundedRect(leftMargin, receptorY, contentWidth, boxHeight, 3).lineWidth(0.6).strokeColor(cLineas).stroke();
          doc.font('Helvetica-Bold').fontSize(8).fillColor(cPrimario).text('DATOS DEL CLIENTE', leftMargin + 10, receptorY + 7);

          // 1. Razón Social
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Razón Social:', leftMargin + 10, receptorY + 20);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(razonSocialCliente, leftMargin + 72, receptorY + 20, { width: contentWidth - 82, lineBreak: false });

          // 2. R.I.F.
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('R.I.F.:', leftMargin + 10, receptorY + 32);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(rifCliente, leftMargin + 46, receptorY + 32, { width: contentWidth - 56, lineBreak: false });

          // 3. Telf
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Telf:', leftMargin + 10, receptorY + 44);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(telCliente, leftMargin + 42, receptorY + 44, { width: contentWidth - 52, lineBreak: false });

          // 4. Dirección (Espacio de 2 líneas)
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Dirección:', leftMargin + 10, receptorY + 56);
          doc.font('Helvetica').fontSize(7.3).fillColor(cTexto).text(dirCliente, leftMargin + 58, receptorY + 56, { width: contentWidth - 68, height: 20, lineGap: 1 });

          // 5. Correo
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Correo:', leftMargin + 10, receptorY + 77);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(emailCliente || 'N/A', leftMargin + 50, receptorY + 77, { width: contentWidth - 60, lineBreak: false });

          // 6. Condición de Pago
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Condición de Pago:', leftMargin + 10, receptorY + 89);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(condicionPago, leftMargin + 96, receptorY + 89, { width: contentWidth - 106, lineBreak: false });

          // 7. Nro. de ODC
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('Nro. de ODC:', leftMargin + 10, receptorY + 101);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(nroOdc || 'N/A', leftMargin + 72, receptorY + 101, { width: contentWidth - 82, lineBreak: false });

          // 8. CC/OPP
          doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cTexto).text('CC/OPP:', leftMargin + 10, receptorY + 113);
          doc.font('Helvetica').fontSize(7.5).fillColor(cTexto).text(ccOpp || 'N/A', leftMargin + 54, receptorY + 113, { width: contentWidth - 64, lineBreak: false });
        }

        return 249; // Y de inicio para la tabla en la página 1
      }

      // -------------------------------------------------------------
      // DIBUJAR ENCABEZADO CONDENSADO (PÁGINAS 2 EN ADELANTE)
      // -------------------------------------------------------------
      function drawSubsequentPageHeader() {
        const subY = 16;
        doc.font('Helvetica-Bold').fontSize(8).fillColor(cPrimario);
        doc.text(`${emisor.razonSocial} | RIF: ${emisor.rif}`, leftMargin, subY);
        doc.text(`${tipoDocTitulo} N°: ${ident.NumeroDocumento || ''} (CONTINUACIÓN)`, leftMargin, subY, { width: contentWidth, align: 'right' });

        doc.moveTo(leftMargin, subY + 12).lineTo(rightMargin, subY + 12).lineWidth(0.5).strokeColor(cLineas).stroke();
        return subY + 18;
      }

      // -------------------------------------------------------------
      // DIBUJAR CABECERA DE LA TABLA CON FRANJA DORADA
      // -------------------------------------------------------------
      function drawTableHeader(y) {
        const headerH = 20.94;
        // 1. Fondo #3D3A86
        doc.rect(leftMargin, y, contentWidth, headerH).fillColor(cPrimario).fill();

        // 2. Franja de Acento Dorado (#F7BA00) de TFHKA
        doc.rect(leftMargin, y + headerH, contentWidth, 2.5).fillColor(cAcento).fill();

        // 3. Títulos en Blanco - Alineados al CENTRO
        doc.font('Helvetica-Bold').fontSize(8).fillColor('#FFFFFF');
        cols.forEach(col => {
          doc.text(col.title, col.x, y + 5.5, { width: col.width, align: 'center' });
        });

        return y + headerH + 3.5;
      }

      // -------------------------------------------------------------
      // RENDERIZADO DE FILAS DE ÍTEMS CON PAGINACIÓN
      // -------------------------------------------------------------
      let currentY = drawFirstPageHeader();
      currentY = drawTableHeader(currentY);

      const maxTableY = 560; // Límite inferior seguro para reservar espacio de totales y pie legal

      items.forEach((item, index) => {
        const codigo = item.codigoPLU || item.CodigoPLU || item.codigoItem || item.codigo || '';
        const descripcion = (item.descripcion || item.Descripcion || '').trim();
        const cantidad = formatNumber(item.cantidad || item.Cantidad || 1, 0);
        const precioUnit = formatNumber(item.precioUnitario || item.PrecioUnitario || 0, 2);
        
        let alicuotaStr = 'G';
        const tasaIVA = String(item.tasaIVA || item.TasaIVA || '16');
        if (tasaIVA === '0') alicuotaStr = 'E';
        else if (tasaIVA === '8') alicuotaStr = 'R';
        else if (tasaIVA === '16') alicuotaStr = 'G';
        else alicuotaStr = tasaIVA + '%';

        const totalItem = formatNumber(item.precioItem || item.PrecioItem || (item.valorTotalItem || item.ValorTotalItem || 0), 2);

        // Altura dinámica de la fila según la descripción
        const descHeight = doc.heightOfString(descripcion, { width: cols[1].width - 8, font: 'Helvetica', size: 7.2 });
        const rowHeight = Math.max(14, descHeight + 4);

        if (currentY + rowHeight > maxTableY) {
          doc.addPage();
          currentY = drawSubsequentPageHeader();
          currentY = drawTableHeader(currentY);
        }

        // Zebra alternado
        if (index % 2 === 1) {
          doc.rect(leftMargin, currentY, contentWidth, rowHeight).fillColor(cZebra).fill();
        }

        // Todos los valores de los renglones alineados a la IZQUIERDA con padding
        doc.font('Helvetica').fontSize(7.2).fillColor(cTexto);
        doc.text(codigo || 'N/A', cols[0].x + 4, currentY + 3, { width: cols[0].width - 8, align: 'left' });
        doc.text(descripcion, cols[1].x + 4, currentY + 3, { width: cols[1].width - 8, align: 'left' });
        doc.text(cantidad, cols[2].x + 4, currentY + 3, { width: cols[2].width - 8, align: 'left' });
        doc.text(precioUnit, cols[3].x + 4, currentY + 3, { width: cols[3].width - 8, align: 'left' });
        doc.text(alicuotaStr, cols[4].x + 4, currentY + 3, { width: cols[4].width - 8, align: 'left' });
        
        doc.font('Helvetica-Bold').fontSize(7.2).fillColor(cTexto);
        doc.text(totalItem, cols[5].x + 4, currentY + 3, { width: cols[5].width - 8, align: 'left' });

        // Divisor inferior
        doc.moveTo(leftMargin, currentY + rowHeight).lineTo(rightMargin, currentY + rowHeight).lineWidth(0.3).strokeColor(cLineas).stroke();
        currentY += rowHeight;
      });

      // -------------------------------------------------------------
      // BLOQUE DE TOTALES: DOBLE COLUMNA PARALELA (USD / BS.)
      // -------------------------------------------------------------
      const totalsRequiredHeight = 150;
      if (currentY + totalsRequiredHeight > 700) {
        doc.addPage();
        currentY = drawSubsequentPageHeader();
      }

      currentY += 12;
      const totalsY = currentY;

      // 1. Lado Izquierdo: NOTAS Y COMENTARIOS
      const leftColW = 175;
      doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cPrimario).text('NOTAS Y COMENTARIOS', leftMargin, totalsY);
      
      const formasPago = totales.FormasPago || totales.formasPago || [];
      let notasText = '';
      if (formasPago.length > 0) {
        const fp = formasPago[0];
        notasText += `Forma de Pago: ${fp.Descripcion || fp.descripcion || 'Depósito / Transferencia'}\n`;
      }
      if (otraMoneda) {
        notasText += `Documento emitido con contravalor en divisas según convenio cambiario vigente.`;
      }
      doc.font('Helvetica').fontSize(6.8).fillColor(cSlate).text(notasText, leftMargin, totalsY + 12, { width: leftColW, lineGap: 2 });

      // 2. Lado Derecho: Doble Columna Bimonetaria Paralela
      const rightColsStartX = leftMargin + 185;
      const colDivisaX = rightColsStartX;
      const colDivisaW = 188;
      const colLocalX = rightColsStartX + colDivisaW + 6;
      const colLocalW = rightMargin - colLocalX;

      // Encabezado superior unificado de Tasa B.C.V.
      const tasaCambio = otraMoneda ? (otraMoneda.tipoCambio || otraMoneda.TipoCambio || 0) : 0;
      const monedaOtra = otraMoneda ? (otraMoneda.moneda || otraMoneda.Moneda || 'USD') : 'USD';
      
      if (otraMoneda && Number(tasaCambio) > 0) {
        doc.font('Helvetica-Bold').fontSize(7.2).fillColor(cPrimario);
        const tasaTexto = `Tasa B.C.V a la fecha de emisión: ${formatNumber(tasaCambio, 4)} / ${monedaOtra}`;
        doc.text(tasaTexto, colDivisaX, totalsY - 1, { width: colDivisaW + colLocalW + 6, align: 'right' });
      }

      // Línea divisoria bajo la tasa
      doc.moveTo(colDivisaX, totalsY + 10).lineTo(rightMargin, totalsY + 10).lineWidth(0.4).strokeColor(cLineas).stroke();

      let rowY = totalsY + 15;

      // Valores en Bolívares (VES)
      const subtotalBs = totales.Subtotal || totales.subtotal || 0;
      const descuentoBs = totales.TotalDescuento || totales.totalDescuento || 0;
      const exentoBs = totales.MontoExentoTotal || totales.montoExentoTotal || 0;
      const gravadoBs = totales.MontoGravadoTotal || totales.montoGravadoTotal || subtotalBs;
      const ivaBs = totales.TotalIVA || totales.totalIVA || 0;
      const totalPagarBs = totales.TotalAPagar || totales.totalAPagar || (parseFloat(subtotalBs) + parseFloat(ivaBs));

      // Valores en Divisas (USD / EUR)
      const subtotalDiv = otraMoneda ? (otraMoneda.subtotal || otraMoneda.Subtotal || 0) : 0;
      const descuentoDiv = otraMoneda ? (otraMoneda.totalDescuento || 0) : 0;
      const exentoDiv = otraMoneda ? (otraMoneda.montoExentoTotal || otraMoneda.MontoExentoTotal || 0) : 0;
      const gravadoDiv = otraMoneda ? (otraMoneda.montoGravableTotal || otraMoneda.montoGravadoTotal || subtotalDiv) : 0;
      const ivaDiv = otraMoneda ? (otraMoneda.totalIVA || otraMoneda.TotalIVA || 0) : 0;
      const totalPagarDiv = otraMoneda ? (otraMoneda.totalAPagar || otraMoneda.TotalAPagar || (parseFloat(subtotalDiv) + parseFloat(ivaDiv))) : 0;

      function drawParallelTotalRow(label, valDivisa, valBs) {
        // Columna Divisa
        doc.font('Helvetica').fontSize(6.8).fillColor(cSlate).text(label, colDivisaX, rowY);
        doc.font('Helvetica').fontSize(6.8).fillColor(cTexto).text(`${monedaOtra}.`, colDivisaX + 90, rowY);
        doc.font('Helvetica').fontSize(6.8).fillColor(cTexto).text(formatNumber(valDivisa, 2), colDivisaX + 115, rowY, { width: colDivisaW - 118, align: 'right' });

        // Columna Bolívares
        doc.font('Helvetica').fontSize(6.8).fillColor(cSlate).text(label, colLocalX, rowY);
        doc.font('Helvetica').fontSize(6.8).fillColor(cTexto).text('BS.', colLocalX + 92, rowY);
        doc.font('Helvetica').fontSize(6.8).fillColor(cTexto).text(formatNumber(valBs, 2), colLocalX + 115, rowY, { width: colLocalW - 118, align: 'right' });

        rowY += 10.5;
      }

      drawParallelTotalRow('Subtotal', subtotalDiv, subtotalBs);
      drawParallelTotalRow('Descuento', descuentoDiv, descuentoBs);
      drawParallelTotalRow('Exento', exentoDiv, exentoBs);
      drawParallelTotalRow('Base Imponible (G)', gravadoDiv, gravadoBs);
      drawParallelTotalRow('Alícuota IVA (16.00%)', ivaDiv, ivaBs);
      drawParallelTotalRow('Base Imponible (IGTF)', 0, 0);
      drawParallelTotalRow('Alícuota IGTF (3.00%)', 0, 0);

      // Fila de Total a Pagar con líneas dobles superior e inferior
      rowY += 2;
      doc.moveTo(colDivisaX, rowY).lineTo(rightMargin, rowY).lineWidth(0.8).strokeColor(cTexto).stroke();
      rowY += 4;

      doc.font('Helvetica-Bold').fontSize(9).fillColor(cPrimario);
      doc.text('Total a Pagar', colDivisaX, rowY);
      doc.text(`${monedaOtra}.`, colDivisaX + 90, rowY);
      doc.text(formatNumber(totalPagarDiv, 2), colDivisaX + 115, rowY, { width: colDivisaW - 118, align: 'right' });

      doc.text('Total a Pagar', colLocalX, rowY);
      doc.text('BS.', colLocalX + 92, rowY);
      doc.text(formatNumber(totalPagarBs, 2), colLocalX + 115, rowY, { width: colLocalW - 118, align: 'right' });

      rowY += 13;
      doc.moveTo(colDivisaX, rowY).lineTo(rightMargin, rowY).lineWidth(0.8).strokeColor(cTexto).stroke();

      // -------------------------------------------------------------
      // CLÁUSULAS LEGALES (IGTF Y MULTIDIVISA)
      // -------------------------------------------------------------
      let disclaimersY = Math.max(rowY + 10, totalsY + totalsRequiredHeight - 20);
      doc.font('Helvetica').fontSize(5.8).fillColor(cSlate);
      doc.text(companyConfig.textosLegales.clausulaIgtf, leftMargin, disclaimersY, { width: contentWidth, align: 'left', lineGap: 1 });
      
      if (otraMoneda) {
        const clausulaMulti = companyConfig.textosLegales.clausulaBimonetaria.replace('[MONEDA]', monedaOtra === 'EUR' ? 'Euros' : 'Dólares');
        doc.text(clausulaMulti, leftMargin, doc.y + 2, { width: contentWidth, align: 'left', lineGap: 1 });
      }

      // -------------------------------------------------------------
      // SEGUNDA PASADA: MARCA DE AGUA, QR Y PIE DE PÁGINA
      // -------------------------------------------------------------
      const range = doc.bufferedPageRange();
      for (let i = range.start; i < range.start + range.count; i++) {
        doc.switchToPage(i);
        doc.page.margins.bottom = 0; // Prevenir saltos de página accidentales durante el pie

        // 1. Marca de Agua Diagonal
        doc.save();
        doc.rotate(-35, { origin: [pageWidth / 2, pageHeight / 2] });
        doc.font('Helvetica-Bold').fontSize(21).fillColor(companyConfig.estilo.marcaDeAguaColor || '#CBD5E1').opacity(companyConfig.estilo.marcaDeAguaOpacidad || 0.16);
        doc.text(
          companyConfig.estilo.marcaDeAguaTexto,
          -100,
          pageHeight / 2 - 20,
          { width: pageWidth + 200, align: 'center', lineBreak: false }
        );
        doc.restore();

        // 2. Línea divisoria sobre el pie de página
        doc.opacity(1.0);
        const footerY = 724;
        doc.moveTo(leftMargin, footerY - 4).lineTo(rightMargin, footerY - 4).lineWidth(0.4).strokeColor(cLineas).stroke();

        // 3. Código QR en la esquina inferior izquierda
        if (qrBuffer) {
          try {
            doc.image(qrBuffer, leftMargin, footerY, { width: 51, height: 51 });
          } catch (qrDrawErr) {
            console.warn('[PREVIEW] Error dibujando QR en PDF:', qrDrawErr.message);
          }
        }

        // 4. Texto Legal de Imprenta Digital (Centrado al lado del QR)
        const textCenterStartX = leftMargin + 58;
        const textCenterWidth = contentWidth - 145;

        doc.font('Helvetica-Bold').fontSize(6).fillColor(cSlate);
        doc.text(companyConfig.textosLegales.leyendaSeniat, textCenterStartX, footerY + 4, {
          width: textCenterWidth,
          align: 'center',
          lineBreak: false
        });

        doc.font('Helvetica').fontSize(5.6).fillColor(cSlate);
        doc.text(companyConfig.textosLegales.pieImprenta, textCenterStartX, footerY + 13, {
          width: textCenterWidth,
          align: 'center',
          lineGap: 1.2
        });

        // 5. Paginación (Esquina Inferior Derecha)
        doc.font('Helvetica-Bold').fontSize(7.5).fillColor(cPrimario);
        doc.text(`Página ${i + 1} de ${range.count}`, rightMargin - 75, footerY + 18, {
          width: 75,
          align: 'right',
          lineBreak: false
        });
      }

      // Finalizar PDF
      doc.end();
    } catch (error) {
      reject(error);
    }
  });
}
