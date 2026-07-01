<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Validation Language Lines (EN overrides)
|--------------------------------------------------------------------------
|
| Mirrors lang/ru/validation.php so the two locales stay in lockstep. Only
| overridden keys live here; everything else falls back to the framework's
| bundled EN messages (FileLoader merges registered lang paths per-key).
|
| `current_password` reads a touch clearer than the framework default
| ("The password is incorrect.") for the self-service change flow
| (ChangePasswordRequest, POST /api/me/password).
|
*/

return [
    'current_password' => 'The current password is incorrect.',
];
