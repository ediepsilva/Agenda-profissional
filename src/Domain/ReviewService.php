<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;
use DomainException;

/**
 * Avaliações: a cliente avalia pelo link da própria reserva (código secreto), uma vez,
 * somente após o atendimento concluído. Publicação na página após aprovação
 * (ou automática, se configurado).
 */
final class ReviewService
{
    public const STATUS_LABELS = ['pending' => 'Aguardando aprovação', 'approved' => 'Publicada', 'hidden' => 'Oculta'];

    public function canReview(array $booking): bool
    {
        return $booking['status'] === 'completed'
            && !Db::value('SELECT id FROM reviews WHERE booking_id = ?', [$booking['id']]);
    }

    /** @throws ValidationException|DomainException */
    public function submit(array $booking, array $in): int
    {
        if (!$this->canReview($booking)) {
            throw new DomainException('Esta reserva não pode ser avaliada (ainda não concluída ou já avaliada).');
        }
        $errors = [];
        $rating = (int) ($in['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Escolha de 1 a 5 estrelas.';
        }
        $comment = trim((string) ($in['comment'] ?? ''));
        if (mb_strlen($comment) > 1000) {
            $errors['comment'] = 'Use no máximo 1000 caracteres.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        // Nome exibido: primeiro nome + inicial do sobrenome (privacidade).
        $parts = preg_split('/\s+/', trim((string) $booking['client_name']));
        $display = $parts[0] . (count($parts) > 1 ? ' ' . mb_substr(end($parts), 0, 1) . '.' : '');
        $auto = Settings::int('reviews_auto_approve', 0) === 1;

        return Db::insert('reviews', [
            'booking_id' => $booking['id'],
            'client_id' => $booking['client_id'],
            'professional_id' => $booking['professional_id'],
            'rating' => $rating,
            'comment' => $comment !== '' ? $comment : null,
            'display_name' => mb_substr($display, 0, 60),
            'status' => $auto ? 'approved' : 'pending',
        ]);
    }

    public function moderate(int $id, string $status, ?int $userId): void
    {
        if (!isset(self::STATUS_LABELS[$status])) {
            throw new DomainException('Status inválido.');
        }
        Db::update('reviews', ['status' => $status, 'moderated_by' => $userId, 'moderated_at' => Clock::now()->format('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    public function published(int $limit = 6): array
    {
        return Db::all("SELECT r.*, s.name AS service_name FROM reviews r JOIN bookings b ON b.id = r.booking_id JOIN services s ON s.id = b.service_id WHERE r.status = 'approved' ORDER BY r.created_at DESC LIMIT " . (int) $limit);
    }

    /** @return array{count:int, average:float} */
    public function stats(?string $status = 'approved'): array
    {
        $row = Db::one('SELECT COUNT(*) AS n, COALESCE(AVG(rating),0) AS avg FROM reviews' . ($status ? ' WHERE status = ?' : ''), $status ? [$status] : []);
        return ['count' => (int) $row['n'], 'average' => round((float) $row['avg'], 1)];
    }
}
