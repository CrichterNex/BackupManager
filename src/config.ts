import 'dotenv/config';

function required(name: string): string {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable ${name}`);
  return value;
}

const jwtSecret = required('JWT_SECRET');
if (jwtSecret.length < 32) throw new Error('JWT_SECRET must be at least 32 characters');

export const config = {
  databaseUrl: required('DATABASE_URL'),
  jwtSecret,
  port: Number(process.env.PORT ?? 3000),
  isProduction: process.env.NODE_ENV === 'production',
  // Sessions last 8 hours, matching the API spec's auth_token cookie.
  sessionHours: 8,
  bcryptCost: Number(process.env.BCRYPT_COST ?? 12),
  rateLimitPerHour: Number(process.env.RATE_LIMIT_PER_HOUR ?? 1000),
  loginAttemptsPer15Min: Number(process.env.LOGIN_ATTEMPTS_PER_15_MIN ?? 10),
  trustProxy: process.env.TRUST_PROXY === 'true',
};
