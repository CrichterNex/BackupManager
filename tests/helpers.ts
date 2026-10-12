import bcrypt from 'bcryptjs';
import request from 'supertest';
import { createApp } from '../src/app.js';
import { pool } from '../src/db.js';
import type { Role } from '../src/types.js';

export const app = createApp();
export const PASSWORD = 'correct-horse-battery';

export async function resetDb() {
  await pool.query(`TRUNCATE reports, notifications, server_assignments, backup_test_failures, backup_test_executions,
    backup_jobs, servers, audit_trail, users, companies RESTART IDENTITY CASCADE`);
}

export async function makeCompany(slug: string, extra: { max_servers?: number } = {}) {
  const { rows } = await pool.query('INSERT INTO companies (name, slug, max_servers) VALUES ($1,$2,$3) RETURNING id', [slug.toUpperCase(), slug, extra.max_servers ?? null]);
  return rows[0].id as number;
}

export async function makeUser(companyId: number, username: string, role: Role, active = true) {
  const hash = await bcrypt.hash(PASSWORD, 4);
  const { rows } = await pool.query(
    `INSERT INTO users (company_id, username, email, role, auth_method, password_hash, active) VALUES ($1,$2,$3,$4,'local',$5,$6) RETURNING id`,
    [companyId, username, `${username}@example.com`, role, hash, active],
  );
  return rows[0].id as number;
}

/** Log in and return a supertest agent-like helper bound to the bearer token. */
export async function login(username: string, password = PASSWORD) {
  const res = await request(app).post('/api/auth/login').send({ username, password });
  if (res.status !== 200) throw new Error(`login failed for ${username}: ${res.status} ${JSON.stringify(res.body)}`);
  const token = res.body.token as string;
  const auth = (r: request.Test) => r.set('Authorization', `Bearer ${token}`);
  return {
    token,
    get: (url: string) => auth(request(app).get(url)),
    post: (url: string, body?: object) => auth(request(app).post(url)).send(body ?? {}),
    patch: (url: string, body: object) => auth(request(app).patch(url)).send(body),
    put: (url: string, body: object) => auth(request(app).put(url)).send(body),
    del: (url: string) => auth(request(app).delete(url)),
  };
}

export type Client = Awaited<ReturnType<typeof login>>;
