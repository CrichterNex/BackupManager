import path from 'node:path';
import { fileURLToPath } from 'node:url';
import cookieParser from 'cookie-parser';
import express from 'express';
import helmet from 'helmet';
import rateLimit from 'express-rate-limit';
import { authenticate } from './auth.js';
import { config } from './config.js';
import { errorHandler, notFoundHandler, requestId } from './errors.js';
import { authRouter, meRouter } from './routes/auth.js';
import { jobsRouter } from './routes/jobs.js';
import { auditRouter, dashboardRouter, notificationsRouter } from './routes/misc.js';
import { serversRouter } from './routes/servers.js';
import { testsRouter } from './routes/tests.js';
import { usersRouter } from './routes/users.js';

const publicDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'public');

/** One app serves both the JSON API (/api) and the web UI (/), so there is a single thing to deploy. */
export function createApp() {
  const app = express();
  if (config.trustProxy) app.set('trust proxy', 1);
  app.disable('x-powered-by');
  app.use(requestId);
  app.use(helmet());
  app.use(express.json({ limit: '1mb' }));
  app.use(cookieParser());

  const api = express.Router();
  api.use((_req, res, next) => { res.setHeader('Cache-Control', 'no-store'); next(); });

  // Brute-force protection on login, per client address.
  api.use('/auth/login', rateLimit({
    windowMs: 15 * 60 * 1000, limit: config.loginAttemptsPer15Min, standardHeaders: true, legacyHeaders: false,
    handler: (req, res) => res.status(429).json({ success: false, error: 'Too many login attempts. Try again later.', error_code: 'RATE_LIMIT', request_id: req.requestId }),
  }));
  api.use('/auth', authRouter);

  api.use(authenticate);
  // Per-user limit (1000/hour by default) applied once the caller is known.
  api.use(rateLimit({
    windowMs: 60 * 60 * 1000, limit: config.rateLimitPerHour, standardHeaders: true, legacyHeaders: false,
    keyGenerator: (req) => `user:${req.session?.user_id}`,
    handler: (req, res) => res.status(429).json({ success: false, error: 'Rate limit exceeded', error_code: 'RATE_LIMIT', request_id: req.requestId }),
  }));
  api.use('/me', meRouter);
  api.use('/users', usersRouter);
  api.use('/servers', serversRouter);
  api.use('/backup-jobs', jobsRouter);
  api.use('/notifications', notificationsRouter);
  api.use('/audit', auditRouter);
  api.use('/', testsRouter);
  api.use('/', dashboardRouter);
  api.use(notFoundHandler);
  app.use('/api', api);

  app.use(express.static(publicDir, { index: 'index.html' }));
  // Single-page app: any non-API path falls through to the UI shell.
  app.get(/^\/(?!api\/).*/, (_req, res) => res.sendFile(path.join(publicDir, 'index.html')));

  app.use(errorHandler);
  return app;
}
