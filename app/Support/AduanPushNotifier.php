<?php

namespace App\Support;

use App\Models\Aduan;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single place that turns "a new Aduan exists" into exactly ONE FCM push,
 * regardless of who noticed it first:
 *   - the queued SendAduanPushNotification listener (mobile-created rows), or
 *   - the `aduan:watch` daemon (web-created rows, and a safety net for mobile).
 *
 * Deduplication is an atomic claim on `push_sent_at` (NULL -> now()). Whoever
 * flips it wins and sends; everyone else is a no-op. This also makes it safe to
 * run more than one watcher worker.
 */
class AduanPushNotifier
{
    public function __construct(private PushNotificationService $push)
    {
    }

    /**
     * Atomically claim the create-push for $aduan and send it.
     *
     * @return bool true if THIS caller sent the push, false if it was already
     *              claimed/sent elsewhere (so the caller should do nothing).
     */
    public function created(Aduan $aduan): bool
    {
        // Atomic claim — a single UPDATE that only the first caller can win.
        $claimed = DB::table('aduans')
            ->where('id', $aduan->id)
            ->whereNull('push_sent_at')
            ->update(['push_sent_at' => now()]);

        if ($claimed === 0) {
            return false; // already notified by another worker / the listener
        }

        try {
            // A brand-new complaint is an ICT alert (this replaced the web's
            // site-wide 2s SweetAlert poll). Guests must NEVER receive it — not for
            // their own creation, and not for anybody else's. See broadcastRecipients().
            $recipients = self::broadcastRecipients($aduan);
            if ($recipients->isNotEmpty()) {
                $this->push->sendToUsers(
                    $recipients,
                    'Aduan Baru!',
                    self::summary($aduan),
                    [
                        // Tapping the notification opens this aduan (mobile reads `aduan_id`).
                        'type'           => 'aduan',
                        'aduan_id'       => (string) $aduan->id,
                        'complaint_code' => (string) ($aduan->complaint_code ?? ''),
                        'site'           => (string) ($aduan->site ?? ''),
                        'status'         => (string) ($aduan->status ?? ''),
                    ]
                );
            }

            return true;
        } catch (\Throwable $e) {
            // Release the claim so the next tick / queue retry can resend it
            // (FCM send is otherwise fail-safe, so this path is rare).
            DB::table('aduans')->where('id', $aduan->id)->update(['push_sent_at' => null]);
            throw $e;
        }
    }

    /**
     * Recipients for a plain BROADCAST (a new complaint arrived): every ICT/staff
     * user of the aduan's site, but NEVER a guest.
     *
     * The Guest role must not follow the ICT notification flow: a guest may only
     * ever hear about their OWN complaint, and even then only for status events
     * (assign / progress / closed), not for "a new complaint exists". So guests
     * are excluded from this audience entirely. Users with a NULL role are kept
     * (only an explicit 'guest' is dropped) so existing ICT delivery is unchanged.
     *
     * @return Collection<int,User>
     */
    public static function broadcastRecipients(Aduan $aduan): Collection
    {
        return User::where('site', $aduan->site)
            ->where(function ($q) {
                $q->whereNull('role')->orWhere('role', '!=', 'guest');
            })
            ->get();
    }

    /**
     * Recipients for an event tied to a SPECIFIC complaint (assignment / progress
     * / closed): all non-guest staff of the site PLUS the complaint's owner
     * (matched by nrp) even when that owner is a guest.
     *
     * This is the ONLY path by which a guest is ever pushed, and strictly for
     * their own ticket — ownership (creator nrp), never role/site/broadcast.
     *
     * @return Collection<int,User>
     */
    public static function ownerEventRecipients(Aduan $aduan): Collection
    {
        $staff = self::broadcastRecipients($aduan);

        $owner = ! empty($aduan->nrp)
            ? User::where('nrp', $aduan->nrp)->get()
            : collect();

        return $staff->concat($owner)->unique('id')->values();
    }

    public static function summary(Aduan $aduan): string
    {
        $note = trim((string) ($aduan->complaint_note ?? ''));
        $code = trim((string) ($aduan->complaint_code ?? ''));
        $text = $note !== '' ? $note : 'Lihat detail aduan.';

        return $code !== '' ? "[$code] $text" : $text;
    }
}
