// JSON helpers shared by the HTTP routers.
import type { NextFunction, Request, Response } from 'express';
import type { Logger } from 'pino';

/** Writes an API error: code is the machine-readable error from the spec, message is optional. */
export function sendError(res: Response, status: number, code: string, message?: string): void {
  res.status(status).json(message ? { error: code, message } : { error: code });
}

export class BadRequestError extends Error {}

/** Last middleware: bad requests become 400, everything else is logged and becomes 500. */
export function errorHandler(log: Logger) {
  return (err: unknown, req: Request, res: Response, _next: NextFunction): void => {
    if (err instanceof BadRequestError || (err instanceof SyntaxError && 'body' in err)) {
      sendError(res, 400, 'bad_request', err.message);
      return;
    }
    log.error({ method: req.method, path: req.path, err }, 'request failed');
    sendError(res, 500, 'internal');
  };
}

/** The cursor query parameter: the id of the last item of the previous page, 0 for the first page. */
export function cursor(req: Request): number {
  const s = req.query.cursor;
  if (s === undefined || s === '') {
    return 0;
  }
  if (typeof s !== 'string' || !/^\d+$/.test(s)) {
    throw new BadRequestError('invalid cursor');
  }
  return Number(s);
}

/** The cursor of the next page, or null if the page is not full. */
export function nextCursor(items: Array<{ id: number }>, limit: number): string | null {
  const last = items.at(-1);
  if (!last || items.length < limit) {
    return null;
  }
  return String(last.id);
}

export function positiveId(value: unknown, name: string): number {
  const id = typeof value === 'string' && /^\d+$/.test(value) ? Number(value) : 0;
  if (!Number.isSafeInteger(id) || id <= 0) {
    throw new BadRequestError(`invalid ${name}`);
  }
  return id;
}

/** The parsed JSON body as an object. */
export function body(req: Request): Record<string, unknown> {
  const b: unknown = req.body;
  if (typeof b !== 'object' || b === null || Array.isArray(b)) {
    throw new BadRequestError('request body must be a JSON object');
  }
  return b as Record<string, unknown>;
}

export function int(b: Record<string, unknown>, name: string): number {
  const v = b[name];
  if (typeof v !== 'number' || !Number.isInteger(v)) {
    throw new BadRequestError(`${name} must be an integer`);
  }
  return v;
}

export function str(b: Record<string, unknown>, name: string): string {
  const v = b[name];
  if (typeof v !== 'string') {
    throw new BadRequestError(`${name} must be a string`);
  }
  return v;
}
