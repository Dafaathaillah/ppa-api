<?php

namespace Tests\Feature\Api;

use App\Jobs\SendAduanWebhook;
use App\Models\Aduan;
use App\Observers\AduanObserver;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AduanWebhookParityTest extends TestCase
{
    public function test_created_observer_queues_the_maintenance_webhook_job(): void
    {
        Queue::fake();
        $aduan = new Aduan;
        $aduan->id = 'test-aduan';
        $aduan->site = 'BIB';
        $aduan->date_of_complaint = '2026-10-06 02:30:00';

        (new AduanObserver)->created($aduan);

        Queue::assertPushed(SendAduanWebhook::class);
    }
}
