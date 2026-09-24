<?php
declare(strict_types=1);

/*
 * Gera os ícones PNG do PWA (192 e 512 px) sem depender da extensão GD.
 * Mesmo desenho de public/assets/icons/icon.svg. Uso: php bin/make-icons.php
 */

function png(int $w, int $h, callable $pixel): string
{
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < $w; $x++) {
            [$r, $g, $b, $a] = $pixel($x, $y);
            $raw .= chr($r) . chr($g) . chr($b) . chr($a);
        }
    }
    $chunk = static fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw, 9))
        . $chunk('IEND', '');
}

function hexrgb(string $hex): array
{
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}

/** Cor do desenho num ponto (coordenadas no espaço 0..512). */
function sample(float $x, float $y): ?array
{
    // Retângulo arredondado de fundo (rx = 112)
    $rx = 112;
    $cx = max($rx, min(512 - $rx, $x));
    $cy = max($rx, min(512 - $rx, $y));
    if (hypot($x - $cx, $y - $cy) > $rx) {
        return null;
    }
    $d = hypot($x - 256, $y - 256);
    if ($d <= 46) {
        return hexrgb('#b8916b');
    }
    if (abs($d - 150) <= 14) {
        return hexrgb('#f4e4e9');
    }
    return hexrgb('#7d3f53');
}

$out = dirname(__DIR__) . '/public/assets/icons';
foreach ([192, 512] as $size) {
    $ss = 3; // supersampling para bordas suaves
    $scale = 512 / $size;
    $data = png($size, $size, static function (int $px, int $py) use ($ss, $scale) {
        $acc = [0, 0, 0];
        $hits = 0;
        for ($i = 0; $i < $ss; $i++) {
            for ($j = 0; $j < $ss; $j++) {
                $c = sample(($px + ($i + .5) / $ss) * $scale, ($py + ($j + .5) / $ss) * $scale);
                if ($c) {
                    $acc[0] += $c[0];
                    $acc[1] += $c[1];
                    $acc[2] += $c[2];
                    $hits++;
                }
            }
        }
        if ($hits === 0) {
            return [0, 0, 0, 0];
        }
        return [(int) ($acc[0] / $hits), (int) ($acc[1] / $hits), (int) ($acc[2] / $hits), (int) round(255 * $hits / ($ss * $ss))];
    });
    file_put_contents("$out/icon-$size.png", $data);
    echo "Gerado icon-$size.png (" . strlen($data) . " bytes)\n";
}
