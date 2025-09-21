<?php

use App\Http\Controllers\Admin\ClinicController;
use App\Http\Middleware\JwtMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware([JwtMiddleware::class])->group(function () {
    Route::controller(ClinicController::class)->group(function () {
        Route::get('/showClinic','show');
        Route::get('/showClinicDetails','showDetails');
        Route::get('/showDoctorsClinic','showDoctorsClinic');
        Route::post('/addClinic','addClinic');
        Route::post('/editClinic','editClinic');
        Route::delete('/removeClinic','removeClinic');
    });
});