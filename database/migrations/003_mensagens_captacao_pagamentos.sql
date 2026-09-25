-- Fase 3: mensagens (WhatsApp oficial), consentimento, avaliações, indicações e pagamento online.

-- Consentimento para campanhas (separado dos avisos da reserva) e indicação.
ALTER TABLE clients
    ADD marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER privacy_accepted_at,
    ADD marketing_updated_at DATETIME NULL AFTER marketing_opt_in,
    ADD preferences_token CHAR(32) NULL AFTER marketing_updated_at,
    ADD referral_code VARCHAR(12) NULL AFTER preferences_token,
    ADD referred_by_client_id INT UNSIGNED NULL AFTER referral_code,
    ADD UNIQUE KEY uq_clients_pref_token (preferences_token),
    ADD UNIQUE KEY uq_clients_referral (referral_code),
    ADD CONSTRAINT fk_clients_referrer FOREIGN KEY (referred_by_client_id) REFERENCES clients(id) ON DELETE SET NULL;

-- Registro de cada aceite/revogação (prova de consentimento — LGPD).
CREATE TABLE consent_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    purpose VARCHAR(30) NOT NULL DEFAULT 'marketing',
    action ENUM('opt_in','opt_out') NOT NULL,
    channel VARCHAR(30) NOT NULL,
    note VARCHAR(190) NULL,
    ip VARCHAR(45) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_consent_client (client_id, created_at),
    CONSTRAINT fk_consent_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_consent_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bookings
    ADD referred_by_client_id INT UNSIGNED NULL AFTER channel,
    ADD origin VARCHAR(40) NULL AFTER referred_by_client_id,
    ADD CONSTRAINT fk_bookings_referrer FOREIGN KEY (referred_by_client_id) REFERENCES clients(id) ON DELETE SET NULL;

-- Modelos de mensagem. Na API oficial do WhatsApp, mensagens iniciadas pela empresa
-- usam modelos aprovados pela Meta: "name" é o nome do modelo lá; "param_order" diz
-- quais variáveis preenchem {{1}}, {{2}}...; "preview" é o texto equivalente (exibido no painel/simulação).
CREATE TABLE message_templates (
    template_key VARCHAR(40) PRIMARY KEY,
    label VARCHAR(80) NOT NULL,
    category ENUM('transactional','marketing') NOT NULL,
    name VARCHAR(100) NOT NULL,
    language VARCHAR(10) NOT NULL DEFAULT 'pt_BR',
    param_order VARCHAR(255) NOT NULL DEFAULT '',
    preview TEXT NOT NULL,
    offset_hours SMALLINT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    template_key VARCHAR(40) NOT NULL,
    audience VARCHAR(30) NOT NULL,
    audience_param VARCHAR(40) NULL,
    offer_text VARCHAR(190) NULL,
    status ENUM('draft','queued') NOT NULL DEFAULT 'draft',
    recipients_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    queued_at DATETIME NULL,
    CONSTRAINT fk_campaign_template FOREIGN KEY (template_key) REFERENCES message_templates(template_key),
    CONSTRAINT fk_campaign_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fila de saída (outbox). dedupe_key impede duplicar o mesmo aviso (ex.: lembrete da reserva 10).
CREATE TABLE messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category ENUM('transactional','marketing') NOT NULL,
    template_key VARCHAR(40) NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    booking_id INT UNSIGNED NULL,
    campaign_id INT UNSIGNED NULL,
    to_phone VARCHAR(20) NOT NULL,
    params TEXT NOT NULL,
    body TEXT NOT NULL,
    status ENUM('queued','sent','delivered','read','failed','skipped','cancelled') NOT NULL DEFAULT 'queued',
    scheduled_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    provider VARCHAR(20) NULL,
    provider_message_id VARCHAR(128) NULL,
    sent_at DATETIME NULL,
    delivered_at DATETIME NULL,
    read_at DATETIME NULL,
    dedupe_key VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_messages_dedupe (dedupe_key),
    UNIQUE KEY uq_messages_provider_id (provider_message_id),
    KEY idx_messages_due (status, scheduled_at),
    KEY idx_messages_booking (booking_id),
    KEY idx_messages_client (client_id),
    CONSTRAINT fk_messages_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_messages_template FOREIGN KEY (template_key) REFERENCES message_templates(template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    professional_id INT UNSIGNED NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    display_name VARCHAR(60) NOT NULL,
    status ENUM('pending','approved','hidden') NOT NULL DEFAULT 'pending',
    moderated_by INT UNSIGNED NULL,
    moderated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reviews_booking (booking_id),
    KEY idx_reviews_status (status, created_at),
    CONSTRAINT fk_reviews_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_professional FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE SET NULL,
    CONSTRAINT fk_reviews_user FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pagamento online (checkout do gateway). external_reference liga a notificação à reserva.
CREATE TABLE payment_intents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    provider VARCHAR(20) NOT NULL,
    external_reference CHAR(32) NOT NULL,
    preference_id VARCHAR(128) NULL,
    checkout_url VARCHAR(500) NULL,
    amount_cents INT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_intents_ref (external_reference),
    KEY idx_intents_booking (booking_id),
    CONSTRAINT fk_intents_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotência: o mesmo pagamento do gateway nunca é registrado duas vezes.
ALTER TABLE booking_payments
    ADD external_id VARCHAR(100) NULL AFTER note,
    ADD UNIQUE KEY uq_payments_external (external_id);

INSERT INTO message_templates (template_key, label, category, name, param_order, preview, offset_hours) VALUES
('booking_confirmed', 'Confirmação da reserva', 'transactional', 'confirmacao_reserva', 'nome,servico,data,hora,local,link',
 'Olá, {{nome}}! Sua reserva de {{servico}} está confirmada para {{data}} às {{hora}} ({{local}}). Detalhes: {{link}}', 0),
('reminder', 'Lembrete', 'transactional', 'lembrete_atendimento', 'nome,servico,data,hora,local',
 'Oi, {{nome}}! Lembrete: seu atendimento de {{servico}} é em {{data}} às {{hora}} ({{local}}). Até lá!', 24),
('pre_care', 'Orientações pré-atendimento', 'transactional', 'orientacoes_pre_atendimento', 'nome,data',
 'Oi, {{nome}}! Para o seu atendimento em {{data}}: venha com o rosto limpo e hidratado, sem maquiagem, e avise se tiver alergias.', 48),
('thanks_review', 'Agradecimento e pedido de avaliação', 'transactional', 'agradecimento_avaliacao', 'nome,link_avaliacao,link_indicacao',
 'Obrigada pela confiança, {{nome}}! Conta como foi? Avalie em {{link_avaliacao}}. Indique amigas pelo seu link: {{link_indicacao}}', 3),
('booking_cancelled', 'Aviso de cancelamento', 'transactional', 'reserva_cancelada', 'nome,servico,data,hora',
 'Olá, {{nome}}. Sua reserva de {{servico}} em {{data}} às {{hora}} foi cancelada. Qualquer dúvida, responda esta mensagem.', 0),
('campaign_offer', 'Campanha: oferta', 'marketing', 'campanha_oferta', 'nome,oferta,link',
 'Oi, {{nome}}! {{oferta}} Agende: {{link}} (Para não receber mais ofertas, responda SAIR.)', 0),
('campaign_birthday', 'Campanha: aniversário', 'marketing', 'campanha_aniversario', 'nome,oferta,link',
 'Feliz aniversário, {{nome}}! 🎉 {{oferta}} Agende: {{link}} (Para não receber mais ofertas, responda SAIR.)', 0);

INSERT INTO settings (setting_key, setting_value) VALUES
('messaging_enabled', '1'),
('reviews_auto_approve', '0'),
('referral_reward_text', 'Indique uma amiga: quando ela fizer o primeiro atendimento, você ganha 10% de desconto no próximo.'),
('pix_key', ''),
('pix_holder', ''),
('online_payment_enabled', '1');
