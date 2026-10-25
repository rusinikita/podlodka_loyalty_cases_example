<?php

declare(strict_types=1);

namespace App\Promo;

use App\Campaigns\Campaign;
use App\Campaigns\CampaignService;
use App\Campaigns\Event;
use App\Campaigns\Exceptions\ActivationsExhaustedException;
use App\Campaigns\Exceptions\AlreadyUsedException;
use App\Campaigns\Exceptions\CampaignExpiredException;
use App\Campaigns\Exceptions\CampaignNotFoundException;
use App\Campaigns\Exceptions\IneligibleException;
use App\Campaigns\Exceptions\MonthlyLimitException;
use App\Campaigns\Exceptions\WrongCountryException;
use App\Campaigns\Trigger;
use App\Discounts\Discount;
use App\Promo\Exceptions\PromoAlreadyUsedException;
use App\Promo\Exceptions\PromoException;
use App\Promo\Exceptions\PromoExpiredException;
use App\Promo\Exceptions\PromoLimitReachedException;
use App\Promo\Exceptions\PromoMonthlyLimitException;
use App\Promo\Exceptions\PromoNotFoundException;
use App\Promo\Exceptions\PromoWrongCountryException;
use Illuminate\Support\Facades\Log;
use Normalizer;

/**
 * Promo codes entered by users: finds the campaign of the code and passes it
 * to campaigns.
 */
final class PromoService
{
    public function __construct(
        private readonly CampaignService $campaigns,
    ) {}

    /**
     * Format characters (ZWSP, ZWNJ, ZWJ, word joiner, BOM, soft hyphen) and
     * no-break spaces: not rendered or rendered as a blank inside a code.
     */
    private const INVISIBLE = '/[\p{Cf}\x{00A0}\x{2007}\x{202F}]/u';

    /**
     * Turns user input into the form promo codes are stored in. Codes are
     * often pasted from emails, messengers and notes, and the pasted text
     * carries invisible characters (zero-width spaces, soft hyphens,
     * non-breaking spaces) or full-width forms from some keyboards. NFKC folds
     * compatibility forms (full-width letters and digits, ligatures) into
     * plain ones. Invisible characters are dropped first, before NFKC turns
     * no-break spaces into ordinary ones.
     */
    public static function normalizeCode(string $raw): string
    {
        $code = preg_replace(self::INVISIBLE, '', $raw) ?? $raw;
        $code = Normalizer::normalize($code, Normalizer::FORM_KC);
        if ($code === false) {
            $code = $raw;
        }

        return mb_strtoupper(trim($code));
    }

    /**
     * Activates the promo code for the user: the user gets the discount of
     * the code's campaign for the next order.
     *
     * @throws PromoException
     */
    public function apply(ApplyRequest $req): Discount
    {
        try {
            $campaign = $this->campaigns->byPromoCode(self::normalizeCode($req->code));
        } catch (CampaignNotFoundException) {
            Log::info('promo not found', [
                'input' => $req->code,
                'user_id' => $req->userId,
                'channel' => $req->channel->value,
            ]);

            throw new PromoNotFoundException;
        }

        try {
            $discount = $this->campaigns->applyPromoCode($campaign, new Event(
                trigger: Trigger::PromoCode,
                userId: $req->userId,
                country: $req->country,
                cityId: $req->cityId,
                segment: $req->segment,
                channel: $req->channel->value,
            ));
        } catch (CampaignExpiredException) {
            throw new PromoExpiredException;
        } catch (AlreadyUsedException) {
            throw new PromoAlreadyUsedException;
        } catch (ActivationsExhaustedException) {
            throw new PromoLimitReachedException;
        } catch (MonthlyLimitException) {
            throw new PromoMonthlyLimitException;
        } catch (WrongCountryException) {
            throw new PromoWrongCountryException;
        } catch (IneligibleException $e) {
            throw $this->hide($campaign, $req, $e->reason);
        }

        Log::info('promo applied', [
            'campaign_id' => $campaign->id,
            'user_id' => $req->userId,
            'channel' => $req->channel->value,
            'discount_id' => $discount->id,
        ]);

        return $discount;
    }

    /**
     * Makes a promo code the user is not eligible for look non-existent:
     * targeted and not yet launched campaigns must not leak to everyone else.
     */
    private function hide(Campaign $campaign, ApplyRequest $req, string $reason): PromoNotFoundException
    {
        Log::info('promo hidden', [
            'campaign_id' => $campaign->id,
            'user_id' => $req->userId,
            'channel' => $req->channel->value,
            'reason' => $reason,
        ]);

        return new PromoNotFoundException;
    }
}
