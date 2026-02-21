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
}
