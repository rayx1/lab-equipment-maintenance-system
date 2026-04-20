-- Lab Equipment Maintenance System
-- MySQL 8+ direct import file

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS user_tokens;
DROP TABLE IF EXISTS service_history;
DROP TABLE IF EXISTS repair_status_updates;
DROP TABLE IF EXISTS technician_assignments;
DROP TABLE IF EXISTS maintenance_requests;
DROP TABLE IF EXISTS attachments;
DROP TABLE IF EXISTS fault_reports;
DROP TABLE IF EXISTS equipment;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id CHAR(36) NOT NULL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash CHAR(64) NOT NULL,
  role ENUM('LAB_USER','TECHNICIAN','LAB_MANAGER','ADMIN') NOT NULL,
  specialization VARCHAR(190) NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE equipment (
  id CHAR(36) NOT NULL PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  model VARCHAR(190) NOT NULL,
  serial_number VARCHAR(190) NOT NULL UNIQUE,
  category VARCHAR(190) NOT NULL,
  location VARCHAR(190) NOT NULL,
  purchase_date DATE NULL,
  status ENUM('OPERATIONAL','UNDER_MAINTENANCE','FAULTY','DECOMMISSIONED') NOT NULL DEFAULT 'OPERATIONAL',
  next_service_date DATETIME NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fault_reports (
  id CHAR(36) NOT NULL PRIMARY KEY,
  equipment_id CHAR(36) NOT NULL,
  reported_by CHAR(36) NOT NULL,
  description TEXT NOT NULL,
  severity ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
  status ENUM('OPEN','ACKNOWLEDGED','RESOLVED') NOT NULL DEFAULT 'OPEN',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fault_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id),
  CONSTRAINT fk_fault_reported_by FOREIGN KEY (reported_by) REFERENCES users(id),
  INDEX idx_fault_equipment (equipment_id),
  INDEX idx_fault_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attachments (
  id CHAR(36) NOT NULL PRIMARY KEY,
  fault_report_id CHAR(36) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attach_fault FOREIGN KEY (fault_report_id) REFERENCES fault_reports(id) ON DELETE CASCADE,
  INDEX idx_attach_fault (fault_report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maintenance_requests (
  id CHAR(36) NOT NULL PRIMARY KEY,
  equipment_id CHAR(36) NOT NULL,
  fault_report_id CHAR(36) NULL,
  request_type ENUM('PREVENTIVE','CORRECTIVE','EMERGENCY') NOT NULL,
  priority ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
  description TEXT NOT NULL,
  requested_by CHAR(36) NOT NULL,
  due_date DATETIME NOT NULL,
  status ENUM('OPEN','IN_PROGRESS','PENDING_PARTS','RESOLVED','CLOSED') NOT NULL DEFAULT 'OPEN',
  sla_deadline DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_maint_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id),
  CONSTRAINT fk_maint_fault FOREIGN KEY (fault_report_id) REFERENCES fault_reports(id),
  CONSTRAINT fk_maint_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
  INDEX idx_maint_equipment (equipment_id),
  INDEX idx_maint_status (status),
  INDEX idx_maint_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE technician_assignments (
  id CHAR(36) NOT NULL PRIMARY KEY,
  maintenance_request_id CHAR(36) NOT NULL,
  technician_id CHAR(36) NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes TEXT NULL,
  CONSTRAINT fk_assign_request FOREIGN KEY (maintenance_request_id) REFERENCES maintenance_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_technician FOREIGN KEY (technician_id) REFERENCES users(id),
  INDEX idx_assign_request (maintenance_request_id),
  INDEX idx_assign_technician (technician_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE repair_status_updates (
  id CHAR(36) NOT NULL PRIMARY KEY,
  maintenance_request_id CHAR(36) NOT NULL,
  updated_by CHAR(36) NOT NULL,
  status ENUM('OPEN','IN_PROGRESS','PENDING_PARTS','RESOLVED','CLOSED') NOT NULL,
  notes TEXT NULL,
  estimated_completion DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_status_request FOREIGN KEY (maintenance_request_id) REFERENCES maintenance_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_status_user FOREIGN KEY (updated_by) REFERENCES users(id),
  INDEX idx_status_request (maintenance_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE service_history (
  id CHAR(36) NOT NULL PRIMARY KEY,
  equipment_id CHAR(36) NOT NULL,
  maintenance_request_id CHAR(36) NULL UNIQUE,
  technician_id CHAR(36) NOT NULL,
  work_performed TEXT NOT NULL,
  parts_replaced TEXT NULL,
  cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  outcome VARCHAR(255) NOT NULL,
  service_date DATETIME NOT NULL,
  next_service_date DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hist_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id),
  CONSTRAINT fk_hist_request FOREIGN KEY (maintenance_request_id) REFERENCES maintenance_requests(id),
  CONSTRAINT fk_hist_technician FOREIGN KEY (technician_id) REFERENCES users(id),
  INDEX idx_hist_equipment (equipment_id),
  INDEX idx_hist_date (service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_tokens (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  token CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_token_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id CHAR(36) NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Password for seeded users: Password@123
-- SHA256: ff7bd97b1a7789ddd2775122fd6817f3173672da9f802ceec57f284325bf589f

INSERT INTO users (id, name, email, password_hash, role, specialization, is_available) VALUES
('11111111-1111-1111-1111-111111111111', 'Lab Manager', 'manager@lab.local', 'ff7bd97b1a7789ddd2775122fd6817f3173672da9f802ceec57f284325bf589f', 'LAB_MANAGER', NULL, 1),
('22222222-2222-2222-2222-222222222222', 'Technician One', 'tech1@lab.local', 'ff7bd97b1a7789ddd2775122fd6817f3173672da9f802ceec57f284325bf589f', 'TECHNICIAN', 'Centrifuges', 1),
('33333333-3333-3333-3333-333333333333', 'Lab User', 'user@lab.local', 'ff7bd97b1a7789ddd2775122fd6817f3173672da9f802ceec57f284325bf589f', 'LAB_USER', NULL, 1),
('44444444-4444-4444-4444-444444444444', 'System Admin', 'admin@lab.local', 'ff7bd97b1a7789ddd2775122fd6817f3173672da9f802ceec57f284325bf589f', 'ADMIN', NULL, 1);

INSERT INTO equipment (id, name, model, serial_number, category, location, purchase_date, status, next_service_date, archived) VALUES
('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa1', 'Ultra Centrifuge', 'UC-900', 'SN-UC-0001', 'Centrifuge', 'Lab A', '2023-04-15', 'OPERATIONAL', DATE_ADD(NOW(), INTERVAL 20 DAY), 0),
('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa2', 'PCR Machine', 'PCR-X2', 'SN-PCR-0012', 'PCR', 'Lab B', '2022-09-10', 'UNDER_MAINTENANCE', DATE_ADD(NOW(), INTERVAL 10 DAY), 0),
('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa3', 'Spectrophotometer', 'SP-420', 'SN-SP-0099', 'Spectroscopy', 'Lab C', '2021-11-03', 'FAULTY', DATE_ADD(NOW(), INTERVAL 35 DAY), 0);

INSERT INTO fault_reports (id, equipment_id, reported_by, description, severity, status, created_at) VALUES
('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbb1', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa3', '33333333-3333-3333-3333-333333333333', 'Output readings fluctuate unpredictably during calibration.', 'HIGH', 'OPEN', DATE_SUB(NOW(), INTERVAL 2 DAY));

INSERT INTO maintenance_requests (id, equipment_id, fault_report_id, request_type, priority, description, requested_by, due_date, status, sla_deadline, created_at, updated_at) VALUES
('cccccccc-cccc-cccc-cccc-ccccccccccc1', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaa3', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbb1', 'CORRECTIVE', 'HIGH', 'Inspect optics assembly and recalibrate sensor module.', '11111111-1111-1111-1111-111111111111', DATE_ADD(NOW(), INTERVAL 1 DAY), 'IN_PROGRESS', DATE_ADD(NOW(), INTERVAL 1 DAY), NOW(), NOW());

INSERT INTO technician_assignments (id, maintenance_request_id, technician_id, assigned_at, notes) VALUES
('dddddddd-dddd-dddd-dddd-ddddddddddd1', 'cccccccc-cccc-cccc-cccc-ccccccccccc1', '22222222-2222-2222-2222-222222222222', NOW(), 'Prioritize before next scheduled assay batch.');

INSERT INTO repair_status_updates (id, maintenance_request_id, updated_by, status, notes, estimated_completion, created_at) VALUES
('eeeeeeee-eeee-eeee-eeee-eeeeeeeeeee1', 'cccccccc-cccc-cccc-cccc-ccccccccccc1', '22222222-2222-2222-2222-222222222222', 'IN_PROGRESS', 'Diagnostic started; waiting for internal lamp test.', DATE_ADD(NOW(), INTERVAL 8 HOUR), NOW());

SET FOREIGN_KEY_CHECKS = 1;

