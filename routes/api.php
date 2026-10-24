<?php

use App\Admin\AdminController;
use App\Admin\IdentifyOperator;
use App\Admin\RequireRole;
use App\Admin\Role;
use App\Http\Controllers\AppController;
use App\Http\Controllers\OrdersController;
use App\Http\Middleware\RequireServiceToken;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', fn () => response('', 200));

// App endpoints, called through the API gateway.
Route::prefix('v1')->group(function () {
    Route::post('promo/apply', [AppController::class, 'applyPromo']);
    Route::get('discounts/active', [AppController::class, 'activeDiscounts']);
    Route::get('referrals/rewards', [AppController::class, 'referralRewards']);

    Route::middleware(RequireServiceToken::class)->group(function () {
        Route::post('orders/discount-quote', [OrdersController::class, 'quote']);
        Route::post('orders/{orderId}/redeem-discount', [OrdersController::class, 'redeem']);
    });
});

// Admin panel endpoints.
Route::prefix('admin/v1')->middleware(IdentifyOperator::class)->group(function () {
    Route::get('campaigns', [AdminController::class, 'searchCampaigns']);
    Route::post('campaigns', [AdminController::class, 'createCampaign'])
        ->middleware(RequireRole::atLeast(Role::LoyaltyAdmin));
    Route::get('campaigns/{id}', [AdminController::class, 'getCampaign'])
        ->middleware(RequireRole::atLeast(Role::SupportL2));
    Route::patch('campaigns/{id}', [AdminController::class, 'updateCampaign'])
        ->middleware(RequireRole::atLeast(Role::LoyaltyAdmin));

    Route::get('users/{userId}/discounts', [AdminController::class, 'userDiscounts']);
    Route::post('users/{userId}/discounts', [AdminController::class, 'grantCompensation'])
        ->middleware(RequireRole::atLeast(config('loyalty.discounts.compensation.role')));
    Route::get('users/{userId}/referral-rewards', [AdminController::class, 'userReferralRewards']);
});
