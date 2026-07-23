import dotenv from 'dotenv';
dotenv.config();

export const config = {
  port: parseInt(process.env.PORT || '8000', 10),
  tfhka: {
    usuario: process.env.TFHKA_USUARIO,
    clave: process.env.TFHKA_CLAVE,
    baseUrl: process.env.TFHKA_BASE_URL || 'https://demoemisionv2.thefactoryhka.com.ve'
  }
};
