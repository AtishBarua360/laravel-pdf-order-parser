<?php

namespace App\Http\Controllers;

use App\Http\Request\UploadPdfRequest;
use App\Services\PdfUploadService;

class PdfUploadController extends Controller
{
    protected PdfUploadService $pdfUploadService;

    public function __construct(PdfUploadService $pdfUploadService)
    {
        $this->pdfUploadService = $pdfUploadService;
    }

    public function upload(UploadPdfRequest $request)
    {
        $pdfPath = $this->pdfUploadService->storePdf($request->file('pdf_file'));

        return response()->json([
            'message' => 'PDF uploaded successfully',
            'path' => $pdfPath,
            'success' => true
        ]);
    }
}
