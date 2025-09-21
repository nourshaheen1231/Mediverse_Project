<?php

use App\Http\Controllers\Secretary\AppointmentController;
use App\Http\Middleware\JwtMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware([JwtMiddleware::class])->group(function () {
    Route::controller(AppointmentController::class)->group(function () {
        Route::post('/filteringAppointmentByDoctor', 'filteringAppointmentByDoctor');
        Route::post('/filteringAppointmentByDoctorStatus', 'filteringAppointmentByDoctorStatus');
        Route::post('/filteringAppointmentByStatus', 'filteringAppointmentByStatus');
        Route::post('/filteringAppointmentByDate', 'filteringAppointmentByDate');
        Route::post('/filteringAppointmentByClinic', 'filteringAppointmentByClinic');
        Route::post('/editSchedule', 'editSchedule');
        Route::get('/cancelAppointment', 'cancelAppointment');
        Route::get('/showCanceledAppointments', 'showCanceledAppointments');
        Route::get('/showAppointmentDetails', 'showAppointmentDetails');
        Route::get('/showTodayAppointmentByDoctor', 'showTodayAppointmentByDoctor');
        Route::get('/showDoctors', 'showDoctors');
        Route::get('/showClinicDoctors', 'showClinicDoctors');
        Route::get('/showClinics', 'showClinics');
        Route::get('/showDoctorDetails', 'showDoctorDetails');
        Route::get('/showDoctorWorkDates', 'showDoctorWorkDates');
    });
});
