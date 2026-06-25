<?php

declare(strict_types=1);

return [
    'users' => [
        'cannot_deactivate_self' => 'Нельзя деактивировать собственную учётную запись.',
    ],
    'departments' => [
        'parent_self' => 'Отдел не может быть родителем самому себе.',
        'parent_descendant' => 'Нельзя назначить родителем дочерний отдел — это создаст цикл.',
    ],
    'roles' => [
        'unknown_role' => 'Неизвестная роль.',
        'admin_not_lockable' => 'Роль администратора всегда получает все права и не может быть ограничена.',
        'unknown_permission' => 'Неизвестные права: :names.',
    ],
];
