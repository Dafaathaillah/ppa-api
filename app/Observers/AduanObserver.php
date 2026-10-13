<?php

namespace App\Observers;

use App\Events\AduanAssigned;
use App\Events\AduanCreated;
use App\Events\AduanUpdated;
use App\Models\Aduan;
use App\Jobs\SendAduanWebhook;
use Illuminate\Support\Facades\Log;

/**
 * Event-driven trigger for Aduan push notifications. Hooking the Eloquent model
 * covers BOTH the web Inertia controllers and the mobile AduanApiController in a
 * single place, WITHOUT touching any Aduan business logic (it only dispatches
 * events after the write has already happened).
 *
 * This is what replaces the web's per-2-second polling for mobile clients.
 */
class AduanObserver
{
    public function created(Aduan $aduan): void
    {
        // Lightweight diagnostic so production can confirm the observer fires on
        // a web-created aduan (low volume — only on aduan create). Safe to drop
        // once the push pipeline is verified end-to-end in production.
        Log::info('[ADUAN-PUSH] observer.created fired', [
            'id' => $aduan->id, 'complaint_code' => $aduan->complaint_code, 'site' => $aduan->site,
        ]);
        AduanCreated::dispatch($aduan);
        SendAduanWebhook::dispatch($aduan)->afterCommit();
    }

    public function updated(Aduan $aduan): void
    {
        Log::info('[ADUAN-PUSH] observer.updated fired', [
            'id' => $aduan->id, 'crew_changed' => $aduan->wasChanged('crew'),
        ]);
        // A change to the crew column is an "assignment"; anything else is a
        // generic update (status / progress / note).
        if ($aduan->wasChanged('crew')) {
            AduanAssigned::dispatch($aduan);
        } else {
            AduanUpdated::dispatch($aduan);
        }
    }
}
