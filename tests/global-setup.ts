// globalSetup runs outside the test workers, so the test env has to be set here as well.
process.env.DATABASE_URL = process.env.TEST_DATABASE_URL ?? 'postgres://bms:bms@localhost:5432/bms_test';
process.env.JWT_SECRET = 'test-secret-test-secret-test-secret-123';

export default async function setup() {
  const { migrate } = await import('../src/migrate.js');
  const { pool } = await import('../src/db.js');
  await migrate();
  await pool.end();
}
