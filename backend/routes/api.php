<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

/*
 * Our REST API. All of these are under /api and use the 'eticket' middleware group (session + JSON checks).
 * The frontend (frontend/js/store.js), our Postman collection and the API test (tests/api-smoke.mjs) all call these.
 *
 * Who can access:  public = no login needed, eticket.auth = any logged-in user,
 *                  eticket.auth:admin = admin only, eticket.auth:attendee = attendee only.
 * eticket.csrf = requests that change something must send the X-CSRF-Token header.
 */

/* ---------------- no login needed ---------------- */
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

/* ---------------- any logged-in user ---------------- */
Route::middleware(['eticket.auth', 'eticket.csrf'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('/bootstrap', BootstrapController::class);
    Route::get('/events', [EventController::class, 'index']);            // ?q=&category=&date=&from=
    Route::get('/events/{id}', [EventController::class, 'show']);
    Route::get('/reservations', [ReservationController::class, 'index']); // ?status=&q= (attendees only see their own)
    Route::get('/reservations/{id}', [ReservationController::class, 'show']);
    Route::post('/reservations/{id}/cancel', [ReservationController::class, 'cancel']);
    Route::get('/reservations/{id}/ticket', [ReservationController::class, 'ticket']);
});

/* ---------------- attendees only ---------------- */
Route::middleware(['eticket.auth:attendee', 'eticket.csrf'])->group(function () {
    Route::post('/reservations', [ReservationController::class, 'store']);
});

/* ---------------- admin only ---------------- */
Route::middleware(['eticket.auth:admin', 'eticket.csrf'])->group(function () {
    Route::post('/events', [EventController::class, 'store']);
    Route::put('/events/{id}', [EventController::class, 'update']);
    Route::delete('/events/{id}', [EventController::class, 'destroy']);

    Route::post('/reservations/{id}/confirm', [ReservationController::class, 'confirm']);
    Route::post('/reservations/{id}/payment', [ReservationController::class, 'payment']);

    Route::post('/checkin', [CheckInController::class, 'store']);
    Route::post('/attendees/{id}/undo-checkin', [CheckInController::class, 'undo']);

    Route::get('/reports', [AdminController::class, 'reports']);
    Route::get('/users', [AdminController::class, 'users']);
    Route::put('/users/{id}/role', [AdminController::class, 'setRole']);
    Route::post('/users/{id}/reset-code', [AdminController::class, 'resetCode']);
});
