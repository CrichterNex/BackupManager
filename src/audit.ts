import type { Request } from 'express';
import type { Db } from './db.js';

export interface AuditEvent {
  action: string;
  resource_type?: string;
  resource_id?: number | null;
  details?: Record<string, unknown>;
  success?: boolean;
  /** Set when there is no session yet, e.g. a failed login for a known user. */
  actor?: { company_id: number; user_id: number };
}

/** Write an audit row inside the caller's transaction so the log and the change commit together. */
export async function audit(db: Db, req: Request, event: AuditEvent): Promise<void> {
  const s = req.session;
  await db.query(
    `INSERT INTO audit_trail (company_id, user_id, action, resource_type, resource_id, details, ip_address, success)
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8)`,
    [
      event.actor?.company_id ?? s?.company_id ?? null,
      event.actor?.user_id ?? s?.user_id ?? null,
      event.action,
      event.resource_type ?? null,
      event.resource_id ?? null,
      event.details ? JSON.stringify(event.details) : null,
      req.ip ?? null,
      event.success ?? true,
    ],
  );
}
