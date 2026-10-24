<?php

declare(strict_types=1);

namespace App\Providers;

use App\Campaigns\CampaignService;
use App\Clients\Notifications\NotificationsClient;
use App\Clients\PayoutGateway\PayoutGatewayClient;
use App\Discounts\Compensation;
use App\Support\Duration;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PayoutGatewayClient::class, fn ($app) => new PayoutGatewayClient(
            $app->make(Factory::class),
            config('loyalty.clients.payout_gateway.base_url'),
            config('services.service_token'),
            Duration::seconds(config('loyalty.clients.payout_gateway.timeout')),
        ));

        $this->app->singleton(NotificationsClient::class, fn ($app) => new NotificationsClient(
            $app->make(Factory::class),
            config('loyalty.clients.notifications.base_url'),
            config('services.service_token'),
            Duration::seconds(config('loyalty.clients.notifications.timeout')),
        ));

        $this->app->singleton(Compensation::class, fn () => new Compensation(
            maxPercent: config('loyalty.discounts.compensation.max_percent'),
            ttlDays: config('loyalty.discounts.compensation.ttl_days'),
            role: config('loyalty.discounts.compensation.role'),
        ));

        $this->app->when(CampaignService::class)
            ->needs('$maxPromoPerMonth')
            ->giveConfig('loyalty.promo.max_per_month');
    }
}
