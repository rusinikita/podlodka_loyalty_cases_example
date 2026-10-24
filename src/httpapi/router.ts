// App endpoints (/v1) and endpoints for the orders and pricing services
// (/v1/orders), see api/loyalty-api.yaml.
import { timingSafeEqual } from 'node:crypto';

import { type NextFunction, type Request, type Response, Router } from 'express';

import { DiscountUnavailableError, discountView } from '../discounts/discount.js';
import type { DiscountService } from '../discounts/service.js';
import { BadRequestError, body, cursor, int, nextCursor, positiveId, sendError, str } from '../http.js';
import { Channel, PromoError, type PromoErrorCode, type PromoService } from '../promo/service.js';
import { type ReferralService, rewardView } from '../referrals/service.js';
import { userFromHeaders } from './user.js';

const PAGE_SIZE = 50;

const promoErrorStatus: Record<PromoErrorCode, number> = {
  promo_not_found: 404,
  promo_already_used: 409,
  promo_expired: 422,
  promo_activations_exhausted: 422,
  promo_monthly_limit: 422,
  promo_country_unavailable: 422,
};

export function appRouter(
  promo: PromoService,
  discounts: DiscountService,
  referrals: ReferralService,
  serviceToken: string,
): Router {
  const router = Router();

  router.post('/promo/apply', async (req, res) => {
    const user = userFromHeaders(req);
    const b = body(req);
    const code = str(b, 'code');
    const channel = str(b, 'channel');
    if (channel !== Channel.Deeplink && channel !== Channel.Manual) {
      throw new BadRequestError('unknown channel');
    }

    try {
      const d = await promo.apply({
        userId: user.id,
        country: user.country,
        cityId: user.cityId,
        segment: user.segment,
        code,
        channel,
      });
      res.json(discountView(d));
    } catch (err) {
      if (err instanceof PromoError) {
        sendError(res, promoErrorStatus[err.code], err.code);
        return;
      }
      throw err;
    }
  });

  router.get('/discounts/active', async (req, res) => {
    const user = userFromHeaders(req);
    const items = await discounts.active(user.id);
    res.json({ items: items.map(discountView) });
  });

  router.get('/referrals/rewards', async (req, res) => {
    const user = userFromHeaders(req);
    const items = await referrals.byUser(user.id, cursor(req), PAGE_SIZE);
    res.json({ items: items.map(rewardView), next_cursor: nextCursor(items, PAGE_SIZE) });
  });

  const orders = Router();
  orders.use(requireServiceToken(serviceToken));

  orders.post('/discount-quote', async (req, res) => {
    const b = body(req);
    const orderedAt = str(b, 'ordered_at');
    if (Number.isNaN(Date.parse(orderedAt))) {
      throw new BadRequestError('invalid ordered_at');
    }
    const q = await discounts.quote({
      userId: int(b, 'user_id'),
      orderAmount: int(b, 'order_amount'),
      cityId: int(b, 'city_id'),
      orderedAt,
    });
    res.json({ discount_id: q.discountId, discount_amount: q.amount });
  });

  orders.post('/:order_id/redeem-discount', async (req, res) => {
    const orderId = positiveId(req.params.order_id, 'order_id');
    const b = body(req);
    try {
      await discounts.redeem(int(b, 'discount_id'), int(b, 'user_id'), orderId);
      res.status(204).end();
    } catch (err) {
      if (err instanceof DiscountUnavailableError) {
        sendError(res, 409, 'discount_unavailable');
        return;
      }
      throw err;
    }
  });

  router.use('/orders', orders);
  return router;
}

function requireServiceToken(token: string) {
  const expected = Buffer.from(token);
  return (req: Request, res: Response, next: NextFunction) => {
    const got = Buffer.from(req.get('X-Service-Token') ?? '');
    if (got.length !== expected.length || !timingSafeEqual(got, expected)) {
      sendError(res, 401, 'unauthorized');
      return;
    }
    next();
  };
}
