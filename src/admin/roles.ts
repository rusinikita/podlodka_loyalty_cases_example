import type { NextFunction, Request, Response } from 'express';

import { sendError } from '../http.js';

// Roles of the admin panel, from the lowest. Each role can do everything the
// previous ones can (docs/admin_permissions.md).
export const Role = {
  SupportL1: 'support_l1',
  SupportL2: 'support_l2',
  LoyaltyOperator: 'loyalty_operator',
  LoyaltyAdmin: 'loyalty_admin',
} as const;

const roleRank: Record<string, number> = {
  [Role.SupportL1]: 1,
  [Role.SupportL2]: 2,
  [Role.LoyaltyOperator]: 3,
  [Role.LoyaltyAdmin]: 4,
};

export interface Operator {
  login: string;
  role: string;
}

/** Reads the operator passed by the admin panel in headers. */
export function withOperator(req: Request, res: Response, next: NextFunction): void {
  const op: Operator = {
    login: req.get('X-Operator-Login') ?? '',
    role: req.get('X-Operator-Role') ?? '',
  };
  if (!op.login || !roleRank[op.role]) {
    sendError(res, 403, 'forbidden');
    return;
  }
  res.locals.operator = op;
  next();
}

export function requireRole(min: string) {
  return (_req: Request, res: Response, next: NextFunction): void => {
    if ((roleRank[operatorOf(res).role] ?? 0) < (roleRank[min] ?? 0)) {
      sendError(res, 403, 'forbidden');
      return;
    }
    next();
  };
}

export function operatorOf(res: Response): Operator {
  return res.locals.operator as Operator;
}
