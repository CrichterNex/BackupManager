import { Router } from 'express';
import bcrypt from 'bcryptjs';
import { z } from 'zod';
import { audit } from '../audit.js';
import { authenticate, session, setAuthCookie, signToken, COOKIE_NAME } from '../auth.js';
import { config } from '../config.js';
import { pool, withCompany } from '../db.js';
import { ApiError, badRequest, unauthorized } from '../errors.js';
import { assertStrongPassword } from '../passwords.js';

export const authRouter = Router();

// Compared against when the username does not exist, so response time does not reveal valid usernames.
const DUMMY_HASH = bcrypt.hashSync('not-a-real-password', config.bcryptCost);

const loginBody = z.object({ username: z.string().min(1).max(100), password: z.string().min(1).max(200) });

authRouter.post('/login', async (req, res) => {
  const { username, password } = loginBody.parse(req.body);
  const { rows } = await pool.query(
    `SELECT u.*, c.name AS company_name, c.slug AS company_slug, c.is_active AS company_active
       FROM users u JOIN companies c ON c.id = u.company_id
      WHERE LOWER(u.username) = LOWER($1)`,
    [username],
  );
  const user = rows[0];

  if (user?.auth_method === 'ldap') {
    throw new ApiError(503, 'AUTH_003', 'LDAP authentication is not configured on this server');
  }

  const passwordOk = await bcrypt.compare(password, user?.password_hash ?? DUMMY_HASH);
  if (!user || !passwordOk) {
    if (user) {
      await withCompany(user.company_id, (db) =>
        audit(db, req, { action: 'login_failed', resource_type: 'user', resource_id: user.id, success: false, actor: { company_id: user.company_id, user_id: user.id } }),
      );
    } else {
      await pool.query(`INSERT INTO audit_trail (action, details, ip_address, success) VALUES ('login_failed', $1, $2, false)`, [
        JSON.stringify({ username: username.slice(0, 100) }),
        req.ip ?? null,
      ]);
    }
    throw unauthorized('Invalid credentials', 'AUTH_001');
  }
  if (!user.active || !user.company_active) throw unauthorized('User account is disabled', 'AUTH_005');

  await withCompany(user.company_id, async (db) => {
    await db.query('UPDATE users SET last_login = NOW() WHERE id = $1', [user.id]);
    await audit(db, req, { action: 'login', resource_type: 'user', resource_id: user.id, actor: { company_id: user.company_id, user_id: user.id } });
  });

  const token = signToken(user.id);
  setAuthCookie(res, token);
  res.json({
    success: true,
    token,
    expires_in: config.sessionHours * 3600,
    user: { id: user.id, username: user.username, email: user.email, first_name: user.first_name, last_name: user.last_name },
    company: { id: user.company_id, company_name: user.company_name, company_slug: user.company_slug },
    role: user.role,
    redirect_to: `/companies/${user.company_slug}/dashboard`,
  });
});

authRouter.post('/logout', (_req, res) => {
  res.clearCookie(COOKIE_NAME);
  res.json({ success: true, message: 'Logged out successfully' });
});

export const meRouter = Router();
meRouter.use(authenticate);

meRouter.get('/', (req, res) => {
  const s = session(req);
  res.json({
    success: true,
    data: {
      id: s.user_id, username: s.username, email: s.email, first_name: s.first_name, last_name: s.last_name,
      company_id: s.company_id, company_name: s.company_name, role: s.role,
    },
  });
});

const changePassword = z.object({ current_password: z.string().min(1), new_password: z.string() });

meRouter.post('/change-password', async (req, res) => {
  const s = session(req);
  const body = changePassword.parse(req.body);
  assertStrongPassword(body.new_password);
  const { rows } = await pool.query('SELECT password_hash, auth_method FROM users WHERE id = $1', [s.user_id]);
  const row = rows[0];
  if (row?.auth_method !== 'local') throw badRequest('Password is managed by your directory (LDAP)');
  if (!(await bcrypt.compare(body.current_password, row.password_hash))) throw unauthorized('Current password is incorrect', 'AUTH_001');
  const hash = await bcrypt.hash(body.new_password, config.bcryptCost);
  await withCompany(s.company_id, async (db) => {
    await db.query('UPDATE users SET password_hash = $1, updated_at = NOW() WHERE id = $2', [hash, s.user_id]);
    await audit(db, req, { action: 'password_changed', resource_type: 'user', resource_id: s.user_id });
  });
  res.json({ success: true, message: 'Password changed' });
});
