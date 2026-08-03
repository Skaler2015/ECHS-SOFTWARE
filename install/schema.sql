-- ============================================================
--  Noble Health Software  (RGHS + ECHS)
--  Database schema
--  Import this file in phpMyAdmin, OR use install/index.php
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ---------- Users (login) ----------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `username`      VARCHAR(60)  NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name`     VARCHAR(120) NOT NULL,
    `role`          VARCHAR(20)  NOT NULL DEFAULT 'admin',
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Patients / Beneficiaries ----------
CREATE TABLE IF NOT EXISTS `patients` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `scheme`        ENUM('RGHS','ECHS') NOT NULL,
    `card_no`       VARCHAR(60)  NOT NULL,
    `name`          VARCHAR(120) NOT NULL,
    `relation`      VARCHAR(40)  DEFAULT NULL,   -- Self / Spouse / Son / Daughter etc.
    `holder_name`   VARCHAR(120) DEFAULT NULL,   -- main card holder name
    `age`           INT          DEFAULT NULL,
    `gender`        ENUM('Male','Female','Other') DEFAULT NULL,
    `phone`         VARCHAR(20)  DEFAULT NULL,
    `address`       VARCHAR(255) DEFAULT NULL,
    -- scheme specific
    `service_no`    VARCHAR(60)  DEFAULT NULL,   -- ECHS service no / RGHS employee id
    `rank_desig`    VARCHAR(80)  DEFAULT NULL,   -- rank (ECHS) / designation (RGHS)
    `category`      VARCHAR(40)  DEFAULT NULL,   -- Pensioner / Serving / Employee etc.
    `notes`         TEXT         DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_scheme`  (`scheme`),
    KEY `idx_card`    (`card_no`),
    KEY `idx_name`    (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Medicine master (for quick entry / autocomplete) ----------
CREATE TABLE IF NOT EXISTS `medicines` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(160) NOT NULL,
    `unit`       VARCHAR(30)  DEFAULT NULL,   -- Tab / Cap / Syrup / Inj etc.
    `hsn`        VARCHAR(20)  DEFAULT NULL,
    `mrp`        DECIMAL(10,2) DEFAULT 0,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_med_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Bills / Claims ----------
CREATE TABLE IF NOT EXISTS `bills` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `scheme`           ENUM('RGHS','ECHS') NOT NULL,
    `bill_no`          VARCHAR(40)  NOT NULL,
    `patient_id`       INT          DEFAULT NULL,
    `patient_name`     VARCHAR(120) NOT NULL,   -- snapshot at time of bill
    `card_no`          VARCHAR(60)  DEFAULT NULL,
    `doctor_name`      VARCHAR(120) DEFAULT NULL,
    `hospital`         VARCHAR(160) DEFAULT NULL,
    `prescription_date` DATE        DEFAULT NULL,
    `bill_date`        DATE         NOT NULL,
    `referral_no`      VARCHAR(60)  DEFAULT NULL,
    `sub_total`        DECIMAL(12,2) NOT NULL DEFAULT 0,
    `discount`         DECIMAL(12,2) NOT NULL DEFAULT 0,
    `total_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status`           ENUM('Pending','Submitted','Paid','Rejected') NOT NULL DEFAULT 'Pending',
    `remarks`          TEXT         DEFAULT NULL,
    `created_by`       INT          DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_b_scheme` (`scheme`),
    KEY `idx_b_status` (`status`),
    KEY `idx_b_date`   (`bill_date`),
    KEY `idx_b_patient`(`patient_id`),
    UNIQUE KEY `uq_scheme_billno` (`scheme`,`bill_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Bill line items (medicines dispensed) ----------
CREATE TABLE IF NOT EXISTS `bill_items` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `bill_id`    INT NOT NULL,
    `medicine`   VARCHAR(160) NOT NULL,
    `batch`      VARCHAR(50)  DEFAULT NULL,
    `expiry`     VARCHAR(10)  DEFAULT NULL,
    `qty`        DECIMAL(10,2) NOT NULL DEFAULT 1,
    `rate`       DECIMAL(10,2) NOT NULL DEFAULT 0,
    `amount`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    KEY `idx_bi_bill` (`bill_id`),
    CONSTRAINT `fk_bi_bill` FOREIGN KEY (`bill_id`) REFERENCES `bills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- App settings (org name, header for bills etc.) ----------
CREATE TABLE IF NOT EXISTS `settings` (
    `skey`   VARCHAR(60) PRIMARY KEY,
    `svalue` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`) VALUES
    ('org_name',    'Noble Medical Store'),
    ('org_address', 'Rajasthan, India'),
    ('org_phone',   ''),
    ('org_gstin',   ''),
    ('org_dl_no',   '');

SET foreign_key_checks = 1;
