<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierMasterTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    public function test_default_couriers_are_seeded_and_pages_render(): void
    {
        $jne = Courier::where('code', 'JNE')->first();
        $this->assertNotNull($jne);
        $this->assertContains('REG', array_column($jne->services, 'code'));

        $this->actingAs($this->superAdmin());
        $this->get('/master/couriers')->assertOk()->assertSee('JNE Express');
        $this->get('/master/couriers/create')->assertOk();
        $this->get("/master/couriers/{$jne->id}/edit")->assertOk();
    }

    public function test_can_create_courier_with_services(): void
    {
        $this->actingAs($this->superAdmin())->post('/master/couriers', [
            'code' => 'lion',
            'name' => 'Lion Parcel',
            'tracking_url' => 'https://example.com/track?resi={resi}',
            'pickup_cutoff' => '15:00',
            'is_active' => '1',
            'services' => [
                ['code' => 'regpack', 'name' => 'Reguler'],
                ['code' => '', 'name' => ''],
                ['code' => 'jagopack', 'name' => 'Ekonomi'],
            ],
        ])->assertRedirect(route('master.couriers.index'));

        $lion = Courier::where('code', 'LION')->first();
        $this->assertSame([
            ['code' => 'REGPACK', 'name' => 'Reguler'],
            ['code' => 'JAGOPACK', 'name' => 'Ekonomi'],
        ], $lion->services);
        $this->assertSame('https://example.com/track?resi=AB%2F123', $lion->trackingUrlFor('AB/123'));
    }

    public function test_courier_validation(): void
    {
        $this->actingAs($this->superAdmin())->post('/master/couriers', [
            'code' => 'JNE',
            'name' => 'Duplikat',
            'tracking_url' => 'bukan-url',
            'pickup_cutoff' => '25:99',
            'services' => [['code' => 'REG', 'name' => 'A'], ['code' => 'reg', 'name' => 'B']],
        ])->assertSessionHasErrors(['code', 'tracking_url', 'pickup_cutoff', 'services.1.code']);
    }

    public function test_courier_permissions_are_enforced(): void
    {
        $courier = Courier::first();
        $staff = User::factory()->create()->assignRole('Staff Gudang');

        $this->actingAs($staff)->get('/master/couriers')->assertOk();
        $this->get('/master/couriers/create')->assertForbidden();
        $this->put("/master/couriers/{$courier->id}", ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->delete("/master/couriers/{$courier->id}")->assertForbidden();

        $this->actingAs($this->superAdmin())->delete("/master/couriers/{$courier->id}")->assertRedirect(route('master.couriers.index'));
        $this->assertModelMissing($courier);
    }
}
