<?php
declare(strict_types=1);

/*
 * Processa mensagens: cria lembretes/orientações que chegaram na hora e envia a fila.
 *
 *   php bin/worker.php          uma rodada (use no Agendador de Tarefas do Windows ou cron, a cada 5 min)
 *   php bin/worker.php --loop   roda continuamente (a cada 60 s) até ser interrompido
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Domain\MessageService;
use App\Integrations\Integrations;

if (PHP_SAPI !== 'cli') {
    exit('Somente via linha de comando.');
}

$lock = fopen(BASE_PATH . '/storage/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Outro worker já está em execução.\n";
    exit(0);
}

if (!App\Core\Env::get('APP_URL')) {
    echo "Aviso: defina APP_URL no .env (ex.: https://seudominio.com.br) para que os links nas mensagens fiquem corretos.\n";
}

$loop = in_array('--loop', $argv, true);
$svc = new MessageService();
echo 'Provedor de WhatsApp: ' . Integrations::whatsapp()->name() . (Integrations::whatsappConfigured() ? '' : ' (configure WHATSAPP_* no .env para envio real)') . "\n";

do {
    $created = $svc->scheduleDue();
    $r = $svc->dispatchDue(100);
    printf("[%s] lembretes criados: %d | enviadas: %d | falhas: %d | puladas: %d | nova tentativa: %d\n",
        date('d/m/Y H:i:s'), $created, $r['sent'], $r['failed'], $r['skipped'], $r['retry']);
    if ($loop) {
        sleep(60);
    }
} while ($loop);
