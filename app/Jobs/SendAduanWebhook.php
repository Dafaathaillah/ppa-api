<?php

namespace App\Jobs;

use App\Models\Aduan;
use App\Models\UserAll;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SendAduanWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public readonly string|int $aduanId;
    public readonly string $site;
    public readonly array $payload;

    public function __construct(Aduan $aduan)
    {
        $this->aduanId = $aduan->getKey();
        $this->site = strtoupper((string) $aduan->site);
        $department = filled($aduan->nrp) ? UserAll::where('nrp', $aduan->nrp)->value('department') : null;
        $timezone = config("aduan.timezones.sites.{$this->site}", config('aduan.timezones.default', 'Asia/Makassar'));
        $complaintTime = Carbon::parse($aduan->date_of_complaint, 'UTC')->setTimezone($timezone);
        $this->payload = [
            'event' => 'aduan.created', 'no_aduan' => $aduan->complaint_code,
            'nama' => $aduan->complaint_name, 'dept' => $department, 'kontak' => $aduan->phone_number,
            'waktu_aduan' => $complaintTime->toIso8601String(), 'aduan' => $aduan->complaint_note,
            'status_aduan' => $aduan->status, 'site' => $this->site,
        ];
        $this->onQueue('webhooks');
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $url = config("aduan.webhooks.sites.{$this->site}");
        $secret = (string) config('aduan.webhooks.secret');
        if (blank($url)) {
            throw new RuntimeException("URL webhook aduan untuk site {$this->site} belum dikonfigurasi.");
        }
        if ($secret === '') {
            throw new RuntimeException('ADUAN_WEBHOOK_SECRET belum dikonfigurasi.');
        }
        $json = json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Http::connectTimeout(5)->timeout(10)->withHeaders([
            'Content-Type' => 'application/json', 'X-Aduan-Event' => 'aduan.created',
            'X-Aduan-Signature' => 'sha256='.hash_hmac('sha256', $json, $secret),
            'Idempotency-Key' => "aduan.created:{$this->aduanId}",
        ])->withBody($json, 'application/json')->post($url)->throw();
    }
}
