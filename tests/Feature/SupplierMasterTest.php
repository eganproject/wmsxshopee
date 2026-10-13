<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierMasterTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    public function test_can_create_update_and_list_supplier(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/master/suppliers')->assertOk();
        $this->get('/master/suppliers/create')->assertOk();

        $this->post('/master/suppliers', [
            'code' => 'sup-01',
            'name' => 'CV Sumber Textile',
            'contact_name' => 'Budi',
            'phone' => '0812345678',
            'email' => 'budi@example.com',
            'city' => 'Bandung',
            'npwp' => '01.234.567.8-901.000',
            'payment_term_days' => 30,
            'is_active' => '1',
        ])->assertRedirect(route('master.suppliers.index'));

        $supplier = Supplier::where('code', 'SUP-01')->firstOrFail();
        $this->assertSame('30 hari', $supplier->paymentTermLabel());

        $this->get('/master/suppliers?search=bandung')->assertOk()->assertSee('CV Sumber Textile');
        $this->get("/master/suppliers/{$supplier->id}/edit")->assertOk();

        $this->put("/master/suppliers/{$supplier->id}", ['code' => 'SUP-01', 'name' => 'CV Sumber Textile', 'payment_term_days' => 0])
            ->assertRedirect(route('master.suppliers.index'));

        $supplier->refresh();
        $this->assertFalse($supplier->is_active);
        $this->assertSame('Tunai', $supplier->paymentTermLabel());

        $this->post('/master/suppliers', ['code' => 'SUP-01', 'name' => 'Duplikat'])->assertSessionHasErrors('code');
    }

    public function test_supplier_with_receipts_cannot_be_deleted(): void
    {
        $this->actingAs($this->superAdmin());

        $unused = Supplier::create(['code' => 'A', 'name' => 'Tanpa Transaksi', 'is_active' => true]);
        $this->delete("/master/suppliers/{$unused->id}")->assertRedirect(route('master.suppliers.index'));
        $this->assertModelMissing($unused);

        $used = Supplier::create(['code' => 'B', 'name' => 'Ada Transaksi', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'JKT01', 'name' => 'Gudang Jakarta', 'is_active' => true]);
        GoodsReceipt::create(['number' => 'GR2610-001', 'status' => 'draft', 'supplier_id' => $used->id, 'warehouse_id' => $warehouse->id, 'receipt_date' => now()]);

        $this->delete("/master/suppliers/{$used->id}")->assertSessionHas('error');
        $this->assertModelExists($used);
    }

    public function test_staff_cannot_manage_suppliers(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('Staff Gudang');

        $this->actingAs($staff);
        $this->get('/master/suppliers')->assertOk();
        $this->get('/master/suppliers/create')->assertForbidden();
    }
}
