<li class="booking-row">
    <a href="<?= e(url('/admin/reservas/' . $b['id'])) ?>">
        <span class="dot" style="background: <?= e($b['professional_color']) ?>" title="<?= e($b['professional_name']) ?>"></span>
        <span class="booking-when"><?= ($showDate ?? true) ? e(date_br($b['starts_at'])) . ' · ' : '' ?><?= e(time_br($b['starts_at'])) ?></span>
        <span class="booking-what"><strong><?= e($b['client_name']) ?></strong> — <?= e($b['service_name']) ?></span>
        <?= status_badge($b['status']) ?>
    </a>
</li>
