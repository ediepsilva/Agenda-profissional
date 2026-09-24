<?php
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Session;

/** Escapa texto para HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Caminho-base da aplicação (vazio no servidor embutido; subpasta no Apache). */
function base_path_url(): string
{
    static $base = null;
    if ($base === null) {
        // No Windows, dirname('/index.php') devolve "\": normaliza as barras depois do dirname.
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($base === '.') {
            $base = '';
        }
    }
    return $base;
}

function url(string $path = '/', array $query = []): string
{
    $u = base_path_url() . '/' . ltrim($path, '/');
    $u = implode('/', array_map('rawurlencode', explode('/', $u)));
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public/' . ltrim($path, '/');
    $v = is_file($file) ? substr((string) filemtime($file), -6) : '1';
    return url($path) . '?v=' . $v;
}

function absolute_url(string $path = '/'): string
{
    $configured = App\Core\Env::get('APP_URL');
    if ($configured) {
        return rtrim($configured, '/') . '/' . ltrim($path, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

/** Valor anterior do formulário (após erro de validação). */
function old(string $key, mixed $default = ''): mixed
{
    $old = Session::get('_old', []);
    return $old[$key] ?? $default;
}

function money(?int $cents): string
{
    return 'R$ ' . number_format(($cents ?? 0) / 100, 2, ',', '.');
}

/** Converte "150,00" / "1.500" / "150.5" em centavos. */
function parse_money(string $input): ?int
{
    $v = trim(str_replace(['R$', ' '], '', $input));
    if ($v === '') {
        return null;
    }
    if (str_contains($v, ',')) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    }
    if (!is_numeric($v) || (float) $v < 0) {
        return null;
    }
    return (int) round(((float) $v) * 100);
}

function money_input(?int $cents): string
{
    return $cents === null ? '' : number_format($cents / 100, 2, ',', '');
}

function date_br(string|DateTimeInterface|null $date): string
{
    if ($date === null || $date === '') {
        return '';
    }
    $d = $date instanceof DateTimeInterface ? $date : new DateTimeImmutable($date);
    return $d->format('d/m/Y');
}

function datetime_br(string|DateTimeInterface|null $date): string
{
    if ($date === null || $date === '') {
        return '';
    }
    $d = $date instanceof DateTimeInterface ? $date : new DateTimeImmutable($date);
    return $d->format('d/m/Y H:i');
}

function time_br(string|DateTimeInterface $date): string
{
    $d = $date instanceof DateTimeInterface ? $date : new DateTimeImmutable($date);
    return $d->format('H:i');
}

function weekday_name(int $weekday, bool $short = false): string
{
    $long = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    $abbr = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    return $short ? $abbr[$weekday] : $long[$weekday];
}

function month_name(int $month): string
{
    return ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho',
        'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'][$month];
}

function date_long_br(string|DateTimeInterface $date): string
{
    $d = $date instanceof DateTimeInterface ? $date : new DateTimeImmutable($date);
    return weekday_name((int) $d->format('w')) . ', ' . $d->format('j') . ' de ' . month_name((int) $d->format('n')) . ' de ' . $d->format('Y');
}

function duration_br(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h && $m) {
        return "{$h}h{$m}min";
    }
    return $h ? "{$h}h" : "{$m}min";
}

function phone_br(?string $digits): string
{
    $d = preg_replace('/\D/', '', (string) $digits);
    if (strlen($d) === 11) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
    }
    if (strlen($d) === 10) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
    }
    return (string) $digits;
}

/** Link oficial "clique para conversar" do WhatsApp (não é automação). */
function whatsapp_link(string $digits, string $text = ''): string
{
    $d = preg_replace('/\D/', '', $digits);
    if (strlen($d) <= 11) {
        $d = '55' . $d;
    }
    return 'https://wa.me/' . $d . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function setting(string $key, string $default = ''): string
{
    return App\Domain\Settings::get($key, $default);
}

/** Mensagem de erro de um campo do formulário. */
function field_error(array $errors, string $field): string
{
    return isset($errors[$field])
        ? '<p class="field-error" id="err-' . e($field) . '">' . e($errors[$field]) . '</p>'
        : '';
}

function invalid_attr(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="err-' . e($field) . '"' : '';
}

function status_badge(string $status): string
{
    return '<span class="badge badge-' . e($status) . '">' . e(App\Domain\BookingService::label($status)) . '</span>';
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(bool $on): string
{
    return $on ? ' checked' : '';
}

function nav_active(string $prefix): string
{
    $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $path = substr($path, strlen(base_path_url())) ?: '/';
    $active = $prefix === '/admin' ? $path === '/admin' : str_starts_with($path, $prefix);
    return $active ? ' aria-current="page" class="active"' : '';
}
