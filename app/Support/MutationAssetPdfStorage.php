<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MutationAssetPdfStorage
{
    public function store(UploadedFile $file): string
    {
        $path = $file->store('images', 'public');

        if (! $path || ! Storage::disk('public')->exists($path)) {
            throw new RuntimeException('PDF mutasi asset gagal disimpan.');
        }

        return url(Storage::disk('public')->url($path));
    }
}
