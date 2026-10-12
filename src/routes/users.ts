import { Router } from 'express';
import bcrypt from 'bcryptjs';
import { z } from 'zod';
import { audit } from '../audit.js';
import { require_, session } from '../auth.js';
import { config } from '../config.js';
import { pool, withCompany } from '../db.js';
import { badRequest, conflict, forbidden, notFound } from '../errors.js';
import { idParam } from '../http.js';
import { assertStrongPassword } from '../passwords.js';

export const usersRouter = Router();
usersRouter.use(require_('users:manage'));

const roles = z.enum(['company_admin', 'company_manager', 'company_technician']);
const PUBLIC = 'id, username, email, first_name, last_name, phone, role, auth_method, active, last_login, created_at';

usersRouter.get('/', async (req, res) => {
  const { rows } = await pool.query(`SELECT ${PUBLIC} FROM users WHERE company_id = $1 ORDER BY username`, [session(req).company_id]);
  res.json({ success: true, data: rows });
});

const createBody = z.object({
  username: z.string().min(3).max(100).regex(/^[A-Za-z0-9._@-]+$/, 'Letters, digits and . _ @ - only'),
  email: z.string().email().max(255),
  first_name: z.string().max(100).optional(),
  last_name: z.string().max(100).optional(),
  phone: z.string().max(30).optional(),
  role: roles,
  auth_method: z.enum(['local', 'ldap']).default('local'),
  password: z.string().optional(),
  company_id: z.number().int().optional(),
});

usersRouter.post('/', async (req, res) => {
  const s = session(req);
  const body = createBody.parse(req.body);
  // The spec requires an explicit refusal rather than silently overriding another company's id.
  if (body.company_id !== undefined && body.company_id !== s.company_id) throw forbidden('You can only create users in your assigned company');
  let hash: string | null = null;
  if (body.auth_method === 'local') {
    if (!body.password) throw badRequest('A password is required for local users');
    assertStrongPassword(body.password);
    hash = await bcrypt.hash(body.password, config.bcryptCost);
  }
  const user = await withCompany(s.company_id, async (db) => {
    const taken = await db.query('SELECT 1 FROM users WHERE LOWER(username) = LOWER($1)', [body.username]);
    if (taken.rowCount) throw conflict('USER_002', 'Username already exists');
    const { rows } = await db.query(
      `INSERT INTO users (company_id, username, email, first_name, last_name, phone, role, auth_method, password_hash, ldap_user)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) RETURNING ${PUBLIC}`,
      [s.company_id, body.username, body.email, body.first_name ?? null, body.last_name ?? null, body.phone ?? null,
        body.role, body.auth_method, hash, body.auth_method === 'ldap' ? body.username : null],
    );
    await audit(db, req, { action: 'user_created', resource_type: 'user', resource_id: rows[0].id, details: { username: body.username, role: body.role } });
    return rows[0];
  });
  res.status(201).json({ success: true, data: user });
});

const patchBody = z
  .object({
    email: z.string().email().max(255),
    first_name: z.string().max(100).nullable(),
    last_name: z.string().max(100).nullable(),
    phone: z.string().max(30).nullable(),
    role: roles,
    active: z.boolean(),
    password: z.string(),
  })
  .partial()
  .refine((b) => Object.keys(b).length > 0, 'No fields to update');

usersRouter.patch('/:id', async (req, res) => {
  const s = session(req);
  const id = idParam(req);
  const body = patchBody.parse(req.body);
  // Prevents an admin locking their own company out by demoting or disabling themselves.
  if (id === s.user_id && (body.role !== undefined || body.active !== undefined)) {
    throw badRequest('You cannot change your own role or active status');
  }
  const sets: string[] = [];
  const params: unknown[] = [];
  const add = (col: string, val: unknown) => { params.push(val); sets.push(`${col} = $${params.length}`); };
  for (const col of ['email', 'first_name', 'last_name', 'phone', 'role', 'active'] as const) {
    if (body[col] !== undefined) add(col, body[col]);
  }
  if (body.password !== undefined) {
    assertStrongPassword(body.password);
    add('password_hash', await bcrypt.hash(body.password, config.bcryptCost));
  }
  const user = await withCompany(s.company_id, async (db) => {
    params.push(id, s.company_id);
    const { rows } = await db.query(
      `UPDATE users SET ${sets.join(', ')}, updated_at = NOW()
        WHERE id = $${params.length - 1} AND company_id = $${params.length}
        ${body.password !== undefined ? `AND auth_method = 'local'` : ''}
        RETURNING ${PUBLIC}`,
      params,
    );
    if (!rows[0]) {
      const exists = await db.query('SELECT auth_method FROM users WHERE id = $1 AND company_id = $2', [id, s.company_id]);
      if (exists.rows[0]) throw badRequest('Password is managed by LDAP for this user');
      throw notFound('User', 'USER_001');
    }
    const { password, ...changed } = body;
    await audit(db, req, { action: 'user_updated', resource_type: 'user', resource_id: id, details: { ...changed, password_reset: password !== undefined } });
    return rows[0];
  });
  res.json({ success: true, data: user });
});
