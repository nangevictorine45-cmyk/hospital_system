-- =============================================================================
-- CityCare Hospital - Appointment & Queue Management System
-- Database Schema Definition & Initial Seed Data
-- =============================================================================
-- Database Name: hospital_system
-- Supported Engines: MariaDB 10.4+ / MySQL 8.0+
-- Character Set: utf8mb4 / Collation: utf8mb4_unicode_ci
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `hospital_system`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `hospital_system`;

-- Disable foreign key checks during schema creation/resets
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. Table: users
-- Core authentication and identity table for patients, clinic staff, and admins.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `google_id` VARCHAR(100) NULL,
  `phone` VARCHAR(25) NULL,
  `password_hash` VARCHAR(255) NULL,
  `auth_provider` VARCHAR(20) NOT NULL DEFAULT 'local',
  `avatar_url` VARCHAR(255) NULL,
  `role` ENUM('patient', 'staff', 'admin') NOT NULL DEFAULT 'patient',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  UNIQUE KEY `uk_users_google_id` (`google_id`),
  KEY `idx_users_role_status` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Table: patient_profiles
-- Extended demographic and emergency information for patient accounts.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `patient_profiles`;
CREATE TABLE `patient_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `date_of_birth` DATE NULL,
  `gender` ENUM('male', 'female', 'other') NULL,
  `address` TEXT NULL,
  `emergency_contact_name` VARCHAR(100) NULL,
  `emergency_contact_phone` VARCHAR(25) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_patient_profiles_user` (`user_id`),
  CONSTRAINT `fk_patient_profiles_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Table: doctors
-- Medical practitioners and specialists available for patient booking.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `doctors`;
CREATE TABLE `doctors` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(100) NOT NULL,
  `specialty` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(25) NOT NULL,
  `room_number` VARCHAR(30) NOT NULL,
  `description` TEXT NULL,
  `active_status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doctors_email` (`email`),
  KEY `idx_doctors_specialty` (`specialty`),
  KEY `idx_doctors_active` (`active_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Table: doctor_schedules
-- Weekly working shifts and consultation slot parameters for doctors.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `doctor_schedules`;
CREATE TABLE `doctor_schedules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doctor_id` INT UNSIGNED NOT NULL,
  `day_of_week` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `slot_duration_minutes` INT UNSIGNED NOT NULL DEFAULT 20,
  `active_status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doctor_schedules_lookup` (`doctor_id`, `day_of_week`, `active_status`),
  CONSTRAINT `fk_doctor_schedules_doctor`
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Table: appointments
-- Scheduled consultations between a patient and a doctor.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `appointments`;
CREATE TABLE `appointments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_reference` VARCHAR(20) NOT NULL,
  `patient_id` INT UNSIGNED NOT NULL,
  `doctor_id` INT UNSIGNED NOT NULL,
  `appointment_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `status` ENUM('pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_appointments_booking_ref` (`booking_reference`),
  KEY `idx_doctor_appointment_slot` (`doctor_id`, `appointment_date`, `start_time`),
  KEY `idx_patient_appointment` (`patient_id`, `appointment_date`),
  KEY `idx_appointments_status` (`status`),
  CONSTRAINT `fk_appointments_patient`
    FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_appointments_doctor`
    FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Table: queue_entries
-- Physical clinic queue tickets issued when an appointment is checked in.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `queue_entries`;
CREATE TABLE `queue_entries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `appointment_id` INT UNSIGNED NOT NULL,
  `queue_date` DATE NOT NULL,
  `queue_number` VARCHAR(10) NOT NULL,
  `status` ENUM('waiting', 'in_service', 'completed', 'skipped', 'cancelled') NOT NULL DEFAULT 'waiting',
  `checked_in_at` DATETIME NULL,
  `called_at` DATETIME NULL,
  `service_started_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_queue_appointment` (`appointment_id`),
  KEY `idx_queue_daily` (`queue_date`, `status`),
  KEY `idx_queue_daily_number` (`queue_date`, `queue_number`),
  CONSTRAINT `fk_queue_entries_appointment`
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. Table: clinic_settings
-- System-wide business operational rules and clinic contact information.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `clinic_settings`;
CREATE TABLE `clinic_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `clinic_name` VARCHAR(150) NOT NULL,
  `address` TEXT NOT NULL,
  `phone` VARCHAR(25) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `timezone` VARCHAR(50) NOT NULL DEFAULT 'UTC',
  `opening_time` TIME NOT NULL DEFAULT '08:00:00',
  `closing_time` TIME NOT NULL DEFAULT '17:00:00',
  `max_advance_booking_days` INT UNSIGNED NOT NULL DEFAULT 30,
  `min_cancellation_hours_notice` INT UNSIGNED NOT NULL DEFAULT 2,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. Table: audit_logs
-- Immutable trail of security events, administrative changes, and status shifts.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` INT UNSIGNED NULL,
  `action` VARCHAR(50) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `description` TEXT NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_action` (`action`, `created_at`),
  KEY `idx_audit_logs_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_logs_actor` (`actor_user_id`),
  CONSTRAINT `fk_audit_logs_actor`
    FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. Table: patient_messages
-- In-app messaging, automated missed appointment notifications, and reschedule offers.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `patient_messages`;
CREATE TABLE `patient_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_id` INT UNSIGNED NOT NULL,
  `appointment_id` INT UNSIGNED NULL,
  `sender_role` VARCHAR(20) NOT NULL DEFAULT 'system',
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `message_type` ENUM('missed_appointment', 'reschedule_offer', 'reminder', 'general') NOT NULL DEFAULT 'general',
  `suggested_slots_json` TEXT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `is_resolved` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_patient_messages_user` (`patient_id`, `is_read`),
  KEY `idx_patient_messages_app` (`appointment_id`),
  CONSTRAINT `fk_patient_messages_user`
    FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_patient_messages_appointment`
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;


-- =============================================================================
-- SEED DATA
-- Default starting data for clinic configuration, staff/patient users, & doctors.
-- Default password for seeded accounts: Password123!
-- =============================================================================

-- 1. Default Clinic Settings
INSERT INTO `clinic_settings` (
  `id`, `clinic_name`, `address`, `phone`, `email`, `timezone`,
  `opening_time`, `closing_time`, `max_advance_booking_days`, `min_cancellation_hours_notice`
) VALUES (
  1,
  'CityCare Hospital',
  '123 Healthcare Boulevard, Medical District, Metro City',
  '+1 (555) 019-2834',
  'info@citycare.local',
  'UTC',
  '08:00:00',
  '17:00:00',
  30,
  2
) ON DUPLICATE KEY UPDATE `clinic_name` = VALUES(`clinic_name`);

-- 2. Initial Sample Accounts (Admin, Staff, Patient)
-- Password hash generated using PHP password_hash('Password123!', PASSWORD_BCRYPT)
INSERT INTO `users` (`id`, `full_name`, `email`, `phone`, `password_hash`, `role`, `status`) VALUES
(1, 'System Administrator', 'admin@citycare.local', '+1 (555) 000-0001', '$2y$10$Fmj0EyspVYp49jhg/15PPupZrMqAKvX0Jwg1UjkwKy61GTnW5OqIm', 'admin', 'active'),
(2, 'Clinic Receptionist', 'staff@citycare.local', '+1 (555) 000-0002', '$2y$10$Fmj0EyspVYp49jhg/15PPupZrMqAKvX0Jwg1UjkwKy61GTnW5OqIm', 'staff', 'active'),
(3, 'John Doe', 'patient@citycare.local', '+1 (555) 000-0003', '$2y$10$Fmj0EyspVYp49jhg/15PPupZrMqAKvX0Jwg1UjkwKy61GTnW5OqIm', 'patient', 'active')
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);

-- 3. Sample Patient Profile (for John Doe, user_id = 3)
INSERT INTO `patient_profiles` (
  `id`, `user_id`, `date_of_birth`, `gender`, `address`,
  `emergency_contact_name`, `emergency_contact_phone`
) VALUES (
  1,
  3,
  '1990-05-15',
  'male',
  '456 Maple Avenue, Apt 3B, Metro City',
  'Jane Doe',
  '+1 (555) 000-0099'
) ON DUPLICATE KEY UPDATE `emergency_contact_name` = VALUES(`emergency_contact_name`);

-- 4. Sample Doctors across specialties
INSERT INTO `doctors` (`id`, `full_name`, `specialty`, `email`, `phone`, `room_number`, `description`, `active_status`) VALUES
(1, 'Dr. Sarah Jenkins', 'General Medicine', 's.jenkins@citycare.local', '+1 (555) 101-0001', 'Room 101', 'Board-certified in Family and General Medicine with 12 years of experience in primary care and preventative health.', 1),
(2, 'Dr. Michael Chang', 'Pediatrics', 'm.chang@citycare.local', '+1 (555) 102-0002', 'Room 102', 'Specialist pediatrician focusing on infant growth monitoring, adolescent wellness, and childhood immunizations.', 1),
(3, 'Dr. Elena Rodriguez', 'Cardiology', 'e.rodriguez@citycare.local', '+1 (555) 201-0003', 'Room 201', 'Consultant cardiologist specializing in cardiovascular diagnostics, hypertension management, and heart health counseling.', 1)
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);

-- 5. Doctor Weekly Schedules (realistic shifts with 20-minute consultation slots)
INSERT INTO `doctor_schedules` (`doctor_id`, `day_of_week`, `start_time`, `end_time`, `slot_duration_minutes`, `active_status`) VALUES
-- Dr. Sarah Jenkins (General Medicine) - Monday, Wednesday, Friday
(1, 'Monday', '09:00:00', '13:00:00', 20, 1),
(1, 'Monday', '14:00:00', '17:00:00', 20, 1),
(1, 'Wednesday', '09:00:00', '13:00:00', 20, 1),
(1, 'Wednesday', '14:00:00', '17:00:00', 20, 1),
(1, 'Friday', '09:00:00', '13:00:00', 20, 1),

-- Dr. Michael Chang (Pediatrics) - Tuesday, Thursday
(2, 'Tuesday', '09:00:00', '13:00:00', 20, 1),
(2, 'Tuesday', '14:00:00', '16:40:00', 20, 1),
(2, 'Thursday', '09:00:00', '13:00:00', 20, 1),
(2, 'Thursday', '14:00:00', '16:40:00', 20, 1),

-- Dr. Elena Rodriguez (Cardiology) - Monday, Wednesday, Friday
(3, 'Monday', '10:00:00', '14:00:00', 20, 1),
(3, 'Wednesday', '10:00:00', '14:00:00', 20, 1),
(3, 'Friday', '10:00:00', '14:00:00', 20, 1);

-- 6. Initial Audit Log Entry
INSERT INTO `audit_logs` (`actor_user_id`, `action`, `entity_type`, `entity_id`, `description`, `ip_address`) VALUES
(1, 'SYSTEM_INIT', 'database', 1, 'Hospital system database initialized with default schema, clinic settings, sample doctors, schedules, and initial user accounts.', '127.0.0.1');

