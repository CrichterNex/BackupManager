import type { Request } from 'express';
import { z } from 'zod';
import { badRequest } from './errors.js';

export const idParam = (req: Request, name = 'id'): number => {
  const value = Number(req.params[name]);
  if (!Number.isInteger(value) || value < 1) throw badRequest(`Invalid ${name}`);
  return value;
};

const paging = z.object({
  page: z.coerce.number().int().min(1).default(1),
  limit: z.coerce.number().int().min(1).max(1000).default(50),
});

export function pageParams(req: Request) {
  const { page, limit } = paging.parse(req.query);
  return { page, limit, offset: (page - 1) * limit };
}

export const pagination = (page: number, limit: number, total: number) => ({
  page,
  limit,
  total_count: total,
  total_pages: Math.max(1, Math.ceil(total / limit)),
});

/** Whitelisted ORDER BY so sort input never reaches SQL directly. */
export function orderBy(req: Request, allowed: Record<string, string>, fallback: string): string {
  const key = typeof req.query.sort_by === 'string' ? req.query.sort_by : undefined;
  const column = (key && allowed[key]) || allowed[fallback]!;
  const dir = req.query.sort_order === 'asc' ? 'ASC' : 'DESC';
  return `${column} ${dir}`;
}
