<section class="section">
<div class="wrap narrow prose">
    <h1>Política de privacidade</h1>
    <p><strong><?= e(setting('business_name')) ?></strong> usa seus dados apenas para organizar e realizar o atendimento que você solicitou, em conformidade com a Lei Geral de Proteção de Dados (Lei 13.709/2018).</p>
    <h2>Quais dados coletamos</h2>
    <ul>
        <li>Nome e WhatsApp — para confirmar e combinar o atendimento.</li>
        <li>E-mail (opcional) — para envio de informações da reserva.</li>
        <li>Endereço — somente quando o atendimento é no seu local.</li>
        <li>Observações que você escolher informar (por exemplo, alergias), para realizar o serviço com segurança.</li>
    </ul>
    <h2>Como usamos</h2>
    <p>Os dados não são vendidos nem compartilhados com terceiros para fins de marketing. Mensagens promocionais só serão enviadas se você autorizar expressamente, e você poderá cancelar essa autorização a qualquer momento.</p>
    <h2>Seus direitos</h2>
    <p>Você pode pedir acesso, correção ou exclusão dos seus dados a qualquer momento<?= setting('whatsapp') ? ' pelo WhatsApp ' . e(phone_br(setting('whatsapp'))) : '' ?>.</p>
</div>
</section>
