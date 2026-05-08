<?php

namespace App\Providers;

use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Observers\ProductBatchObserver;
use App\Observers\SaleAnalyticsObserver;
use App\Observers\SaleItemAnalyticsObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ProductBatch::observe(ProductBatchObserver::class);
        Sale::observe(SaleAnalyticsObserver::class);
        SaleItem::observe(SaleItemAnalyticsObserver::class);
    }
}
