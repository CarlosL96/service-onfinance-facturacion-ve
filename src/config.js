import dotenv from 'dotenv';
dotenv.config();

export const config = {
  nodeEnv: process.env.NODE_ENV || 'development',
  port: parseInt(process.env.PORT || '8000', 10),
  apiKey: process.env.API_KEY || null,
  devInterceptEmail: process.env.DEV_INTERCEPT_EMAIL || 'carlos.lugo@asimetrixtech.com',
  devAllowedDomain: process.env.DEV_ALLOWED_DOMAIN || '@asimetrixtech.com',
  // Configuración de Producción (Emisiones reales)
  // Soporta TFHKA_PROD_* o fallback a TFHKA_* tradicional
  tfhkaProd: {
    usuario: process.env.TFHKA_PROD_USUARIO || process.env.TFHKA_USUARIO,
    clave: process.env.TFHKA_PROD_CLAVE || process.env.TFHKA_CLAVE,
    baseUrl: process.env.TFHKA_PROD_URL || process.env.TFHKA_BASE_URL || 'https://emision.thefactoryhka.com.ve'
  },
  // Configuración de Demo / Sandbox (Vistas previas oficiales FastReport)
  tfhkaDemo: {
    usuario: process.env.TFHKA_DEMO_USUARIO || process.env.TFHKA_USUARIO || 'swfrnwrltzth_tfhka',
    clave: process.env.TFHKA_DEMO_CLAVE || process.env.TFHKA_CLAVE || ',YEDYs@&Z&Zw',
    baseUrl: process.env.TFHKA_DEMO_URL || 'https://demoemisionv2.thefactoryhka.com.ve'
  },
  // Retrocompatibilidad con código existente
  tfhka: {
    usuario: process.env.TFHKA_USUARIO,
    clave: process.env.TFHKA_CLAVE,
    baseUrl: process.env.TFHKA_BASE_URL || 'https://demoemisionv2.thefactoryhka.com.ve'
  }
};
