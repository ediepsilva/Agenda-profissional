<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;

/**
 * Equipe. Um "membro" pode ter:
 * - agenda (registro em professionals): atende clientes, tem disponibilidade, serviços, comissão;
 * - acesso ao painel (registro em users): login com um papel.
 * Os dois ficam ligados por professionals.user_id.
 *
 * Regras de segurança: sempre existe ao menos uma dona ativa; ninguém altera o
 * próprio papel nem desativa o próprio acesso; e-mails são únicos; senha mínima de 10 caracteres.
 */
final class TeamService
{
    public const MIN_PASSWORD = 10;

    /** Lista de membros (profissionais com ou sem login + usuárias sem agenda). */
    public function members(): array
    {
        $rows = Db::all(
            'SELECT p.id AS professional_id, p.name, p.color, p.commission_percent, p.active AS pro_active, p.accepts_online_booking,
                    u.id AS user_id, u.email, u.role, u.active AS user_active, u.last_login_at,
                    (SELECT COUNT(*) FROM professional_services ps WHERE ps.professional_id = p.id) AS services_count
             FROM professionals p LEFT JOIN users u ON u.id = p.user_id
             UNION ALL
             SELECT NULL, u.name, NULL, NULL, NULL, NULL, u.id, u.email, u.role, u.active, u.last_login_at, 0
             FROM users u WHERE NOT EXISTS (SELECT 1 FROM professionals p WHERE p.user_id = u.id)'
        );
        usort($rows, static function ($a, $b) {
            $activeA = (int) ($a['pro_active'] ?? $a['user_active']);
            $activeB = (int) ($b['pro_active'] ?? $b['user_active']);
            return [$activeB, $a['name']] <=> [$activeA, $b['name']];
        });
        return $rows;
    }

    /** Carrega um membro pelo id da profissional ou do usuário. */
    public function find(?int $professionalId, ?int $userId): ?array
    {
        if ($professionalId) {
            $p = Db::one('SELECT * FROM professionals WHERE id = ?', [$professionalId]);
            if (!$p) {
                return null;
            }
            $u = $p['user_id'] ? Db::one('SELECT * FROM users WHERE id = ?', [$p['user_id']]) : null;
        } elseif ($userId) {
            $u = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
            if (!$u) {
                return null;
            }
            $p = Db::one('SELECT * FROM professionals WHERE user_id = ?', [$userId]);
        } else {
            return null;
        }
        $services = $p ? array_map('intval', array_column(Db::all('SELECT service_id FROM professional_services WHERE professional_id = ?', [$p['id']]), 'service_id')) : [];
        return ['pro' => $p, 'user' => $u, 'services' => $services];
    }

    /**
     * Cria ou atualiza um membro. Retorna ['professional_id'=>?, 'user_id'=>?, 'warnings'=>[]].
     * @throws ValidationException
     */
    public function save(?array $existing, array $in, int $actingUserId): array
    {
        $pro = $existing['pro'] ?? null;
        $user = $existing['user'] ?? null;
        $errors = [];

        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Informe o nome (2 a 120 caracteres).';
        }
        $hasAgenda = !empty($in['has_agenda']);
        $hasLogin = !empty($in['has_login']);
        $active = !empty($in['active']);
        if (!$hasAgenda && !$hasLogin) {
            $errors['has_agenda'] = 'Marque "atende clientes" e/ou "acessa o painel".';
        }

        // Agenda
        $color = (string) ($in['color'] ?? '#b0677a');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $errors['color'] = 'Cor inválida.';
        }
        $commission = BookingService::parsePercent((string) ($in['commission_percent'] ?? '0'));
        if ($commission === null) {
            $errors['commission_percent'] = 'Comissão entre 0 e 100%.';
        }
        $validServices = array_map('intval', array_column(Db::all('SELECT id FROM services'), 'id'));
        $services = array_values(array_intersect(array_map('intval', (array) ($in['services'] ?? [])), $validServices));

        // Acesso
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $role = (string) ($in['role'] ?? 'artist');
        $password = (string) ($in['password'] ?? '');
        if ($hasLogin) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'E-mail inválido.';
            } elseif (Db::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $user['id'] ?? 0])) {
                $errors['email'] = 'Este e-mail já está em uso.';
            }
            if (!isset(Permissions::ROLES[$role])) {
                $errors['role'] = 'Papel inválido.';
            }
            if (!$user && mb_strlen($password) < self::MIN_PASSWORD) {
                $errors['password'] = 'Defina uma senha inicial com ao menos ' . self::MIN_PASSWORD . ' caracteres.';
            }
            if ($user && $password !== '' && mb_strlen($password) < self::MIN_PASSWORD) {
                $errors['password'] = 'A nova senha precisa de ao menos ' . self::MIN_PASSWORD . ' caracteres.';
            }
        }

        // Proteções
        if ($user && (int) $user['id'] === $actingUserId) {
            if (!$hasLogin || !$active || $role !== $user['role']) {
                $errors['role'] = 'Você não pode alterar o próprio papel nem desativar o próprio acesso.';
            }
        }
        if ($user && $user['role'] === 'owner' && $user['active'] && (!$hasLogin || !$active || $role !== 'owner')) {
            $owners = (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'owner' AND active = 1");
            if ($owners <= 1) {
                $errors['role'] = 'É preciso manter ao menos uma dona com acesso ativo.';
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return Db::transaction(function () use ($pro, $user, $name, $hasAgenda, $hasLogin, $active, $color, $commission, $services, $email, $role, $password, $in) {
            $warnings = [];
            $userId = $user['id'] ?? null;
            if ($hasLogin) {
                $data = ['name' => $name, 'email' => $email, 'role' => $role, 'active' => $active ? 1 : 0];
                if ($password !== '') {
                    $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                if ($user) {
                    Db::update('users', $data, 'id = ?', [$user['id']]);
                } else {
                    $userId = Db::insert('users', $data);
                }
            } elseif ($user) {
                Db::update('users', ['active' => 0], 'id = ?', [$user['id']]);
            }

            $proId = $pro['id'] ?? null;
            if ($hasAgenda) {
                $data = [
                    'name' => $name,
                    'color' => $color,
                    'commission_percent' => $commission,
                    'bio' => mb_substr(trim((string) ($in['bio'] ?? '')), 0, 2000) ?: null,
                    'accepts_online_booking' => !empty($in['accepts_online_booking']) ? 1 : 0,
                    'active' => $active ? 1 : 0,
                    'user_id' => $userId,
                ];
                if ($pro) {
                    Db::update('professionals', $data, 'id = ?', [$pro['id']]);
                } else {
                    $proId = Db::insert('professionals', $data);
                }
                Db::exec('DELETE FROM professional_services WHERE professional_id = ?', [$proId]);
                foreach ($services as $sid) {
                    Db::insert('professional_services', ['professional_id' => $proId, 'service_id' => $sid]);
                }
            } elseif ($pro) {
                // Mantém o histórico: a agenda é desativada, não apagada.
                Db::update('professionals', ['active' => 0], 'id = ?', [$pro['id']]);
            }

            if ($proId && (!$hasAgenda || !$active)) {
                $future = (int) Db::value(
                    "SELECT COUNT(*) FROM booking_allocations a JOIN bookings b ON b.id = a.booking_id
                     WHERE a.professional_id = ? AND b.starts_at >= ? AND b.status IN ('requested','awaiting_deposit','confirmed')",
                    [$proId, Clock::now()->format('Y-m-d H:i:s')]
                );
                if ($future) {
                    $warnings[] = "Atenção: esta profissional tem $future atendimento(s) futuro(s). Redistribua-os em Reservas.";
                }
            }
            return ['professional_id' => $proId, 'user_id' => $userId, 'warnings' => $warnings];
        });
    }
}
