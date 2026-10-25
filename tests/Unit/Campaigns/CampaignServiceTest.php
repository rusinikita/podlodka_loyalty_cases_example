<?php

declare(strict_types=1);

namespace Tests\Unit\Campaigns;

use App\Campaigns\Campaign;
use App\Campaigns\CampaignRepository;
use App\Campaigns\CampaignService;
use App\Campaigns\Conditions;
use App\Campaigns\Event;
use App\Campaigns\RewardParams;
use App\Campaigns\RewardType;
use App\Campaigns\Trigger;
use App\Clients\OrderHistory\OrderHistoryClient;
use App\Clients\OrderHistory\OrderHistoryException;
use App\Discounts\Discount;
use App\Discounts\DiscountService;
use App\Discounts\GrantRequest;
use App\Referrals\ReferralService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class CampaignServiceTest extends TestCase
{
    private CampaignRepository&MockObject $campaigns;

    private DiscountService&MockObject $discounts;

    private OrderHistoryClient&MockObject $orders;

    /** @var list<GrantRequest> */
    private array $granted = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 12:00:00');

        $this->campaigns = $this->createMock(CampaignRepository::class);
        $this->discounts = $this->createMock(DiscountService::class);
        $this->orders = $this->createMock(OrderHistoryClient::class);

        $this->granted = [];
        $this->discounts->method('grant')->willReturnCallback(function (GrantRequest $req): Discount {
            $this->granted[] = $req;

            return $this->discount($req);
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function firstOrderCases(): array
    {
        return [
            'first order' => [1, 1],
            'second order' => [2, 0],
            'regular customer' => [37, 0],
            'no orders in history' => [0, 0],
        ];
    }

    #[DataProvider('firstOrderCases')]
    public function test_handle_first_order(int $ordersInHistory, int $wantGranted): void
    {
        $this->active($this->paymentCampaign(1, 'first_order', new Conditions(countries: ['RU'], firstOrder: true)));
        $this->orders->method('countOrders')->with(42)->willReturn($ordersInHistory);

        $this->service()->handle($this->payment(42));

        $this->assertCount($wantGranted, $this->granted);
        if ($wantGranted === 1) {
            $g = $this->granted[0];
            $this->assertSame(1, $g->campaignId);
            $this->assertSame(20, $g->percent);
            $this->assertEquals(CarbonImmutable::now()->addDays(14), $g->expiresAt);
            $this->assertTrue($g->notify);
        }
    }

    public function test_handle_first_order_other_country(): void
    {
        $this->active($this->paymentCampaign(1, 'first_order', new Conditions(countries: ['RU'], firstOrder: true)));
        $this->orders->expects($this->never())->method('countOrders');

        $this->service()->handle($this->payment(42, country: 'KZ'));

        $this->assertSame([], $this->granted);
    }

    public function test_handle_without_first_order_condition(): void
    {
        $this->active($this->paymentCampaign(2, 'basket', new Conditions(
            countries: ['RU'],
            skuIds: [100, 101],
            paymentMethods: ['card'],
        )));
        $this->orders->expects($this->never())->method('countOrders');

        $this->service()->handle($this->payment(42));

        $this->assertCount(1, $this->granted);
    }

    public function test_handle_order_history_error(): void
    {
        $this->active($this->paymentCampaign(1, 'first_order', new Conditions(firstOrder: true)));
        $this->orders->method('countOrders')->willThrowException(new OrderHistoryException(503, 'unavailable', ''));

        try {
            $this->service()->handle($this->payment(42));
            $this->fail('handle() must fail to retry the message');
        } catch (OrderHistoryException) {
        }
        $this->assertSame([], $this->granted);
    }

    private function service(): CampaignService
    {
        return new CampaignService(
            $this->campaigns,
            $this->discounts,
            $this->createMock(ReferralService::class),
            $this->orders,
            maxPromoPerMonth: 2,
        );
    }

    private function active(Campaign ...$campaigns): void
    {
        $this->campaigns->method('activeByTrigger')->willReturnCallback(
            static fn (Trigger $trigger): array => array_values(array_filter(
                $campaigns,
                static fn (Campaign $c): bool => $c->trigger === $trigger,
            )),
        );
    }

    private function paymentCampaign(int $id, string $slug, Conditions $conditions): Campaign
    {
        $yesterday = CarbonImmutable::now()->subDay();

        return new Campaign(
            id: $id,
            slug: $slug,
            trigger: Trigger::PaymentSucceeded,
            promoCode: null,
            conditions: $conditions,
            rewardType: RewardType::Discount,
            rewardParams: RewardParams::fromArray(['percent' => 20, 'ttl_days' => 14]),
            startsAt: $yesterday,
            endsAt: null,
            isActive: true,
            createdBy: null,
            createdAt: $yesterday,
        );
    }

    private function payment(int $userId, string $country = 'RU'): Event
    {
        return new Event(
            trigger: Trigger::PaymentSucceeded,
            userId: $userId,
            country: $country,
            paymentId: 'pay-1',
            paymentMethod: 'card',
            skuIds: [100],
        );
    }

    private function discount(GrantRequest $req): Discount
    {
        return new Discount(
            id: count($this->granted),
            userId: $req->userId,
            campaignId: $req->campaignId,
            channel: $req->channel,
            paymentId: $req->paymentId,
            percent: $req->percent,
            amount: null,
            maxAmount: null,
            expiresAt: $req->expiresAt,
            usedAt: null,
            usedOrderId: null,
            createdBy: null,
            comment: null,
            createdAt: CarbonImmutable::now(),
        );
    }
}
