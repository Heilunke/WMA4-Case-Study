<?php

/* Our system settings. The values come from the .env file (see .env.example). */
return [
    // Event dates and times are in Philippine time, but we save timestamps in UTC.
    'event_tz'                => env('ETICKET_EVENT_TZ', 'Asia/Manila'),
    // After how many hours an unpaid Pending reservation gets cancelled. 0 = never.
    'hold_hours'              => (int) env('RESERVATION_HOLD_HOURS', 48),
    // Limits for wrong logins and wrong reset codes.
    'login_window_minutes'    => (int) env('LOGIN_WINDOW_MINUTES', 15),
    'login_max_per_account'   => (int) env('LOGIN_MAX_PER_ACCOUNT', 5),
    'login_max_per_ip'        => (int) env('LOGIN_MAX_PER_IP', 50),
    // How many minutes the reset code from the admin can be used.
    'password_reset_minutes'  => (int) env('PASSWORD_RESET_MINUTES', 30),
    'max_per_reservation'     => 10,
];
