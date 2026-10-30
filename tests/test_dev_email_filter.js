import assert from 'assert';
import { filterDevAllowedEmails } from '../src/utils/devEmailFilter.js';

console.log('--- Probando Directiva de Filtrado de Correos de Desarrollo ---');

// 1. Filtrar dominio externo cuando viene con cuenta @asimetrixtech.com
const test1 = filterDevAllowedEmails([
  'carlos.lugo@asimetrixtech.com',
  'carloslugogaleana@gmail.com'
]);
console.log('Test 1 (Mixto con @asimetrixtech.com):', test1);
assert.deepStrictEqual(test1, ['carlos.lugo@asimetrixtech.com']);

// 2. Múltiples cuentas @asimetrixtech.com con duplicados
const test2 = filterDevAllowedEmails([
  'carlos.lugo@asimetrixtech.com',
  'facturacion@asimetrixtech.com',
  'carlos.lugo@asimetrixtech.com'
]);
console.log('Test 2 (Múltiples asimetrixtech sin duplicados):', test2);
assert.deepStrictEqual(test2, ['carlos.lugo@asimetrixtech.com', 'facturacion@asimetrixtech.com']);

// 3. Cadena delimitada por punto y coma
const test3 = filterDevAllowedEmails('carlos.lugo@asimetrixtech.com; cliente@gmail.com; soporte@asimetrixtech.com');
console.log('Test 3 (String con punto y coma):', test3);
assert.deepStrictEqual(test3, ['carlos.lugo@asimetrixtech.com', 'soporte@asimetrixtech.com']);

// 4. Solo correos externos (debe aplicar fallback)
const test4 = filterDevAllowedEmails(['cliente@empresa.com', 'proveedor@outlook.com']);
console.log('Test 4 (Solo externos -> Fallback):', test4);
assert.deepStrictEqual(test4, ['carlos.lugo@asimetrixtech.com']);

// 5. Vacío o nulo (debe aplicar fallback)
const test5 = filterDevAllowedEmails([]);
console.log('Test 5 (Vacío -> Fallback):', test5);
assert.deepStrictEqual(test5, ['carlos.lugo@asimetrixtech.com']);

console.log('\n✓ ¡Todas las pruebas de la directiva de correos pasaron exitosamente!');
