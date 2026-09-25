<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Clock;
use App\Domain\ReferralService;
use App\Domain\ReviewService;
use DomainException;

/** Captação: avaliações, indicações e links de divulgação com origem rastreável. */
final class GrowthController extends Controller
{
    public const LINK_ORIGINS = [
        'instagram-bio' => 'Link da bio do Instagram',
        'instagram-stories' => 'Stories do Instagram',
        'whatsapp-status' => 'Status do WhatsApp',
        'google' => 'Perfil no Google',
        'tiktok' => 'TikTok',
        'cartao' => 'Cartão de visita / QR code',
    ];

    public function index(): void
    {
        $since = Clock::now()->modify('-90 days')->format('Y-m-d');
        $this->view('admin/growth/index', [
            'pageTitle' => 'Captação',
            'links' => array_map(static fn ($o) => absolute_url('/agendar?origem=' . $o), array_combine(array_keys(self::LINK_ORIGINS), array_keys(self::LINK_ORIGINS))),
            'byOrigin' => Db::all(
                "SELECT COALESCE(origin, 'direto') AS origin, COUNT(*) AS total, SUM(status = 'completed') AS completed
                 FROM bookings WHERE created_at >= ? GROUP BY COALESCE(origin, 'direto') ORDER BY total DESC",
                [$since]
            ),
            'ranking' => ReferralService::ranking(20),
            'referredBookings' => (int) Db::value('SELECT COUNT(*) FROM bookings WHERE referred_by_client_id IS NOT NULL AND created_at >= ?', [$since]),
            'reviewStats' => (new ReviewService())->stats(null),
            'publishedStats' => (new ReviewService())->stats('approved'),
            'reviews' => Db::all(
                'SELECT r.*, c.name AS client_name, p.name AS professional_name FROM reviews r
                 JOIN clients c ON c.id = r.client_id LEFT JOIN professionals p ON p.id = r.professional_id
                 ORDER BY r.status = \'pending\' DESC, r.id DESC LIMIT 100'
            ),
            'optedIn' => (int) Db::value('SELECT COUNT(*) FROM clients WHERE marketing_opt_in = 1'),
            'totalClients' => (int) Db::value('SELECT COUNT(*) FROM clients'),
        ]);
    }

    public function moderate(string $id): void
    {
        try {
            (new ReviewService())->moderate((int) $id, $this->input('status'), $this->userId());
            Session::flash('success', 'Avaliação atualizada.');
        } catch (DomainException $e) {
            Session::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/captacao#avaliacoes');
    }
}
