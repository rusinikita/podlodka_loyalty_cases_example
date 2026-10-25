<?php

declare(strict_types=1);

namespace Tests\Unit\Referrals;

use App\Clients\Antifraud\AntifraudClient;
use App\Clients\Antifraud\RewardCheck;
use App\Clients\Antifraud\RewardVerdict;
use App\Clients\Antifraud\Verdict;
use App\Clients\PayoutGateway\PayoutGatewayClient;
use App\Referrals\NewReward;
use App\Referrals\ReferralService;
use App\Referrals\Reward;
use App\Referrals\RewardRepository;
use App\Referrals\RewardStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class ReferralServiceAntifraudTest extends TestCase
{
    private const NOW = '2026-10-20 12:00:00';

    /** @var array<int, Reward> */
    private array $rewards = [];

    /** @var array<int, Verdict> */
    private array $verdicts = [];

    /** @var list<list<int>> */
    private array $calls = [];

    private RewardRepository&MockObject $repo;

    private ReferralService $service;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(self::NOW);
        $this->rewards = $this->verdicts = $this->calls = [];

        $this->repo = $this->createMock(RewardRepository::class);
        $this->repo->method('byStatusAfter')->willReturnCallback(
            function (RewardStatus $status, int $afterId, int $limit): array {
                $found = array_filter(
                    $this->rewards,
                    static fn (Reward $r): bool => $r->status === $status->value && $r->id > $afterId,
                );
                ksort($found);

                return array_values(array_slice($found, 0, $limit));
            },
        );
        $this->repo->method('setStatus')->willReturnCallback(
            function (int $id, RewardStatus $from, RewardStatus $to): void {
                $r = $this->rewards[$id] ?? null;
                if ($r !== null && $r->status === $from->value) {
                    $this->rewards[$id] = self::withStatus($r, $to);
                }
            },
        );

        $antifraud = $this->createMock(AntifraudClient::class);
        $antifraud->method('verdicts')->willReturnCallback(function (array $items): array {
            $this->calls[] = array_map(static fn (RewardCheck $c): int => $c->rewardId, $items);

            return array_map(fn (RewardCheck $c): RewardVerdict => new RewardVerdict(
                rewardId: $c->rewardId,
                verdict: $this->verdicts[$c->rewardId] ?? Verdict::Pending,
            ), $items);
        });

        $this->service = new ReferralService($this->repo, $this->createMock(PayoutGatewayClient::class), $antifraud);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{int, string, Verdict, RewardStatus}>
     */
    public static function verdictCases(): array
    {
        return [
            'approved' => [1, '1 hour', Verdict::Approved, RewardStatus::Pending],
            'rejected' => [2, '1 hour', Verdict::Rejected, RewardStatus::Rejected],
            'pending, fresh' => [3, '6 hours', Verdict::Pending, RewardStatus::WaitingAntifraudResult],
            'pending, almost a day' => [4, '23 hours 59 minutes', Verdict::Pending, RewardStatus::WaitingAntifraudResult],
            'pending, a day' => [5, '24 hours', Verdict::Pending, RewardStatus::Pending],
            'pending, two days' => [6, '48 hours', Verdict::Pending, RewardStatus::Pending],
            'rejected after a day' => [7, '30 hours', Verdict::Rejected, RewardStatus::Rejected],
        ];
    }

    #[DataProvider('verdictCases')]
    public function test_check_antifraud(int $id, string $since, Verdict $verdict, RewardStatus $want): void
    {
        $this->rewards[$id] = self::waiting($id, $since);
        $this->verdicts[$id] = $verdict;

        $this->service->checkAntifraud(500);

        $this->assertSame($want->value, $this->rewards[$id]->status);
    }

    public function test_check_antifraud_batches(): void
    {
        foreach (range(1, 7) as $id) {
            $this->rewards[$id] = self::waiting($id, '1 hour');
        }
        $this->rewards[8] = self::withStatus(self::waiting(8, '1 hour'), RewardStatus::Paid);

        $this->service->checkAntifraud(3);

        $this->assertSame([[1, 2, 3], [4, 5, 6], [7]], $this->calls);
        $this->assertSame(RewardStatus::Paid->value, $this->rewards[8]->status);
    }

    public function test_create_waits_for_antifraud(): void
    {
        $this->repo->expects($this->once())
            ->method('insert')
            ->with($this->isInstanceOf(NewReward::class), RewardStatus::WaitingAntifraudResult)
            ->willReturn(true);

        $this->service->create(new NewReward(campaignId: 17, userId: 1, inviteeId: 2, amount: 500));
    }

    private static function waiting(int $id, string $since): Reward
    {
        $at = CarbonImmutable::now()->sub($since);

        return new Reward(
            id: $id,
            campaignId: 17,
            userId: 1000 + $id,
            inviteeId: 2000 + $id,
            amount: 500,
            status: RewardStatus::WaitingAntifraudResult->value,
            statusChangedAt: $at,
            payoutId: null,
            createdAt: $at,
        );
    }

    private static function withStatus(Reward $r, RewardStatus $status): Reward
    {
        return new Reward(
            id: $r->id,
            campaignId: $r->campaignId,
            userId: $r->userId,
            inviteeId: $r->inviteeId,
            amount: $r->amount,
            status: $status->value,
            statusChangedAt: CarbonImmutable::now(),
            payoutId: $r->payoutId,
            createdAt: $r->createdAt,
        );
    }
}
