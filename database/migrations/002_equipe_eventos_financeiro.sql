-- Fase 2: equipe, eventos com várias profissionais, comissões e financeiro.

ALTER TABLE professionals
    ADD commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER color;

-- kind = 'event': produção com várias pessoas/profissionais, duração e valor próprios.
ALTER TABLE bookings
    ADD kind ENUM('service','event') NOT NULL DEFAULT 'service' AFTER public_code,
    ADD event_name VARCHAR(160) NULL AFTER kind,
    ADD people_count SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER event_name;

-- share_percent: parte do valor do atendimento atribuída à profissional (somatório = 100).
-- commission_percent: comissão da profissional no momento da alocação (histórico preservado).
ALTER TABLE booking_allocations
    ADD share_percent DECIMAL(5,2) NOT NULL DEFAULT 100,
    ADD commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0;

CREATE TABLE booking_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    kind ENUM('deposit','balance','other') NOT NULL DEFAULT 'balance',
    amount_cents INT UNSIGNED NOT NULL,
    method ENUM('pix','dinheiro','cartao_credito','cartao_debito','transferencia','outro') NOT NULL DEFAULT 'pix',
    paid_on DATE NOT NULL,
    note VARCHAR(190) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payments_booking (booking_id),
    KEY idx_payments_date (paid_on),
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Despesas gerais (booking_id NULL) ou de um atendimento específico (material, transporte, assistente...).
CREATE TABLE expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NULL,
    category VARCHAR(40) NOT NULL,
    description VARCHAR(190) NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    spent_on DATE NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_expenses_date (spent_on),
    KEY idx_expenses_booking (booking_id),
    CONSTRAINT fk_expenses_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_expenses_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
