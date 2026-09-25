<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Papéis e permissões. Verificados no servidor em cada rota protegida.
 * Permissões terminadas em ".own" restringem os dados à própria profissional
 * (a restrição é aplicada nos controladores via escopo).
 */
final class Permissions
{
    public const ROLES = [
        'owner' => 'Dona',
        'manager' => 'Gerente',
        'artist' => 'Maquiadora',
        'assistant' => 'Assistente',
    ];

    public const ROLE_DESCRIPTIONS = [
        'owner' => 'Acesso total, incluindo equipe e configurações.',
        'manager' => 'Gerencia agenda, reservas, clientes, serviços, financeiro, mensagens, campanhas e avaliações. Não altera equipe nem configurações.',
        'artist' => 'Vê apenas a própria agenda, as próprias reservas e clientes, e as próprias comissões.',
        'assistant' => 'Somente leitura da agenda e das reservas.',
    ];

    private const MAP = [
        'owner' => ['*'],
        'manager' => [
            'dashboard.view', 'agenda.view', 'bookings.view', 'bookings.manage',
            'clients.view', 'clients.manage', 'services.manage', 'availability.manage', 'areas.manage',
            'team.view', 'finance.manage', 'reports.view',
            'messages.manage', 'campaigns.manage', 'reviews.manage', 'growth.view',
        ],
        'artist' => [
            'dashboard.view', 'agenda.view.own', 'bookings.view.own', 'bookings.status.own',
            'availability.manage.own', 'clients.view.own', 'reports.view.own',
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
