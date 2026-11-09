<?php

/* E-Ticket business settings, read from .env (see .env.example). */
return [
    // Event dates/times are entered in local time; timestamps are stored in UTC.
    'event_tz'                => env('ETICKET_EVENT_TZ', 'Asia/Manila'),
    // Unpaid Pending reservations are cancelled (seats released) after this many hours. 0 = never.
    'hold_hours'              => (int) env('RESERVATION_HOLD_HOURS', 48),
    // Login / password-reset throttling.
    'login_window_minutes'    => (int) env('LOGIN_WINDOW_MINUTES', 15),
    'login_max_per_account'   => (int) env('LOGIN_MAX_PER_ACCOUNT', 5),
    'login_max_per_ip'        => (int) env('LOGIN_MAX_PER_IP', 50),
    // How long an Administrator-issued reset code stays valid.
    'password_reset_minutes'  => (int) env('PASSWORD_RESET_MINUTES', 30),
    'max_per_reservation'     => 10,
];
