<?php

declare(strict_types=1);

return [
    'users' => [
        'cannot_deactivate_self' => 'You cannot deactivate your own account.',
    ],
    'departments' => [
        'parent_self' => 'A department cannot be its own parent.',
        'parent_descendant' => 'A descendant department cannot be set as the parent — this would create a cycle.',
    ],
    'roles' => [
        'unknown_role' => 'Unknown role.',
        'admin_not_lockable' => 'The admin role always has every permission and cannot be restricted.',
        'unknown_permission' => 'Unknown permissions: :names.',
    ],
];
