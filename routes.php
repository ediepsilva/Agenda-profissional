<?php
declare(strict_types=1);

use App\Controllers\AgendaController;
use App\Controllers\ApiController;
use App\Controllers\AreaController;
use App\Controllers\AuthController;
use App\Controllers\AvailabilityController;
use App\Controllers\BookingController;
use App\Controllers\ClientController;
use App\Controllers\DashboardController;
use App\Controllers\PublicController;
use App\Controllers\ServiceController;
use App\Controllers\SettingsController;
use App\Core\Router;

$r = new Router();

// Área pública (sem login)
$r->get('/', [PublicController::class, 'home']);
$r->get('/agendar', [PublicController::class, 'bookingForm']);
$r->post('/agendar', [PublicController::class, 'bookingSubmit']);
$r->get('/reserva/{code}', [PublicController::class, 'bookingStatus']);
$r->post('/reserva/{code}/cancelar', [PublicController::class, 'bookingCancel']);
$r->get('/privacidade', [PublicController::class, 'privacy']);
$r->get('/api/horarios', [ApiController::class, 'slots']);
$r->get('/api/dias', [ApiController::class, 'days']);

// Autenticação
$r->get('/admin/login', [AuthController::class, 'form']);
$r->post('/admin/login', [AuthController::class, 'login']);
$r->post('/admin/logout', [AuthController::class, 'logout'], 'auth');

// Painel (permissão exigida no 3º parâmetro, verificada no servidor)
$r->get('/admin', [DashboardController::class, 'index'], 'dashboard.view');
$r->get('/admin/agenda', [AgendaController::class, 'index'], 'agenda.view');

$r->get('/admin/reservas', [BookingController::class, 'index'], 'bookings.view');
$r->get('/admin/reservas/nova', [BookingController::class, 'create'], 'bookings.manage');
$r->post('/admin/reservas', [BookingController::class, 'store'], 'bookings.manage');
$r->get('/admin/reservas/horarios', [BookingController::class, 'slots'], 'bookings.manage');
$r->get('/admin/reservas/{id}', [BookingController::class, 'show'], 'bookings.view');
$r->post('/admin/reservas/{id}/status', [BookingController::class, 'status'], 'bookings.manage');
$r->post('/admin/reservas/{id}/reagendar', [BookingController::class, 'reschedule'], 'bookings.manage');
$r->post('/admin/reservas/{id}/notas', [BookingController::class, 'notes'], 'bookings.manage');

$r->get('/admin/servicos', [ServiceController::class, 'index'], 'services.manage');
$r->get('/admin/servicos/novo', [ServiceController::class, 'create'], 'services.manage');
$r->post('/admin/servicos', [ServiceController::class, 'store'], 'services.manage');
$r->get('/admin/servicos/{id}/editar', [ServiceController::class, 'edit'], 'services.manage');
$r->post('/admin/servicos/{id}', [ServiceController::class, 'update'], 'services.manage');
$r->post('/admin/servicos/{id}/excluir', [ServiceController::class, 'destroy'], 'services.manage');

$r->get('/admin/disponibilidade', [AvailabilityController::class, 'index'], 'availability.manage');
$r->post('/admin/disponibilidade/horarios', [AvailabilityController::class, 'saveRules'], 'availability.manage');
$r->post('/admin/disponibilidade/bloqueios', [AvailabilityController::class, 'addBlock'], 'availability.manage');
$r->post('/admin/disponibilidade/bloqueios/{id}/excluir', [AvailabilityController::class, 'deleteBlock'], 'availability.manage');

$r->get('/admin/areas', [AreaController::class, 'index'], 'areas.manage');
$r->post('/admin/areas', [AreaController::class, 'store'], 'areas.manage');
$r->post('/admin/areas/{id}', [AreaController::class, 'update'], 'areas.manage');
$r->post('/admin/areas/{id}/excluir', [AreaController::class, 'destroy'], 'areas.manage');

$r->get('/admin/clientes', [ClientController::class, 'index'], 'clients.view');
$r->get('/admin/clientes/nova', [ClientController::class, 'create'], 'clients.manage');
$r->post('/admin/clientes', [ClientController::class, 'store'], 'clients.manage');
$r->get('/admin/clientes/{id}', [ClientController::class, 'show'], 'clients.view');
$r->get('/admin/clientes/{id}/editar', [ClientController::class, 'edit'], 'clients.manage');
$r->post('/admin/clientes/{id}', [ClientController::class, 'update'], 'clients.manage');

$r->get('/admin/configuracoes', [SettingsController::class, 'index'], 'settings.manage');
$r->post('/admin/configuracoes', [SettingsController::class, 'save'], 'settings.manage');

return $r;
