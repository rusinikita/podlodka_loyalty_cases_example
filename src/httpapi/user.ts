import type { Request } from 'express';

import { BadRequestError } from '../http.js';

/** The user context passed by the API gateway in headers. */
export interface User {
  id: number;
  country: string;
  cityId?: number;
  segment: string;
}

export function userFromHeaders(req: Request): User {
  const id = Number(req.get('X-User-Id'));
  if (!Number.isSafeInteger(id) || id <= 0) {
    throw new BadRequestError('invalid X-User-Id');
  }
  const user: User = {
    id,
    country: req.get('X-User-Country') ?? '',
    segment: req.get('X-User-Segment') ?? '',
  };
  const city = req.get('X-User-City-Id');
  if (city) {
    user.cityId = Number(city);
    if (!Number.isInteger(user.cityId)) {
      throw new BadRequestError('invalid X-User-City-Id');
    }
  }
  return user;
}
