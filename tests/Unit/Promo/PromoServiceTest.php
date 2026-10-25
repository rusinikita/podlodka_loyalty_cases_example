<?php

declare(strict_types=1);

namespace Tests\Unit\Promo;

use App\Campaigns\Campaign;
use App\Campaigns\CampaignService;
use App\Campaigns\Conditions;
use App\Campaigns\Exceptions\CampaignNotFoundException;
use App\Campaigns\RewardParams;
use App\Campaigns\RewardType;
use App\Campaigns\Trigger;
use App\Discounts\Discount;
use App\Promo\ApplyRequest;
use App\Promo\Channel;
use App\Promo\Exceptions\PromoNotFoundException;
use App\Promo\PromoService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PromoServiceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function codes(): array
    {
        return [
            'as stored' => ['WELCOME300', 'WELCOME300'],
            'lower case' => ['welcome300', 'WELCOME300'],
            'mixed case' => ['WeLcOmE300', 'WELCOME300'],
            'surrounding spaces' => ["  WELCOME300 \n", 'WELCOME300'],
            'tab' => ["\tWELCOME300", 'WELCOME300'],
            'zero-width space inside' => ["WELC\u{200B}OME300", 'WELCOME300'],
            'zero-width joiner and non-joiner' => ["\u{200D}WELCOME\u{200C}300", 'WELCOME300'],
            'word joiner' => ["WELCOME\u{2060}300", 'WELCOME300'],
            'byte order mark' => ["\u{FEFF}WELCOME300", 'WELCOME300'],
            'soft hyphen' => ["WEL\u{00AD}COME300", 'WELCOME300'],
            'no-break space inside' => ["WELCOME\u{00A0}300", 'WELCOME300'],
            'narrow no-break space at the end' => ["WELCOME300\u{202F}", 'WELCOME300'],
            'full-width letters and digits' => ['ＷＥＬＣＯＭＥ３００', 'WELCOME300'],
            'full-width digits only' => ['AUTUMN１５', 'AUTUMN15'],
            'full-width lower case' => ['ａｕｔｕｍｎ15', 'AUTUMN15'],
            'code with a dash' => ['pulse-93kd', 'PULSE-93KD'],
            'pasted from a message' => [" \u{200B}autumn１５\u{00A0}", 'AUTUMN15'],
            'empty' => ['', ''],
            'only invisible characters' => ["\u{200B}\u{00AD}\u{FEFF}", ''],
        ];
    }

    #[DataProvider('codes')]
    public function test_normalize_code(string $raw, string $want): void
    {
        $this->assertSame($want, PromoService::normalizeCode($raw));
    }

    public function test_apply_normalized_input(): void
    {
        $campaign = $this->promoCampaign('WELCOME300');
        $campaigns = $this->createMock(CampaignService::class);
        $campaigns->method('byPromoCode')->willReturnCallback(
            static fn (string $code): Campaign => $code === 'WELCOME300' ? $campaign : throw new CampaignNotFoundException,
        );
        $inputs = ['WELCOME300', 'welcome300', "WELC\u{200B}OME300", 'ＷＥＬＣＯＭＥ３００', "WELCOME\u{00A0}300 "];
        $campaigns->expects($this->exactly(count($inputs)))
            ->method('applyPromoCode')
            ->willReturn($this->discount());
        $promo = new PromoService($campaigns);

        foreach ($inputs as $input) {
            $promo->apply($this->request($input));
        }

        $this->expectException(PromoNotFoundException::class);
        $promo->apply($this->request('WELCOME30'));
    }

    private function request(string $code): ApplyRequest
    {
        return new ApplyRequest(userId: 7, country: 'RU', cityId: 1, segment: '', code: $code, channel: Channel::Manual);
    }

    private function promoCampaign(string $code): Campaign
    {
        $yesterday = CarbonImmutable::now()->subDay();

        return new Campaign(
            id: 1,
            slug: 'welcome_300',
            trigger: Trigger::PromoCode,
            promoCode: $code,
            conditions: Conditions::fromArray([]),
            rewardType: RewardType::Discount,
            rewardParams: RewardParams::fromArray(['amount' => 300]),
            startsAt: $yesterday,
            endsAt: null,
            isActive: true,
            createdBy: null,
            createdAt: $yesterday,
        );
    }

    private function discount(): Discount
    {
        $now = CarbonImmutable::now();

        return new Discount(
            id: 1,
            userId: 7,
            campaignId: 1,
            channel: Channel::Manual->value,
            paymentId: null,
            percent: null,
            amount: 300,
            maxAmount: null,
            expiresAt: $now->addDays(14),
            usedAt: null,
            usedOrderId: null,
            createdBy: null,
            comment: null,
            createdAt: $now,
        );
    }
}
