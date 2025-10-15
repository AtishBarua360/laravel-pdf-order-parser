<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PdfUploadService
{
    public function storePdf(UploadedFile $file): string
    {
        return $file->store('uploads', 'public');
    }
}
