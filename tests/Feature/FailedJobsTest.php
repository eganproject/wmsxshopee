<?php

namespace Tests\Feature;

use App\Jobs\PushMarketplaceStock;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FailedJobsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function failJob(string $message = 'Shopee API error: access token expired'): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => PushMarketplaceStock::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 1,
                'attempts' => 1,
                'data' => ['commandName' => PushMarketplaceStock::class, 'command' => serialize(new PushMarketplaceStock)],
            ]),
            'exception' => "App\\Services\\Shopee\\ShopeeException: {$message}\n#0 /var/www/app/Services/Shopee/ShopeeClient.php(120)",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_failed_jobs_are_listed_and_raise_an_alert(): void
    {
        $this->failJob();
        $admin = User::where('username', 'superadmin')->first();
        $this->actingAs($admin);

        $this->get(route('marketplace.sync-logs', ['tab' => 'jobs']))->assertOk()
            ->assertSee('Kirim stok ke marketplace')
            ->assertSee('access token expired')
            ->assertDontSee('ShopeeException: access');

        $issues = app(AlertService::class)->marketplaceIssues();
        $this->assertTrue($issues->contains(fn ($issue) => str_contains($issue->problem, '1 job gagal')));
    }

    public function test_failed_job_can_be_retried_or_removed(): void
    {
        $first = $this->failJob();
        $second = $this->failJob('lain');
        $this->actingAs(User::where('username', 'superadmin')->first());

        $this->post(route('marketplace.sync-logs.retry-job', $first))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());

        $this->delete(route('marketplace.sync-logs.forget-job', $second))->assertRedirect();
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->get(route('marketplace.sync-logs', ['tab' => 'jobs']))->assertOk()->assertSee('Tidak ada job gagal');
    }

    public function test_staff_without_sync_permission_cannot_retry(): void
    {
        $uuid = $this->failJob();
        $user = User::factory()->create();
        $user->givePermissionTo('shopee.view');
        $this->actingAs($user);

        $this->post(route('marketplace.sync-logs.retry-job', $uuid))->assertForbidden();
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }
}
