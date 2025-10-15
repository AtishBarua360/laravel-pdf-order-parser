<?php

namespace App\Contracts\Services;

use Illuminate\Http\UploadedFile;

interface PdfUploadContract
{

    public function storePdf(UploadedFile $file): string;
}