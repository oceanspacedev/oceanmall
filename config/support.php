<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Customer support contact
|--------------------------------------------------------------------------
|
| Shown on the public /bantuan, /legal/* and /hapus-akun pages that App Store Connect and Play
| Console link to. Keep these identical to EXPO_PUBLIC_SUPPORT_EMAIL / EXPO_PUBLIC_SUPPORT_WHATSAPP
| in mobile/.env.production, so the app and the website name the same mailbox and number.
|
*/

return [
    'email' => env('SUPPORT_EMAIL'),

    // Digits in international format without '+' (for example 62812xxxxxxxx).
    'whatsapp' => env('SUPPORT_WHATSAPP'),

    // The single source of the legal text, shared with the mobile app (mobile/lib/legal-content.ts).
    'legal_source' => env('LEGAL_SOURCE_PATH', base_path('mobile/lib/legal-content.json')),
];
