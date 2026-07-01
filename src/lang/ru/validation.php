<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Validation Language Lines (RU overrides)
|--------------------------------------------------------------------------
|
| Only keys that need a Russian override live here. Any key not present
| falls back to the framework's bundled messages (both the framework lang
| path and this app `lang/` path are registered, and FileLoader merges them
| with array_replace_recursive — a per-key override, not a full shadow).
|
| `current_password` backs Laravel's built-in `current_password` rule, used
| by ChangePasswordRequest (POST /api/me/password). Without this line a wrong
| current password rendered the EN default ("The password is incorrect.")
| even for the RU UI, because the framework ships no RU translation for it.
|
*/

return [
    'current_password' => 'Указан неверный текущий пароль.',
];
