<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

/*
 * The E-Ticket REST API, served under /api with the 'eticket' middleware group (session cookie,
 * JSON checks). Called by the frontend (frontend/js/store.js), the Postman collection (postman/) and
 * the API smoke test (tests/api-smoke.mjs).
 *
 * Access levels: public | eticket.auth (any login) | eticket.auth:admin | eticket.auth:attendee.
 * eticket.csrf: state-changing requests must send the X-CSRF-Token header.
 */

/* ---------------- public ---------------- */
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

/* ---------------- any logged-in user ---------------- */
Route::middleware(['eticket.auth', 'eticket.csrf'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('/bootstrap', BootstrapController::class);
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/reservations/{id}', [ReservationController::class, 'show']);
    Route::post('/reservations/{id}/cancel', [ReservationController::class, 'cancel']);
    Route::get('/reservations/{id}/ticket', [ReservationController::class, 'ticket']);
});

/* ---------------- attendees ---------------- */
Route::middleware(['eticket.auth:attendee', 'eticket.csrf'])->group(function () {
    Route::post('/reservations', [ReservationController::class, 'store']);
});

/* ---------------- Administrators ---------------- */
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
