<?php

namespace App\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

class UploadPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // allow all users; change if needed
    }

    public function rules(): array
    {
        return [
            'pdf_file' => ['required', 'mimes:pdf', 'max:5120'], // 5MB
        ];
    }
}
