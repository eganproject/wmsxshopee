<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UiFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::where('username', 'superadmin')->first());
    }

    public function test_empty_list_shows_guidance_and_create_action(): void
    {
        $this->get('/master/suppliers')
            ->assertOk()
            ->assertSee('Belum ada supplier')
            ->assertSee('Tambah Supplier')
            ->assertDontSee('Tidak ada hasil yang cocok');
    }

    public function test_filtered_empty_list_offers_to_clear_filters(): void
    {
        $this->get('/master/suppliers?search=tidak-ada')
            ->assertOk()
            ->assertSee('Tidak ada hasil yang cocok')
            ->assertSee('Hapus filter')
            ->assertDontSee('Belum ada supplier');
    }

    public function test_parent_context_is_not_treated_as_a_filter(): void
    {
        $this->get('/master/zones?warehouse=999')
            ->assertOk()
            ->assertSee('Belum ada zona')
            ->assertDontSee('Tidak ada hasil yang cocok');
    }

    public function test_validation_errors_are_summarised_at_the_top(): void
    {
        $this->from('/master/suppliers/create')
            ->followingRedirects()
            ->post('/master/suppliers', [])
            ->assertOk()
            ->assertSee('data-error-summary', false)
            ->assertSee('hal yang perlu diperbaiki');
    }

    public function test_forms_are_guarded_and_layout_has_progress_bar(): void
    {
        $this->get('/master/suppliers/create')
            ->assertOk()
            ->assertSee('data-dirty-guard', false)
            ->assertSee('id="page-progress"', false);
    }

    public function test_server_error_page_is_friendly(): void
    {
        config(['app.debug' => false]);
        Route::get('/_boom', fn () => throw new \RuntimeException('boom'))->middleware('web');

        $this->get('/_boom')
            ->assertStatus(500)
            ->assertSee('Terjadi Kesalahan')
            ->assertDontSee('RuntimeException');
    }
}
