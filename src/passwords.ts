import { badRequest } from './errors.js';

export function assertStrongPassword(password: string): void {
  if (password.length < 12) throw badRequest('Password must be at least 12 characters');
  if (password.length > 200) throw badRequest('Password is too long');
}
