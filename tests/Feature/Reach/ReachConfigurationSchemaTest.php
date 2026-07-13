<?php

namespace Tests\Feature\Reach;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReachConfigurationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisions_reach_configurations_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('reach_configurations'));
        $this->assertTrue(Schema::hasColumns('reach_configurations', [
            'tenant_id', 'name', 'method', 'formula_version', 'params', 'status',
            'effective_from', 'notes', 'assumptions', 'created_by', 'activated_at', 'activated_by',
        ]));
    }
}
