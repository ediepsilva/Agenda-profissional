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
use App\Controllers\FinanceController;
use App\Controllers\PublicController;
use App\Controllers\ReportController;
use App\Controllers\ServiceController;
use App\Controllers\SettingsController;
use App\Controllers\TeamController;
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
$r->get('/admin/agenda', [AgendaController::class, 'index'], ['agenda.view', 'agenda.view.own']);

$r->get('/admin/reservas', [BookingController::class, 'index'], ['bookings.view', 'bookings.view.own']);
$r->get('/admin/reservas/nova', [BookingController::class, 'create'], 'bookings.manage');
$r->post('/admin/reservas', [BookingController::class, 'store'], 'bookings.manage');
$r->get('/admin/reservas/horarios', [BookingController::class, 'slots'], 'bookings.manage');
$r->get('/admin/reservas/sugestao', [BookingController::class, 'suggest'], 'bookings.manage');
$r->get('/admin/reservas/{id}', [BookingController::class, 'show'], ['bookings.view', 'bookings.view.own']);
$r->post('/admin/reservas/{id}/status', [BookingController::class, 'status'], ['bookings.manage', 'bookings.status.own']);
$r->post('/admin/reservas/{id}/reagendar', [BookingController::class, 'reschedule'], 'bookings.manage');
$r->post('/admin/reservas/{id}/notas', [BookingController::class, 'notes'], 'bookings.manage');
$r->post('/admin/reservas/{id}/equipe/trocar', [BookingController::class, 'reassign'], 'bookings.manage');
$r->post('/admin/reservas/{id}/equipe/adicionar', [BookingController::class, 'addProfessional'], 'bookings.manage');
$r->post('/admin/reservas/{id}/equipe/remover', [BookingController::class, 'removeProfessional'], 'bookings.manage');
$r->post('/admin/reservas/{id}/equipe/divisao', [FinanceController::class, 'allocationTerms'], 'finance.manage');
$r->post('/admin/reservas/{id}/valores', [FinanceController::class, 'bookingValues'], 'finance.manage');
$r->post('/admin/reservas/{id}/pagamentos', [FinanceController::class, 'addPayment'], 'finance.manage');
$r->post('/admin/pagamentos/{id}/excluir', [FinanceController::class, 'deletePayment'], 'finance.manage');
$r->post('/admin/reservas/{id}/despesas', [FinanceController::class, 'addBookingExpense'], 'finance.manage');
$r->post('/admin/despesas/{id}/excluir', [FinanceController::class, 'deleteExpense'], 'finance.manage');

$r->get('/admin/eventos/novo', [BookingController::class, 'createEvent'], 'bookings.manage');
$r->post('/admin/eventos', [BookingController::class, 'storeEvent'], 'bookings.manage');

$r->get('/admin/financeiro/despesas', [FinanceController::class, 'expenses'], 'finance.manage');
$r->post('/admin/financeiro/despesas', [FinanceController::class, 'addExpense'], 'finance.manage');
$r->get('/admin/relatorios', [ReportController::class, 'index'], ['reports.view', 'reports.view.own']);
$r->get('/admin/relatorios/exportar', [ReportController::class, 'export'], ['reports.view', 'reports.view.own']);

$r->get('/admin/equipe', [TeamController::class, 'index'], 'team.view');
$r->get('/admin/equipe/novo', [TeamController::class, 'create'], 'team.manage');
$r->post('/admin/equipe', [TeamController::class, 'store'], 'team.manage');
$r->get('/admin/equipe/editar', [TeamController::class, 'edit'], 'team.manage');
$r->post('/admin/equipe/salvar', [TeamController::class, 'update'], 'team.manage');

$r->get('/admin/servicos', [ServiceController::class, 'index'], 'services.manage');
$r->get('/admin/servicos/novo', [ServiceController::class, 'create'], 'services.manage');
$r->post('/admin/servicos', [ServiceController::class, 'store'], 'services.manage');
$r->get('/admin/servicos/{id}/editar', [ServiceController::class, 'edit'], 'services.manage');
$r->post('/admin/servicos/{id}', [ServiceController::class, 'update'], 'services.manage');
$r->post('/admin/servicos/{id}/excluir', [ServiceController::class, 'destroy'], 'services.manage');

$r->get('/admin/disponibilidade', [AvailabilityController::class, 'index'], ['availability.manage', 'availability.manage.own']);
$r->post('/admin/disponibilidade/horarios', [AvailabilityController::class, 'saveRules'], ['availability.manage', 'availability.manage.own']);
$r->post('/admin/disponibilidade/bloqueios', [AvailabilityController::class, 'addBlock'], ['availability.manage', 'availability.manage.own']);
$r->post('/admin/disponibilidade/bloqueios/{id}/excluir', [AvailabilityController::class, 'deleteBlock'], ['availability.manage', 'availability.manage.own']);

$r->get('/admin/areas', [AreaController::class, 'index'], 'areas.manage');
$r->post('/admin/areas', [AreaController::class, 'store'], 'areas.manage');
$r->post('/admin/areas/{id}', [AreaController::class, 'update'], 'areas.manage');
$r->post('/admin/areas/{id}/excluir', [AreaController::class, 'destroy'], 'areas.manage');

$r->get('/admin/clientes', [ClientController::class, 'index'], ['clients.view', 'clients.view.own']);
$r->get('/admin/clientes/nova', [ClientController::class, 'create'], 'clients.manage');
$r->post('/admin/clientes', [ClientController::class, 'store'], 'clients.manage');
$r->get('/admin/clientes/{id}', [ClientController::class, 'show'], ['clients.view', 'clients.view.own']);
$r->get('/admin/clientes/{id}/editar', [ClientController::class, 'edit'], 'clients.manage');
$r->post('/admin/clientes/{id}', [ClientController::class, 'update'], 'clients.manage');

$r->get('/admin/configuracoes', [SettingsController::class, 'index'], 'settings.manage');
$r->post('/admin/configuracoes', [SettingsController::class, 'save'], 'settings.manage');

return $r;
