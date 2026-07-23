import app from './app.js';
import { config } from './config.js';

app.listen(config.port, () => {
  console.log(`==================================================`);
  console.log(`Servidor de Facturación Digital TFHKA iniciado.`);
  console.log(`Puerto: ${config.port}`);
  console.log(`Entorno TFHKA Base: ${config.tfhka.baseUrl}`);
  console.log(`==================================================`);
});

export default app;
