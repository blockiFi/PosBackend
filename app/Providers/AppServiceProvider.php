<?php

namespace App\Providers;

use App\Models\ProductBatch;
use App\Observers\ProductBatchObserver;
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
    }
}
