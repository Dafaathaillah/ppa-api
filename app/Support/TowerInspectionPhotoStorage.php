<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class TowerInspectionPhotoStorage
{
    /** @var list<string> */
    private array $storedPaths = [];

    public function store(UploadedFile $photo): string
    {
        $path = $photo->store('images', 'public');
        $this->storedPaths[] = $path;

        return url(Storage::disk('public')->url($path));
    }

    public function rollback(): void
    {
        if ($this->storedPaths !== []) {
            Storage::disk('public')->delete($this->storedPaths);
            $this->storedPaths = [];
        }
    }
}
