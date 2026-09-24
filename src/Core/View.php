<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'admin'): void
    {
        $content = self::capture($template, $data);
        if ($layout === null) {
            echo $content;
        } else {
            echo self::capture('layouts/' . $layout, $data + ['content' => $content]);
        }
        // Dados de formulário e erros valem apenas para a próxima renderização.
        Session::forget('_old');
        Session::forget('_errors');
    }

    public static function capture(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View não encontrada: $template");
        }
        $data['errors'] ??= Session::get('_errors', []);
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    public static function partial(string $template, array $data = []): string
    {
        return self::capture('partials/' . $template, $data);
    }
}
