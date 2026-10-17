<?php

return [
    // Calendar days are counted in the company's timezone (Company.settings.timezone) or, if unset, this default.
    'timezone' => env('COMPANY_DEFAULT_TIMEZONE', 'Africa/Cairo'),

    // Paid subscriptions show an "ends in N days" notice and send the daily email only inside this window.
    // Trial and test periods are always announced because their end date is the key fact for the user.
    'notice_days' => (int) env('SUBSCRIPTION_NOTICE_DAYS', 7),
];
