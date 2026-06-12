<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Traits\SeedsPermissions;

abstract class TestCase extends BaseTestCase
{
    use SeedsPermissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAllPermissions();
    }

    protected function enableDecimalQuantities(\App\Models\Business $business): void
    {
        $settings = is_array($business->settings) ? $business->settings : [];
        $settings['allow_decimal_quantities'] = true;
        $business->update(['settings' => $settings]);
        $business->refresh();
    }
}
