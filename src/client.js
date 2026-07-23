import axios from 'axios';
import { config } from './config.js';

class TFHKAClient {
  constructor() {
    this.token = null;
    this.tokenExpiry = null;
  }

  /**
   * Obtiene o renueva el token JWT automáticamente
   */
  async ensureAuthenticated() {
    const now = new Date();
    // Si ya tenemos un token y no ha expirado (dejando un margen de 15 minutos), lo usamos.
    if (this.token && this.tokenExpiry && (this.tokenExpiry - now > 15 * 60 * 1000)) {
      return this.token;
    }

    console.log('TFHKAClient: Obteniendo nuevo token de autenticación...');
    try {
      const response = await axios.post(`${config.tfhka.baseUrl}/api/Autenticacion`, {
        usuario: config.tfhka.usuario,
        clave: config.tfhka.clave
      }, {
        headers: {
          'Content-Type': 'application/json'
        }
      });

      const { data } = response;
      
      // La API v2 de TFHKA devuelve un JSON con { codigo, mensaje, token, expiracion }
      if (data && data.token) {
        this.token = data.token;
        // La fecha de expiración suele venir en formato ISO (ej. 2025-02-12T15:15:13.7744331Z)
        this.tokenExpiry = data.expiracion ? new Date(data.expiracion) : new Date(Date.now() + 12 * 60 * 60 * 1000);
        console.log('TFHKAClient: Token obtenido con éxito. Expira en:', this.tokenExpiry);
        return this.token;
      } else {
        throw new Error(`Respuesta de autenticación inválida: ${JSON.stringify(data)}`);
      }
    } catch (error) {
      console.error('TFHKAClient: Error de autenticación:', error.response?.data || error.message);
      throw new Error(`Fallo en la autenticación con TFHKA: ${error.response?.data?.mensaje || error.message}`);
    }
  }

  /**
   * Emite un documento electrónico a través de la API de TFHKA
   * @param {Object} documentoElectronico El cuerpo del documento electrónico en formato JSON
   */
  async emitirDocumento(documentoElectronico) {
    const token = await this.ensureAuthenticated();
    
    try {
      const response = await axios.post(`${config.tfhka.baseUrl}/api/Emision`, {
        documentoElectronico
      }, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      });
      return response.data;
    } catch (error) {
      console.error('TFHKAClient: Error de emisión:', error.response?.data || error.message);
      throw error;
    }
  }

  /**
   * Anula un documento electrónico en la API de TFHKA
   * @param {Object} datosAnulacion Datos de la anulación (serie, tipoDocumento, numeroDocumento, motivoAnulacion, fechaAnulacion, horaAnulacion)
   */
  async anularDocumento(datosAnulacion) {
    const token = await this.ensureAuthenticated();

    const payload = {
      Serie: datosAnulacion.serie !== undefined ? datosAnulacion.serie : (datosAnulacion.Serie || ''),
      TipoDocumento: datosAnulacion.tipoDocumento || datosAnulacion.TipoDocumento,
      NumeroDocumento: datosAnulacion.numeroDocumento || datosAnulacion.NumeroDocumento,
      MotivoAnulacion: datosAnulacion.motivoAnulacion || datosAnulacion.MotivoAnulacion,
      FechaAnulacion: datosAnulacion.fechaAnulacion || datosAnulacion.FechaAnulacion,
      HoraAnulacion: datosAnulacion.horaAnulacion || datosAnulacion.HoraAnulacion
    };

    try {
      const response = await axios.post(`${config.tfhka.baseUrl}/api/Anular`, payload, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      });
      return response.data;
    } catch (error) {
      console.error('TFHKAClient: Error de anulación:', error.response?.data || error.message);
      throw error;
    }
  }

  /**
   * Descarga un archivo PDF en formato Base64 desde la API de TFHKA
   * @param {Object} datosDescarga Datos de descarga (serie, tipoDocumento, numeroDocumento)
   */
  async descargarArchivo(datosDescarga) {
    const token = await this.ensureAuthenticated();

    const payload = {
      Serie: datosDescarga.serie !== undefined ? datosDescarga.serie : (datosDescarga.Serie || ''),
      TipoDocumento: datosDescarga.tipoDocumento || datosDescarga.TipoDocumento,
      NumeroDocumento: datosDescarga.numeroDocumento || datosDescarga.NumeroDocumento
    };

    try {
      const response = await axios.post(`${config.tfhka.baseUrl}/api/DescargaArchivo`, payload, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      });
      return response.data;
    } catch (error) {
      console.error('TFHKAClient: Error de descarga de archivo:', error.response?.data || error.message);
      throw error;
    }
  }
}

export const tfhkaClient = new TFHKAClient();
