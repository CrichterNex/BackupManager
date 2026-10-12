import { createApp } from './app.js';
import { config } from './config.js';
import { migrate } from './migrate.js';
import { pool } from './db.js';
import { startEngine, stopEngine } from './engine/engine.js';

const applied = await migrate();
if (applied.length) console.log(`Applied migrations: ${applied.join(', ')}`);

const server = createApp().listen(config.port, () => console.log(`BMS listening on http://localhost:${config.port}`));
startEngine();

for (const sig of ['SIGINT', 'SIGTERM'] as const) {
  process.on(sig, () => {
    stopEngine();
    server.close(() => pool.end().then(() => process.exit(0)));
  });
}
