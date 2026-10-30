import { config } from '../config.js';

/**
 * Filtra los correos destinatarios para ambiente de desarrollo/pruebas.
 * Solo permite direcciones que pertenezcan al dominio corporativo (@asimetrixtech.com).
 * Si ningún correo coincide con el dominio permitido, aplica fallback a la cuenta de pruebas.
 * 
 * @param {Array<string>|string} rawEmails Lista o cadena de correos originales
 * @param {string} [fallbackEmail] Correo por defecto si ningún correo cumple el filtro
 * @param {string} [allowedDomain] Dominio permitido (por defecto @asimetrixtech.com)
 * @returns {Array<string>} Lista de correos autorizados para ambiente de desarrollo
 */
export function filterDevAllowedEmails(
  rawEmails,
  fallbackEmail = config.devInterceptEmail || 'carlos.lugo@asimetrixtech.com',
  allowedDomain = config.devAllowedDomain || '@asimetrixtech.com'
) {
  let list = [];

  if (Array.isArray(rawEmails)) {
    list = rawEmails;
  } else if (typeof rawEmails === 'string' && rawEmails.trim()) {
    list = rawEmails.split(/[;,]+/);
  }

  const normalizedDomain = allowedDomain.toLowerCase().startsWith('@')
    ? allowedDomain.toLowerCase()
    : `@${allowedDomain.toLowerCase()}`;

  // Filtrar cuentas que terminen exactamente en el dominio permitido
  const allowed = list
    .map(email => (email ? String(email).trim().toLowerCase() : ''))
    .filter(email => email.length > 0 && email.endsWith(normalizedDomain));

  // Descartar duplicados manteniendo orden
  const uniqueAllowed = Array.from(new Set(allowed));

  if (uniqueAllowed.length > 0) {
    return uniqueAllowed;
  }

  // Fallback seguro si ningún correo cumplió la política de dominio
  return fallbackEmail ? [fallbackEmail.trim().toLowerCase()] : ['carlos.lugo@asimetrixtech.com'];
}

