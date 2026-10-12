import type { Session } from './types.js';

/**
 * Technicians only see servers they are assigned to. Appends the user id to `params` and returns
 * an SQL condition for the given server id expression ('' for admins and managers).
 */
export function technicianScope(s: Session, serverIdExpr: string, params: unknown[]): string {
  if (s.role !== 'company_technician') return '';
  params.push(s.user_id);
  return ` AND ${serverIdExpr} IN (SELECT server_id FROM server_assignments WHERE user_id = $${params.length})`;
}
