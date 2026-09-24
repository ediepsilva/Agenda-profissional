<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Domain\Clock;

final class DashboardController extends Controller
{
    private const SELECT = 'SELECT b.*, c.name AS client_name, c.phone AS client_phone, s.name AS service_name, p.name AS professional_name, p.color AS professional_color
        FROM bookings b
        JOIN clients c ON c.id = b.client_id
        JOIN services s ON s.id = b.service_id
        JOIN professionals p ON p.id = b.professional_id';

    public function index(): void
    {
        $now = Clock::now();
        $today = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $week = $now->modify('+7 days')->format('Y-m-d');

        $pending = Db::all(self::SELECT . " WHERE b.status IN ('requested','awaiting_deposit') AND b.starts_at >= ? ORDER BY b.starts_at LIMIT 20", [$today]);
        $todayList = Db::all(self::SELECT . " WHERE b.starts_at >= ? AND b.starts_at < ? AND b.status NOT IN ('cancelled') ORDER BY b.starts_at", [$today, $tomorrow]);
        $upcoming = Db::all(self::SELECT . " WHERE b.starts_at >= ? AND b.starts_at < ? AND b.status IN ('confirmed','awaiting_deposit') ORDER BY b.starts_at LIMIT 15", [$tomorrow, $week]);

        $stats = [
            'requested' => (int) Db::value("SELECT COUNT(*) FROM bookings WHERE status = 'requested' AND starts_at >= ?", [$today]),
            'awaiting_deposit' => (int) Db::value("SELECT COUNT(*) FROM bookings WHERE status = 'awaiting_deposit' AND starts_at >= ?", [$today]),
            'today' => count($todayList),
            'week' => (int) Db::value("SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','awaiting_deposit','requested') AND starts_at >= ? AND starts_at < ?", [$today, $week]),
        ];

        $this->view('admin/dashboard', [
            'pageTitle' => 'Visão geral',
            'pending' => $pending,
            'todayList' => $todayList,
            'upcoming' => $upcoming,
            'stats' => $stats,
            'publicLink' => absolute_url('/'),
        ]);
    }
}
