<?php

use App\Http\Controllers\PdfUploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/upload-pdf', function () {
    return view('pdf-upload');
});


Route::post('/upload-pdf', [PdfUploadController::class, 'upload'])
    ->name('upload.pdf');


