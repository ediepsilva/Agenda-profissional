-- Fase 1: estrutura base, já preparada para múltiplas profissionais.
-- Valores monetários em centavos (INT). Datas/horas em horário local (America/Sao_Paulo).

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner','manager','artist','assistant') NOT NULL DEFAULT 'artist',
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_email (email, attempted_at),
    KEY idx_login_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE professionals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    bio TEXT NULL,
    color CHAR(7) NOT NULL DEFAULT '#b0677a',
    accepts_online_booking TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_professionals_user (user_id),
    CONSTRAINT fk_professionals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    category VARCHAR(80) NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    deposit_cents INT UNSIGNED NULL,
    location_mode ENUM('studio','client','both') NOT NULL DEFAULT 'both',
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE professional_services (
    professional_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (professional_id, service_id),
    CONSTRAINT fk_ps_professional FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Área atendida: define o tempo de deslocamento (ida e volta, cada trecho) e taxa.
-- professional_id NULL = vale para todas as profissionais.
CREATE TABLE service_areas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    professional_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    travel_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    travel_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_areas_professional FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Janelas semanais de atendimento. weekday: 0 = domingo ... 6 = sábado.
CREATE TABLE availability_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    professional_id INT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    KEY idx_rules_prof_day (professional_id, weekday),
    CONSTRAINT fk_rules_professional FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bloqueios pontuais (folga, compromisso pessoal, viagem...).
CREATE TABLE schedule_blocks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    professional_id INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    reason VARCHAR(190) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_blocks_prof_time (professional_id, starts_at, ends_at),
    CONSTRAINT fk_blocks_professional FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE,
    CONSTRAINT fk_blocks_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clientes: apenas dados úteis ao atendimento (minimização de dados / LGPD).
CREATE TABLE clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(190) NULL,
    instagram VARCHAR(60) NULL,
    birth_date DATE NULL,
    source VARCHAR(40) NULL,
    preferences TEXT NULL,
    notes TEXT NULL,
    privacy_accepted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clients_phone (phone),
    KEY idx_clients_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_code CHAR(24) NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    professional_id INT UNSIGNED NOT NULL,
    status ENUM('requested','awaiting_deposit','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'requested',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    location_type ENUM('studio','client') NOT NULL DEFAULT 'studio',
    service_area_id INT UNSIGNED NULL,
    address VARCHAR(255) NULL,
    travel_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    travel_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
    deposit_cents INT UNSIGNED NOT NULL DEFAULT 0,
    client_notes TEXT NULL,
    internal_notes TEXT NULL,
    channel ENUM('public','admin') NOT NULL DEFAULT 'public',
    cancel_reason VARCHAR(255) NULL,
    confirmed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bookings_code (public_code),
    KEY idx_bookings_start (starts_at),
    KEY idx_bookings_status (status),
    CONSTRAINT fk_bookings_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_bookings_professional FOREIGN KEY (professional_id) REFERENCES professionals(id),
    CONSTRAINT fk_bookings_area FOREIGN KEY (service_area_id) REFERENCES service_areas(id) ON DELETE SET NULL,
    CONSTRAINT fk_bookings_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tempo efetivamente ocupado de cada profissional por reserva
-- (inclui deslocamento e intervalo). Um evento poderá ter várias linhas (Fase 2).
CREATE TABLE booking_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    professional_id INT UNSIGNED NOT NULL,
    role ENUM('lead','support') NOT NULL DEFAULT 'lead',
    block_start DATETIME NOT NULL,
    block_end DATETIME NOT NULL,
    UNIQUE KEY uq_alloc_booking_prof (booking_id, professional_id),
    KEY idx_alloc_prof_time (professional_id, block_start, block_end),
    CONSTRAINT fk_alloc_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_alloc_professional FOREIGN KEY (professional_id) REFERENCES professionals(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    changed_by INT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_history_booking (booking_id),
    CONSTRAINT fk_history_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Controle simples de abuso do formulário público.
CREATE TABLE rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(60) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rate (bucket, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
('business_name', 'Studio de Maquiagem'),
('tagline', 'Maquiagem artística para noivas, eventos e produções'),
('about', 'Maquiagem pensada para realçar a sua beleza e durar o dia todo.'),
('city', ''),
('studio_address', ''),
('whatsapp', ''),
('instagram', ''),
('service_area_text', ''),
('min_advance_hours', '24'),
('max_advance_days', '90'),
('slot_step_minutes', '30'),
('buffer_minutes', '30'),
('hold_pending_requests', '1'),
('deposit_percent', '30'),
('cancellation_min_hours', '48'),
('reschedule_min_hours', '48'),
('deposit_policy', 'O horário é garantido após o pagamento do sinal.'),
('cancellation_policy', 'Cancelamentos com menos de 48 horas de antecedência não têm o sinal devolvido.'),
('reschedule_policy', 'Reagendamentos podem ser feitos com até 48 horas de antecedência, conforme disponibilidade.');
