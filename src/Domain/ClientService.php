<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;

final class ClientService
{
    /** Mantém só dígitos; remove o DDI 55 quando presente. */
    public static function normalizePhone(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone);
        if ((strlen($d) === 12 || strlen($d) === 13) && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        }
        return $d;
    }

    /** @return array<string,string> */
    public static function validate(array $in, bool $requirePrivacy = false, bool $full = false): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Informe o nome (2 a 120 caracteres).';
        }
        $phone = self::normalizePhone((string) ($in['phone'] ?? ''));
        if (!preg_match('/^[1-9]{2}9?\d{8}$/', $phone)) {
            $errors['phone'] = 'Informe um WhatsApp válido com DDD.';
        }
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
            $errors['email'] = 'E-mail inválido.';
        }
        $source = (string) ($in['source'] ?? '');
        if ($source !== '' && !isset(BookingService::SOURCES[$source])) {
            $errors['source'] = 'Opção inválida.';
        }
        if ($requirePrivacy && empty($in['privacy'])) {
            $errors['privacy'] = 'É preciso concordar com o uso dos dados para o agendamento.';
        }
        if ($full) {
            $birth = trim((string) ($in['birth_date'] ?? ''));
            if ($birth !== '') {
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
                if (!$d || $d->format('Y-m-d') !== $birth || $d > Clock::now()) {
                    $errors['birth_date'] = 'Data inválida.';
                }
            }
            $ig = trim((string) ($in['instagram'] ?? ''));
            if ($ig !== '' && !preg_match('/^@?[A-Za-z0-9._]{1,30}$/', $ig)) {
                $errors['instagram'] = 'Usuário do Instagram inválido.';
            }
            foreach (['preferences', 'notes'] as $f) {
                if (mb_strlen((string) ($in[$f] ?? '')) > 2000) {
                    $errors[$f] = 'Use no máximo 2000 caracteres.';
                }
            }
        }
        return $errors;
    }

    /**
     * Localiza a cliente pelo WhatsApp ou cria uma nova.
     * Dados já existentes não são sobrescritos por dados digitados na página pública.
     */
    public static function findOrCreate(array $in, bool $privacyAccepted): int
    {
        $phone = self::normalizePhone((string) $in['phone']);
        $email = trim((string) ($in['email'] ?? '')) ?: null;
        $source = ($in['source'] ?? '') ?: null;
        $now = Clock::now()->format('Y-m-d H:i:s');

        $existing = Db::one('SELECT * FROM clients WHERE phone = ? FOR UPDATE', [$phone]);
        if ($existing) {
            $fill = [];
            if (!$existing['email'] && $email) {
                $fill['email'] = $email;
            }
            if (!$existing['source'] && $source) {
                $fill['source'] = $source;
            }
            if (!$existing['privacy_accepted_at'] && $privacyAccepted) {
                $fill['privacy_accepted_at'] = $now;
            }
            if ($fill) {
                Db::update('clients', $fill, 'id = ?', [$existing['id']]);
            }
            return (int) $existing['id'];
        }

        return Db::insert('clients', [
            'name' => trim((string) $in['name']),
            'phone' => $phone,
            'email' => $email,
            'source' => $source,
            'privacy_accepted_at' => $privacyAccepted ? $now : null,
        ]);
    }

    /** Campos editáveis pelo painel. */
    public static function fromInput(array $in): array
    {
        $opt = static fn (string $k) => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : $v;
        $ig = $opt('instagram');
        return [
            'name' => trim((string) $in['name']),
            'phone' => self::normalizePhone((string) $in['phone']),
            'email' => $opt('email'),
            'instagram' => $ig ? ltrim($ig, '@') : null,
            'birth_date' => $opt('birth_date'),
            'source' => $opt('source'),
            'preferences' => $opt('preferences'),
            'notes' => $opt('notes'),
        ];
    }
}
