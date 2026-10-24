// Admin panel endpoints (/admin/v1): campaigns, compensation discounts and
// user rewards.
import { type Response, Router } from 'express';

import type { CampaignInput } from '../campaigns/admin.js';
import {
  CampaignConflictError,
  CampaignNotFoundError,
  campaignView,
  InvalidCampaignError,
  RewardType,
} from '../campaigns/campaign.js';
import type { CampaignService } from '../campaigns/service.js';
import {
  CommentRequiredError,
  CompensationLimitError,
  discountView,
} from '../discounts/discount.js';
import type { DiscountService } from '../discounts/service.js';
import { BadRequestError, body, cursor, int, nextCursor, positiveId, sendError, str } from '../http.js';
import { type ReferralService, rewardView } from '../referrals/service.js';
import { operatorOf, requireRole, Role, withOperator } from './roles.js';

const PAGE_SIZE = 50;

export function adminRouter(
  campaigns: CampaignService,
  discounts: DiscountService,
  referrals: ReferralService,
  compensationRole: string,
): Router {
  const router = Router();
  router.use(withOperator);

  router.get('/campaigns', async (req, res) => {
    const q = req.query;
    let active: boolean | undefined;
    if (q.active !== undefined && q.active !== '') {
      if (q.active !== 'true' && q.active !== 'false') {
        throw new BadRequestError('invalid active');
      }
      active = q.active === 'true';
    }
    const items = await campaigns.search({
      query: typeof q.query === 'string' ? q.query : '',
      trigger: typeof q.trigger === 'string' ? q.trigger : '',
      active,
      afterId: cursor(req),
      limit: PAGE_SIZE,
    });
    res.json({ items: items.map(campaignView), next_cursor: nextCursor(items, PAGE_SIZE) });
  });

  router.post('/campaigns', requireRole(Role.LoyaltyAdmin), async (req, res) => {
    try {
      const c = await campaigns.create(body(req) as CampaignInput, operatorOf(res).login);
      res.status(201).json(campaignView(c));
    } catch (err) {
      campaignError(res, err);
    }
  });

  router.get('/campaigns/:id', requireRole(Role.SupportL2), async (req, res) => {
    const id = positiveId(req.params.id, 'id');
    try {
      const c = await campaigns.get(id);
      let activations = 0;
      switch (c.rewardType) {
        case RewardType.Discount:
          activations = await discounts.countByCampaign(c.id);
          break;
        case RewardType.Referral:
          activations = await referrals.countByCampaign(c.id);
          break;
      }
      res.json({ ...campaignView(c), activations });
    } catch (err) {
      campaignError(res, err);
    }
  });

  router.patch('/campaigns/:id', requireRole(Role.LoyaltyAdmin), async (req, res) => {
    const id = positiveId(req.params.id, 'id');
    try {
      const c = await campaigns.update(id, body(req) as CampaignInput, operatorOf(res).login);
      res.json(campaignView(c));
    } catch (err) {
      campaignError(res, err);
    }
  });

  router.get('/users/:user_id/discounts', async (req, res) => {
    const userId = positiveId(req.params.user_id, 'user_id');
    const items = await discounts.byUser(userId, cursor(req), PAGE_SIZE);
    res.json({ items: items.map(discountView), next_cursor: nextCursor(items, PAGE_SIZE) });
  });

  router.post('/users/:user_id/discounts', requireRole(compensationRole), async (req, res) => {
    const userId = positiveId(req.params.user_id, 'user_id');
    const b = body(req);
    try {
      const d = await discounts.grantCompensation({
        userId,
        percent: int(b, 'percent'),
        maxAmount: b.max_amount == null ? null : int(b, 'max_amount'),
        ttlDays: int(b, 'ttl_days'),
        operator: operatorOf(res).login,
        comment: b.comment == null ? '' : str(b, 'comment'),
      });
      res.status(201).json(discountView(d));
    } catch (err) {
      if (err instanceof CompensationLimitError) {
        sendError(res, 422, 'compensation_limit');
        return;
      }
      if (err instanceof CommentRequiredError) {
        sendError(res, 400, 'bad_request', err.message);
        return;
      }
      throw err;
    }
  });

  router.get('/users/:user_id/referral-rewards', async (req, res) => {
    const userId = positiveId(req.params.user_id, 'user_id');
    const items = await referrals.byUser(userId, 0, PAGE_SIZE);
    res.json({ items: items.map(rewardView) });
  });

  return router;
}

function campaignError(res: Response, err: unknown): void {
  if (err instanceof CampaignNotFoundError) {
    sendError(res, 404, 'not_found');
  } else if (err instanceof CampaignConflictError) {
    sendError(res, 409, 'conflict', err.message);
  } else if (err instanceof InvalidCampaignError) {
    sendError(res, 400, 'bad_request', err.message);
  } else {
    throw err;
  }
}
