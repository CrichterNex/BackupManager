import { createApp } from './app.js';
import { config } from './config.js';
import { migrate } from './migrate.js';
import { pool } from './db.js';

const applied = await migrate();
if (applied.length) console.log(`Applied migrations: ${applied.join(', ')}`);

const server = createApp().listen(config.port, () => console.log(`BMS listening on http://localhost:${config.port}`));

for (const sig of ['SIGINT', 'SIGTERM'] as const) {
  process.on(sig, () => server.close(() => pool.end().then(() => process.exit(0))));
}
