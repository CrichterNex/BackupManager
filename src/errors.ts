import type { ErrorRequestHandler, RequestHandler } from 'express';
import { ZodError } from 'zod';
import { randomUUID } from 'node:crypto';

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public extra?: Record<string, unknown>,
  ) {
    super(message);
  }
}

export const badRequest = (msg: string, extra?: Record<string, unknown>) => new ApiError(400, 'VALIDATION_001', msg, extra);
export const unauthorized = (msg = 'Authentication required', code = 'AUTH_002') => new ApiError(401, code, msg);
export const forbidden = (msg = 'You do not have permission to do this') => new ApiError(403, 'AUTH_004', msg);
export const notFound = (what: string, code = 'NOT_FOUND') => new ApiError(404, code, `${what} not found`);
export const conflict = (code: string, msg: string) => new ApiError(409, code, msg);

export const requestId: RequestHandler = (req, res, next) => {
  req.requestId = randomUUID();
  res.setHeader('X-Request-Id', req.requestId);
  next();
};

export const notFoundHandler: RequestHandler = (_req, _res, next) => next(notFound('Route', 'ROUTE_001'));

export const errorHandler: ErrorRequestHandler = (err, req, res, _next) => {
  if (err instanceof ZodError) {
    res.status(400).json({
      success: false,
      error: 'Invalid request',
      error_code: 'VALIDATION_001',
      details: err.issues.map((i) => ({ field: i.path.join('.'), message: i.message })),
      request_id: req.requestId,
    });
    return;
  }
  if (err instanceof ApiError) {
    res.status(err.status).json({
      success: false,
      error: err.message,
      error_code: err.code,
      ...err.extra,
      request_id: req.requestId,
    });
    return;
  }
  if (err?.code === '23505') {
    res.status(409).json({ success: false, error: 'A record with these details already exists', error_code: 'CONFLICT_001', request_id: req.requestId });
    return;
  }
  if (err?.type === 'entity.parse.failed') {
    res.status(400).json({ success: false, error: 'Malformed JSON body', error_code: 'VALIDATION_001', request_id: req.requestId });
    return;
  }
  console.error(`[${req.requestId}]`, err);
  res.status(500).json({ success: false, error: 'Internal server error', error_code: 'SERVER_500', request_id: req.requestId });
};
