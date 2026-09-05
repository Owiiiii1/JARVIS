<?php

namespace Tests\Unit\Voice;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class VoiceProviderTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::shouldReceive('hasTable')->andReturn(false);
        Schema::shouldReceive('hasColumn')->andReturn(false);
        Http::preventStrayRequests();
    }
}
