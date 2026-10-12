import type { Request, RequestHandler, Response } from 'express';
import jwt from 'jsonwebtoken';
import { config } from './config.js';
import { pool } from './db.js';
import { forbidden, unauthorized } from './errors.js';
import type { Role, Session } from './types.js';

export const COOKIE_NAME = 'auth_token';

export function signToken(userId: number): string {
  return jwt.sign({ sub: String(userId) }, config.jwtSecret, {
    algorithm: 'HS256',
    expiresIn: `${config.sessionHours}h`,
  });
}

export function setAuthCookie(res: Response, token: string): void {
  res.cookie(COOKIE_NAME, token, {
    httpOnly: true,
    secure: config.isProduction,
    sameSite: 'strict',
    maxAge: config.sessionHours * 3600 * 1000,
  });
}

function readToken(req: Request): string | undefined {
  const header = req.headers.authorization;
  if (header?.startsWith('Bearer ')) return header.slice(7);
  return req.cookies?.[COOKIE_NAME];
}

/**
 * The token only identifies the user. Company, role and active state are read fresh from the
 * database on every request, so deactivating a user or changing a role takes effect immediately
 * and a token can never carry a company the user no longer belongs to.
 */
export const authenticate: RequestHandler = async (req, _res, next) => {
  const token = readToken(req);
  if (!token) throw unauthorized();
  let userId: number;
  try {
    const payload = jwt.verify(token, config.jwtSecret, { algorithms: ['HS256'] });
    userId = Number(payload.sub);
  } catch {
    throw unauthorized('Token expired or invalid. Please log in again.');
  }
  const { rows } = await pool.query(
    `SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.role, u.active AS user_active,
            c.id AS company_id, c.name AS company_name, c.slug AS company_slug, c.is_active AS company_active
       FROM users u JOIN companies c ON c.id = u.company_id
      WHERE u.id = $1`,
    [userId],
  );
  const row = rows[0];
  if (!row || !row.user_active || !row.company_active) throw unauthorized('Account is disabled');
  const session: Session = {
    user_id: row.id,
    username: row.username,
    email: row.email,
    first_name: row.first_name,
    last_name: row.last_name,
    company_id: row.company_id,
    company_name: row.company_name,
    company_slug: row.company_slug,
    role: row.role,
  };
  req.session = session;
  next();
};

export function session(req: Request): Session {
  if (!req.session) throw unauthorized();
  return req.session;
}

/** Permission matrix from SINGLE_COMPANY_PER_USER.md, Part 6. */
export const PERMISSIONS = {
  'servers:read': ['company_admin', 'company_manager', 'company_technician'],
  'servers:write': ['company_admin'],
  'jobs:read': ['company_admin', 'company_manager', 'company_technician'],
  'jobs:write': ['company_admin'],
  'tests:read': ['company_admin', 'company_manager', 'company_technician'],
  'tests:run': ['company_admin', 'company_technician'],
  'failures:resolve': ['company_admin', 'company_technician'],
  'users:manage': ['company_admin'],
  'reports:read': ['company_admin', 'company_manager'],
  'reports:generate': ['company_admin', 'company_manager'],
  'audit:read': ['company_admin'],
  'dashboard:read': ['company_admin', 'company_manager', 'company_technician'],
} as const satisfies Record<string, readonly Role[]>;

export type Permission = keyof typeof PERMISSIONS;

export const can = (role: Role, permission: Permission): boolean => (PERMISSIONS[permission] as readonly Role[]).includes(role);

export const require_ =
  (permission: Permission): RequestHandler =>
  (req, _res, next) => {
    if (!can(session(req).role, permission)) throw forbidden();
    next();
  };
