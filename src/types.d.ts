export type Role = 'company_admin' | 'company_manager' | 'company_technician';

export interface Session {
  user_id: number;
  username: string;
  email: string;
  first_name: string | null;
  last_name: string | null;
  company_id: number;
  company_name: string;
  company_slug: string;
  role: Role;
}

declare global {
  namespace Express {
    interface Request {
      requestId: string;
      session?: Session;
    }
  }
}
