import pg from 'pg';
import { config } from './config.js';

export const pool = new pg.Pool({ connectionString: config.databaseUrl });

// Parse NUMERIC (1700) and BIGINT (20) as JS numbers instead of strings.
pg.types.setTypeParser(1700, (v) => Number(v));
pg.types.setTypeParser(20, (v) => Number(v));

export type Db = pg.PoolClient;

/**
 * Run work inside a transaction scoped to one company. Postgres row-level security
 * only lets the transaction see and write rows whose company_id matches, so a missed
 * WHERE clause in a route cannot leak another tenant's data.
 */
export async function withCompany<T>(companyId: number, fn: (db: Db) => Promise<T>): Promise<T> {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    await client.query(`SELECT set_config('app.company_id', $1, true)`, [String(companyId)]);
    const result = await fn(client);
    await client.query('COMMIT');
    return result;
  } catch (err) {
    await client.query('ROLLBACK').catch(() => undefined);
    throw err;
  } finally {
    client.release();
  }
}

/** Plain transaction with no tenant set. Only for users/companies, which are filtered in code. */
export async function withTransaction<T>(fn: (db: Db) => Promise<T>): Promise<T> {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const result = await fn(client);
    await client.query('COMMIT');
    return result;
  } catch (err) {
    await client.query('ROLLBACK').catch(() => undefined);
    throw err;
  } finally {
    client.release();
  }
}
