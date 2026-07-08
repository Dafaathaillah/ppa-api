<?php

namespace App\Listeners;

use App\Events\AduanAssigned;
use App\Events\AduanCreated;
use App\Events\AduanUpdated;
use App\Services\PushNotificationService;
use App\Support\AduanPushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Turns Aduan domain events into FCM pushes. Recipients mirror the audience that
 * the web toast targeted: every user of the aduan's site (that is exactly who
 * the 2-second web poller showed the SweetAlert to).
 *
 * Implements ShouldQueue so production can offload the HTTP call to a worker;
 * with Laravel's default QUEUE_CONNECTION=sync it simply runs inline.
 */
class SendAduanPushNotification implements ShouldQueue
{
    public function __construct(
        private PushNotificationService $push,
        private AduanPushNotifier $notifier,
    ) {
    }

    public function handle(AduanCreated|AduanAssigned|AduanUpdated $event): void
    {
        $aduan = $event->aduan;

        // CREATE is deduplicated with the `aduan:watch` daemon via the shared
        // push_sent_at claim, so a mobile-created aduan is never pushed twice
        // (once here, once by the watcher). The watcher handles web-created rows.
        if ($event instanceof AduanCreated) {
            $this->notifier->created($aduan);
            return;
        }

        // Assignment / status update — these only originate from THIS API project
        // (mobile), so no checkpoint is needed; deliver immediately.
        $title = $event instanceof AduanAssigned ? 'Aduan Ditugaskan' : 'Update Aduan';

        // Non-guest staff of the site PLUS the complaint owner (even a guest). A
        // guest therefore receives the assign / progress / closed notification for
        // THEIR OWN ticket only, never for anyone else's — ownership, not role.
        $recipients = AduanPushNotifier::ownerEventRecipients($aduan);
        if ($recipients->isEmpty()) {
            return;
        }

        $this->push->sendToUsers($recipients, $title, AduanPushNotifier::summary($aduan), [
            // Tapping the notification opens this aduan (mobile reads `aduan_id`).
            'type'           => 'aduan',
            'aduan_id'       => $aduan->id,
            'complaint_code' => (string) ($aduan->complaint_code ?? ''),
            'site'           => (string) ($aduan->site ?? ''),
            'status'         => (string) ($aduan->status ?? ''),
        ]);
    }
}
