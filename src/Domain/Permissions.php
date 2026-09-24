<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Papéis e permissões. Verificados no servidor em cada rota protegida.
 * Permissões terminadas em ".own" restringem à própria agenda (usadas na Fase 2).
 */
final class Permissions
{
    public const ROLES = [
        'owner' => 'Dona',
        'manager' => 'Gerente',
        'artist' => 'Maquiadora',
        'assistant' => 'Assistente',
    ];

    private const MAP = [
        'owner' => ['*'],
        'manager' => [
            'dashboard.view', 'agenda.view', 'bookings.view', 'bookings.manage',
            'clients.view', 'clients.manage', 'services.manage', 'availability.manage', 'areas.manage',
        ],
        'artist' => [
            'dashboard.view', 'agenda.view.own', 'bookings.view.own', 'availability.manage.own', 'clients.view',
        ],
        'assistant' => [
            'dashboard.view', 'agenda.view', 'bookings.view',
        ],
    ];

    public static function allows(string $role, string $permission): bool
    {
        $granted = self::MAP[$role] ?? [];
        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    public static function roleLabel(string $role): string
    {
        return self::ROLES[$role] ?? $role;
    }
}
