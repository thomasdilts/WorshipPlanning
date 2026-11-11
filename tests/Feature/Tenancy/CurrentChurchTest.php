<?php

namespace Tests\Feature\Tenancy;

use App\Core\Tenancy\CurrentChurch;
use App\Models\Church;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CurrentChurchTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_current_church_sets_spatie_team_id(): void
    {
        $church = Church::factory()->create();
        $current = app(CurrentChurch::class);

        $current->set($church);

        $this->assertTrue($current->check());
        $this->assertSame($church->id, $current->id());
        $this->assertSame(
            $church->id,
            app(PermissionRegistrar::class)->getPermissionsTeamId()
        );
    }

    public function test_an_email_can_belong_to_only_one_account_across_churches(): void
    {
        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();
        User::factory()->create(['church_id' => $churchA->id, 'email' => 'a@test.com']);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['church_id' => $churchB->id, 'email' => 'a@test.com']);
    }
}
