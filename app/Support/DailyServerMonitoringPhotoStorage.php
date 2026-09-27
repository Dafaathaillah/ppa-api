<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DailyServerMonitoringPhotoStorage
{
    private array $storedPaths = [];

    public function store(UploadedFile $photo): string
    {
        $path = $photo->store('images/daily-server-monitorings', 'public');
        $this->storedPaths[] = $path;

        return url(Storage::disk('public')->url($path));
    }

    public function rollback(): void
    {
        Storage::disk('public')->delete($this->storedPaths);
        $this->storedPaths = [];
    }

    public function deleteUrl(?string $url): void
    {
        if (! $url) {
            return;
        }

        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (str_starts_with($path, 'storage/')) {
            Storage::disk('public')->delete(substr($path, 8));
        } elseif (str_starts_with($path, 'images/') && ! str_contains($path, '..')) {
            File::delete(public_path($path));
        }
    }
}
