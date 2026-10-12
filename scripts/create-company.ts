// Provision a tenant and its first admin. There is deliberately no API for this: the v3.0 model has
// no cross-company or platform-level role, so onboarding is an operator task run on the server.
//   npm run company:create -- --name "TechCorp Inc" --slug techcorp-inc --admin alice --email alice@techcorp.com
import bcrypt from 'bcryptjs';
import { parseArgs } from 'node:util';
import { randomBytes } from 'node:crypto';
import { config } from '../src/config.js';
import { pool, withTransaction } from '../src/db.js';

const { values } = parseArgs({
  options: {
    name: { type: 'string' }, slug: { type: 'string' }, tier: { type: 'string', default: 'basic' },
    admin: { type: 'string' }, email: { type: 'string' }, password: { type: 'string' },
  },
});
if (!values.name || !values.slug || !values.admin || !values.email) {
  console.error('Required: --name --slug --admin --email   (optional: --tier --password)');
  process.exit(1);
}
const generated = !values.password;
const password = values.password ?? randomBytes(12).toString('base64url');
if (password.length < 12) { console.error('Password must be at least 12 characters'); process.exit(1); }

try {
  const company = await withTransaction(async (db) => {
    const c = await db.query('INSERT INTO companies (name, slug, subscription_tier, contact_email) VALUES ($1,$2,$3,$4) RETURNING id', [values.name, values.slug, values.tier, values.email]);
    await db.query(
      `INSERT INTO users (company_id, username, email, role, auth_method, password_hash) VALUES ($1,$2,$3,'company_admin','local',$4)`,
      [c.rows[0].id, values.admin, values.email, await bcrypt.hash(password, config.bcryptCost)],
    );
    return c.rows[0].id;
  });
  console.log(`Created company ${company} "${values.name}" with admin "${values.admin}".`);
  if (generated) console.log(`Temporary password (shown once): ${password}`);
} catch (err) {
  console.error((err as Error).message);
  process.exitCode = 1;
} finally {
  await pool.end();
}
