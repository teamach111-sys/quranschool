-- Fresh-install bootstrap for MySQL 8.0.16+ (CHECK constraints are enforced).
-- Seeds only the roles, school, and starter login accounts.
--
-- v2 notes (fixes over v1):
--  * Every table now carries school_id and every user/room/year reference is
--    school-scoped, so no row can attach a person or resource from another school.
--  * Users are deactivated with is_active, never hard-deleted (all references
--    are ON DELETE RESTRICT), which is why recorded_by/teacher_id no longer use
--    ON DELETE SET NULL — a SET NULL composite FK would also null school_id.
--  * rooms gained updated_at, consistent with the other mutable tables.
--  * memorization_sessions: surah/ayah values are now range-checked as a pair,
--    with the documented convention for sessions that end at a surah boundary.
--  * Unused unique keys (id, year_id) that only existed to be FK targets were
--    removed; all FKs now target the school-scoped (id, school_id, year_id) keys.
CREATE DATABASE IF NOT EXISTS quranschool
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE quranschool;

-- Destructive reset: running this script drops all existing application data.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS cache_locks;
DROP TABLE IF EXISTS cache;
DROP TABLE IF EXISTS audit_events;
DROP TABLE IF EXISTS role_change_history;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS memorization_sessions;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS schedules;
DROP TABLE IF EXISTS room_teachers;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS academic_years;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS schools;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Reference data
-- ---------------------------------------------------------------------------
CREATE TABLE schools (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name_fr VARCHAR(150) NOT NULL,
  name_ar VARCHAR(150) NOT NULL,
  address VARCHAR(255),
  phone VARCHAR(30),
  email VARCHAR(190),
  currency CHAR(3) NOT NULL DEFAULT 'MAD',
  monthly_fee DECIMAL(10,2),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE roles (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(24) NOT NULL UNIQUE,
  name_fr VARCHAR(50) NOT NULL,
  name_ar VARCHAR(50) NOT NULL
) ENGINE=InnoDB;

-- Academic years drive the persistent year selector. All year-scoped data
-- references a row here, so switching the selector filters every dependent
-- table by year_id.
CREATE TABLE academic_years (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  year_label VARCHAR(20) NOT NULL COMMENT 'e.g. 2026-2027',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_current BOOLEAN NOT NULL DEFAULT FALSE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  current_school_id BIGINT UNSIGNED GENERATED ALWAYS AS
    (CASE WHEN is_current THEN school_id ELSE NULL END) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ay_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_school_year_label (school_id,year_label),
  -- Referenced by the (year_id,school_id) FKs below; not redundant.
  UNIQUE KEY uq_ay_id_school (id,school_id),
  UNIQUE KEY uq_one_current_year_per_school (current_school_id),
  INDEX idx_ay_current (school_id,is_current),
  CHECK (end_date >= start_date),
  CHECK (is_current = FALSE OR is_active = TRUE)
) ENGINE=InnoDB;

-- Users are soft-deactivated (is_active) rather than deleted, so every table
-- that stores who did what can reference them with ON DELETE RESTRICT.
-- ---------------------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------------------
CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  role_id TINYINT UNSIGNED NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  phone VARCHAR(30),
  language ENUM('fr','ar') NOT NULL DEFAULT 'fr' COMMENT 'persistent UI language',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  INDEX idx_users_school_role (school_id,role_id),
  INDEX idx_users_role (role_id),
  -- Referenced by the (…,school_id) FKs below; not redundant.
  UNIQUE KEY uq_users_id_school (id,school_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Rooms / groups (recreated per academic year)
-- ---------------------------------------------------------------------------
CREATE TABLE rooms (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  year_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  level ENUM('beginner','intermediate','advanced') NOT NULL DEFAULT 'beginner',
  age_min TINYINT UNSIGNED,
  age_max TINYINT UNSIGNED,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rooms_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rooms_year FOREIGN KEY (year_id,school_id) REFERENCES academic_years(id,school_id) ON DELETE RESTRICT,
  UNIQUE KEY uq_room_id_school_year (id,school_id,year_id),
  INDEX idx_rooms_year_active (year_id,is_active),
  INDEX idx_rooms_school_active (school_id,is_active),
  CHECK (age_min IS NULL OR age_max IS NULL OR age_min <= age_max),
  CHECK (capacity > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Students (enrollment is per academic year)
-- ---------------------------------------------------------------------------
-- One row per student per year: a returning student gets a new row the year
-- they re-enroll. student_number is optional and app-managed (MySQL unique
-- keys allow multiple NULLs, so blanks don't collide).
CREATE TABLE students (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  year_id BIGINT UNSIGNED NOT NULL,
  student_number VARCHAR(30),
  full_name VARCHAR(120) NOT NULL,
  birth_date DATE,
  guardian_name VARCHAR(120),
  guardian_phone VARCHAR(30),
  guardian_email VARCHAR(190),
  room_id BIGINT UNSIGNED,
  enrollment_date DATE NOT NULL,
  status ENUM('active','paused','withdrawn') NOT NULL DEFAULT 'active',
  notes TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_students_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
  CONSTRAINT fk_students_room FOREIGN KEY (room_id,school_id,year_id) REFERENCES rooms(id,school_id,year_id) ON DELETE RESTRICT,
  CONSTRAINT fk_students_year_school FOREIGN KEY (year_id,school_id) REFERENCES academic_years(id,school_id) ON DELETE RESTRICT,
  UNIQUE KEY uq_student_number_year (year_id,student_number),
  UNIQUE KEY uq_student_id_school_year (id,school_id,year_id),
  INDEX idx_students_year_room_status (year_id,room_id,status),
  INDEX idx_students_year_name (year_id,full_name),
  INDEX idx_students_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Room <-> teacher assignments (history-aware, per year)
-- ---------------------------------------------------------------------------
-- Validate that the assigned user has the teacher role, and reject overlapping
-- assignment date ranges, in application logic within a transaction.
CREATE TABLE room_teachers (
  room_id BIGINT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL,
  school_id BIGINT UNSIGNED NOT NULL,
  year_id BIGINT UNSIGNED NOT NULL,
  assigned_from DATE NOT NULL,
  assigned_until DATE,
  PRIMARY KEY (room_id,teacher_id,assigned_from),
  CONSTRAINT fk_rt_room_year_school FOREIGN KEY (room_id,school_id,year_id) REFERENCES rooms(id,school_id,year_id) ON DELETE RESTRICT,
  CONSTRAINT fk_rt_teacher_school FOREIGN KEY (teacher_id,school_id) REFERENCES users(id,school_id) ON DELETE RESTRICT,
  CONSTRAINT fk_rt_year_school FOREIGN KEY (year_id,school_id) REFERENCES academic_years(id,school_id) ON DELETE RESTRICT,
  INDEX idx_rt_room_school_year (room_id,school_id,year_id),
  INDEX idx_rt_teacher_school (teacher_id,school_id),
  INDEX idx_rt_year_school (year_id,school_id),
  INDEX idx_rt_teacher (teacher_id,assigned_until),
  INDEX idx_rt_year_teacher (year_id,teacher_id),
  CHECK (assigned_until IS NULL OR assigned_until >= assigned_from)
) ENGINE=InnoDB;

-- Find every active teacher and the rooms they teach for the selected year:
-- SELECT u.id, u.full_name, r.id AS room_id, r.name AS room_name
-- FROM users AS u
-- JOIN roles AS ro ON ro.id = u.role_id AND ro.role_key = 'teacher'
-- LEFT JOIN room_teachers AS rt ON rt.teacher_id = u.id AND rt.year_id = :year_id
--   AND (rt.assigned_until IS NULL OR rt.assigned_until >= CURRENT_DATE())
-- LEFT JOIN rooms AS r ON r.id = rt.room_id AND r.is_active = TRUE
-- WHERE u.is_active = TRUE
-- ORDER BY u.full_name, r.name;

-- ---------------------------------------------------------------------------
-- Weekly schedule slots (per year)
-- ---------------------------------------------------------------------------
-- Prevent overlapping room/teacher slots for the same weekday and overlapping
-- validity dates in application logic within a transaction; CHECK constraints
-- cannot safely detect interval overlaps.
CREATE TABLE schedules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id BIGINT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL,
  school_id BIGINT UNSIGNED NOT NULL,
  year_id BIGINT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1=Monday ... 7=Sunday',
  starts_at TIME NOT NULL,
  ends_at TIME NOT NULL,
  valid_from DATE NOT NULL,
  valid_until DATE,
  label VARCHAR(100) NOT NULL DEFAULT 'Hifz',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_schedule_room_year_school FOREIGN KEY (room_id,school_id,year_id) REFERENCES rooms(id,school_id,year_id) ON DELETE RESTRICT,
  CONSTRAINT fk_schedule_teacher_school FOREIGN KEY (teacher_id,school_id) REFERENCES users(id,school_id) ON DELETE RESTRICT,
  CONSTRAINT fk_schedule_year_school FOREIGN KEY (year_id,school_id) REFERENCES academic_years(id,school_id) ON DELETE RESTRICT,
  INDEX idx_schedule_room_school_year (room_id,school_id,year_id),
  INDEX idx_schedule_teacher_school (teacher_id,school_id),
  INDEX idx_schedule_year_school (year_id,school_id),
  INDEX idx_schedule_year_day_time (year_id,day_of_week,starts_at),
  INDEX idx_schedule_room_day (room_id,day_of_week,starts_at),
  INDEX idx_schedule_teacher (teacher_id),
  CHECK (day_of_week BETWEEN 1 AND 7),
  CHECK (ends_at > starts_at),
  CHECK (valid_until IS NULL OR valid_until >= valid_from)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Attendance (one record per student per day)
-- ---------------------------------------------------------------------------
CREATE TABLE attendance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  school_id BIGINT UNSIGNED NOT NULL,
  room_id BIGINT UNSIGNED,
  year_id BIGINT UNSIGNED NOT NULL,
  recorded_by BIGINT UNSIGNED,
  attendance_date DATE NOT NULL,
  status ENUM('present','absent','late','excused') NOT NULL,
  note VARCHAR(500),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_student_school_year FOREIGN KEY (student_id,school_id,year_id) REFERENCES students(id,school_id,year_id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_room_school_year FOREIGN KEY (room_id,school_id,year_id) REFERENCES rooms(id,school_id,year_id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_recorded_by_school FOREIGN KEY (recorded_by,school_id) REFERENCES users(id,school_id) ON DELETE RESTRICT,
  UNIQUE KEY uq_attendance_student_day (student_id,year_id,attendance_date),
  INDEX idx_attendance_room_year (room_id,year_id),
  INDEX idx_attendance_year_date_room (year_id,attendance_date,room_id),
  INDEX idx_attendance_status (status),
  INDEX idx_attendance_student_date (student_id,attendance_date)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Memorization / Hifz sessions (per year)
-- ---------------------------------------------------------------------------
-- Portion convention: a session that ends exactly at the end of a surah is
-- recorded with surah_end = that surah and ayah_end = its last ayah number;
-- portion_label carries the human-readable description (incl. cross-surah
-- ranges such as "fin Sourate 2 -> début Sourate 3").
CREATE TABLE memorization_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  school_id BIGINT UNSIGNED NOT NULL,
  room_id BIGINT UNSIGNED,
  teacher_id BIGINT UNSIGNED,
  year_id BIGINT UNSIGNED NOT NULL,
  session_date DATE NOT NULL,
  surah_start TINYINT UNSIGNED,
  ayah_start SMALLINT UNSIGNED,
  surah_end TINYINT UNSIGNED,
  ayah_end SMALLINT UNSIGNED,
  portion_label VARCHAR(160) NOT NULL,
  evaluation ENUM('excellent','good','needs_review') NOT NULL DEFAULT 'good',
  notes TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mem_student_school_year FOREIGN KEY (student_id,school_id,year_id) REFERENCES students(id,school_id,year_id) ON DELETE RESTRICT,
  CONSTRAINT fk_mem_room_school_year FOREIGN KEY (room_id,school_id,year_id) REFERENCES rooms(id,school_id,year_id) ON DELETE RESTRICT,
  CONSTRAINT fk_mem_teacher_school FOREIGN KEY (teacher_id,school_id) REFERENCES users(id,school_id) ON DELETE RESTRICT,
  INDEX idx_mem_student_year (student_id,year_id),
  INDEX idx_mem_room_year (room_id,year_id),
  INDEX idx_mem_year_student_date (year_id,student_id,session_date),
  INDEX idx_mem_teacher_date (teacher_id,session_date),
  INDEX idx_mem_evaluation (evaluation),
  CHECK (surah_start IS NULL OR surah_start BETWEEN 1 AND 114),
  CHECK (surah_end IS NULL OR surah_end BETWEEN 1 AND 114),
  CHECK (ayah_start IS NULL OR ayah_start BETWEEN 1 AND 286),
  CHECK (ayah_end IS NULL OR ayah_end BETWEEN 1 AND 286),
  CHECK ((surah_start IS NULL) = (ayah_start IS NULL)),
  CHECK ((surah_end IS NULL) = (ayah_end IS NULL)),
  CHECK (surah_start IS NULL OR surah_end IS NULL
         OR surah_start < surah_end
         OR (surah_start = surah_end AND ayah_start <= ayah_end))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Billing / payments (per year)
-- ---------------------------------------------------------------------------
-- Reject overlapping billing periods for the same student in application
-- logic within a transaction; the unique key here only blocks exact duplicates.
CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  school_id BIGINT UNSIGNED NOT NULL,
  year_id BIGINT UNSIGNED NOT NULL,
  recorded_by BIGINT UNSIGNED,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'MAD',
  status ENUM('paid','due','late','waived') NOT NULL DEFAULT 'due',
  paid_at DATETIME,
  payment_method VARCHAR(40),
  reference VARCHAR(100),
  notes VARCHAR(500),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_student_school_year FOREIGN KEY (student_id,school_id,year_id) REFERENCES students(id,school_id,year_id) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_recorded_by_school FOREIGN KEY (recorded_by,school_id) REFERENCES users(id,school_id) ON DELETE RESTRICT,
  UNIQUE KEY uq_student_billing_period (student_id,year_id,period_start,period_end),
  INDEX idx_payment_year_status_period (year_id,status,period_start),
  INDEX idx_payment_student_period (student_id,period_start),
  CHECK (period_end >= period_start),
  CHECK (amount >= 0),
  CHECK (status = 'paid' OR paid_at IS NULL)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Role change audit (admins reassign roles; every change is recorded)
-- ---------------------------------------------------------------------------
CREATE TABLE role_change_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  changed_by BIGINT UNSIGNED NOT NULL,
  old_role_id TINYINT UNSIGNED,
  new_role_id TINYINT UNSIGNED NOT NULL,
  reason VARCHAR(255),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rch_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rch_user_school FOREIGN KEY (user_id,school_id) REFERENCES users(id,school_id) ON DELETE RESTRICT,
  CONSTRAINT fk_rch_changed_by_school FOREIGN KEY (changed_by,school_id) REFERENCES users(id,school_id) ON DELETE RESTRICT,
  CONSTRAINT fk_rch_old_role FOREIGN KEY (old_role_id) REFERENCES roles(id),
  CONSTRAINT fk_rch_new_role FOREIGN KEY (new_role_id) REFERENCES roles(id),
  INDEX idx_rch_user_date (user_id,created_at),
  INDEX idx_rch_changed_by (changed_by,created_at),
  INDEX idx_rch_school_date (school_id,created_at)
) ENGINE=InnoDB;

-- Unified append-only action events. Keep actor_name as a snapshot so history
-- remains recognizable if the account is later renamed or deactivated.
CREATE TABLE audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id BIGINT UNSIGNED NOT NULL,
  year_id BIGINT UNSIGNED,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  actor_name VARCHAR(120) NOT NULL,
  action_type VARCHAR(48) NOT NULL,
  entity_type VARCHAR(64) NOT NULL,
  entity_id BIGINT UNSIGNED,
  summary_key VARCHAR(100) NOT NULL,
  changes JSON,
  reason VARCHAR(255),
  request_id VARCHAR(100),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_events_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT,
  CONSTRAINT fk_audit_events_year_school FOREIGN KEY (year_id,school_id) REFERENCES academic_years(id,school_id) ON DELETE RESTRICT,
  CONSTRAINT fk_audit_events_actor_school FOREIGN KEY (actor_user_id,school_id) REFERENCES users(id,school_id) ON DELETE RESTRICT,
  INDEX idx_audit_events_school_date (school_id,created_at,id),
  INDEX idx_audit_events_school_year_date (school_id,year_id,created_at,id),
  INDEX idx_audit_events_school_actor_date (school_id,actor_user_id,created_at),
  INDEX idx_audit_events_school_entity (school_id,entity_type,entity_id,created_at)
) ENGINE=InnoDB;

-- Laravel's configured database cache store.
CREATE TABLE cache (
  `key` VARCHAR(255) NOT NULL PRIMARY KEY,
  value MEDIUMTEXT NOT NULL,
  expiration INT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE cache_locks (
  `key` VARCHAR(255) NOT NULL PRIMARY KEY,
  owner VARCHAR(255) NOT NULL,
  expiration INT NOT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Seed data
-- These setup rows support the starter logins and the initial year selector.
-- Sample students and groups are unassigned so the app can be tested before
-- users place students or assign teachers through the interface.
INSERT INTO roles (role_key,name_fr,name_ar) VALUES
  ('admin','Administrateur','مدير النظام'),
  ('teacher','Enseignant','معلم'),
  ('secretary','Secrétariat','السكرتارية');

INSERT INTO schools (name_fr,name_ar,currency) VALUES
  ('Association Imam Muslim','جمعية الامام مسلم','MAD');

SET @seed_school_id = (
  SELECT id FROM schools
  WHERE name_fr = 'Association Imam Muslim'
    AND name_ar = 'جمعية الامام مسلم'
  ORDER BY id
  LIMIT 1
);

INSERT INTO academic_years (school_id,year_label,start_date,end_date,is_current,is_active)
SELECT @seed_school_id,'2026-2027','2026-09-01','2027-06-30',TRUE,TRUE
WHERE NOT EXISTS (
  SELECT 1 FROM academic_years
  WHERE school_id=@seed_school_id AND year_label='2026-2027'
);

-- One starter login per dashboard role (password: "password").
INSERT INTO users (school_id,role_id,full_name,email,password_hash,phone,language,is_active) VALUES
  (@seed_school_id,(SELECT id FROM roles WHERE role_key='admin'),'Admin Test','admin@example.com','$2y$12$x3avkoKP7/D/C.4iQO6ure8S9v33IB9gGfyhR9fQquBnZG6lXFqKm',NULL,'fr',TRUE),
  (@seed_school_id,(SELECT id FROM roles WHERE role_key='teacher'),'Enseignant Test','teacher@example.com','$2y$12$Qeyfxr6Wz1U/GF/Hg39v0uRVBbznovOI6Qiv1xA5VgnQDmy0eCX4O',NULL,'fr',TRUE),
  (@seed_school_id,(SELECT id FROM roles WHERE role_key='secretary'),'Secrétariat Test','secretary@example.com','$2y$12$0OsVx3/I8PC1E5wnLBfXSuGrm2J6J3WKAPqr9w25bvLLLD1QaxVmi',NULL,'fr',TRUE);

-- Five sample teacher accounts (password: "password").
INSERT INTO users (school_id,role_id,full_name,email,password_hash,phone,language,is_active) VALUES
  (@seed_school_id,(SELECT id FROM roles WHERE role_key='teacher'),'ostad1','ostad1@example.com','$2y$12$Qeyfxr6Wz1U/GF/Hg39v0uRVBbznovOI6Qiv1xA5VgnQDmy0eCX4O',NULL,'fr',TRUE),
  (@seed_school_id,(SELECT id FROM roles WHERE role_key='teacher'),'ostad2','ostad2@example.com','$2y$12$Qeyfxr6Wz1U/GF/Hg39v0uRVBbznovOI6Qiv1xA5VgnQDmy0eCX4O',NULL,'fr',TRUE),
  (@seed_school_id,(SELECT id FROM roles WHERE role_key='teacher'),'ostad3','ostad3@example.com','$2y$12$Qeyfxr6Wz1U/GF/Hg39v0uRVBbznovOI6Qiv1xA5VgnQDmy0eCX4O',NULL,'fr',TRUE),
  (@seed_school_id,(SELECT id FROM roles WHERE role_key='teacher'),'ostad4','ostad4@example.com','$2y$12$Qeyfxr6Wz1U/GF/Hg39v0uRVBbznovOI6Qiv1xA5VgnQDmy0eCX4O',NULL,'fr',TRUE),
  (@seed_school_id,(SELECT id FROM roles WHERE role_key='teacher'),'ostad5','ostad5@example.com','$2y$12$Qeyfxr6Wz1U/GF/Hg39v0uRVBbznovOI6Qiv1xA5VgnQDmy0eCX4O',NULL,'fr',TRUE);

INSERT INTO rooms (school_id,year_id,name,level,age_min,age_max,capacity)
SELECT @seed_school_id,ay.id,sample.name,'beginner',sample.age_min,sample.age_max,12
FROM academic_years AS ay
CROSS JOIN (
  SELECT 'G1' AS name, 6 AS age_min, 7 AS age_max
  UNION ALL SELECT 'G2', 8, 9
  UNION ALL SELECT 'G3', 10, 11
  UNION ALL SELECT 'G4', 12, 13
  UNION ALL SELECT 'G5', 14, 15
) AS sample
WHERE ay.school_id=@seed_school_id AND ay.year_label='2026-2027';

INSERT INTO students (
  school_id,year_id,student_number,full_name,birth_date,room_id,enrollment_date,status
)
SELECT @seed_school_id,ay.id,sample.student_number,sample.full_name,sample.birth_date,NULL,'2026-09-01','active'
FROM academic_years AS ay
CROSS JOIN (
  SELECT 'S-001' AS student_number, 'Élève Test 1' AS full_name, '2020-01-15' AS birth_date
  UNION ALL SELECT 'S-002', 'Élève Test 2', '2020-04-22'
  UNION ALL SELECT 'S-003', 'Élève Test 3', '2020-07-09'
  UNION ALL SELECT 'S-004', 'Élève Test 4', '2020-10-28'
  UNION ALL SELECT 'S-005', 'Élève Test 5', '2019-02-11'
  UNION ALL SELECT 'S-006', 'Élève Test 6', '2019-05-30'
  UNION ALL SELECT 'S-007', 'Élève Test 7', '2019-08-17'
  UNION ALL SELECT 'S-008', 'Élève Test 8', '2019-11-03'
  UNION ALL SELECT 'S-009', 'Élève Test 9', '2018-01-26'
  UNION ALL SELECT 'S-010', 'Élève Test 10', '2018-04-14'
  UNION ALL SELECT 'S-011', 'Élève Test 11', '2018-07-31'
  UNION ALL SELECT 'S-012', 'Élève Test 12', '2018-10-06'
  UNION ALL SELECT 'S-013', 'Élève Test 13', '2017-02-19'
  UNION ALL SELECT 'S-014', 'Élève Test 14', '2017-05-08'
  UNION ALL SELECT 'S-015', 'Élève Test 15', '2017-08-25'
  UNION ALL SELECT 'S-016', 'Élève Test 16', '2017-11-12'
  UNION ALL SELECT 'S-017', 'Élève Test 17', '2016-01-04'
  UNION ALL SELECT 'S-018', 'Élève Test 18', '2016-04-27'
  UNION ALL SELECT 'S-019', 'Élève Test 19', '2016-07-16'
  UNION ALL SELECT 'S-020', 'Élève Test 20', '2016-10-29'
  UNION ALL SELECT 'S-021', 'Élève Test 21', '2015-02-07'
  UNION ALL SELECT 'S-022', 'Élève Test 22', '2015-05-21'
  UNION ALL SELECT 'S-023', 'Élève Test 23', '2015-08-02'
  UNION ALL SELECT 'S-024', 'Élève Test 24', '2015-11-18'
  UNION ALL SELECT 'S-025', 'Élève Test 25', '2014-01-23'
  UNION ALL SELECT 'S-026', 'Élève Test 26', '2014-04-06'
  UNION ALL SELECT 'S-027', 'Élève Test 27', '2014-07-28'
  UNION ALL SELECT 'S-028', 'Élève Test 28', '2014-10-15'
  UNION ALL SELECT 'S-029', 'Élève Test 29', '2013-02-02'
  UNION ALL SELECT 'S-030', 'Élève Test 30', '2013-05-19'
  UNION ALL SELECT 'S-031', 'Élève Test 31', '2013-08-07'
  UNION ALL SELECT 'S-032', 'Élève Test 32', '2013-11-24'
  UNION ALL SELECT 'S-033', 'Élève Test 33', '2012-01-12'
  UNION ALL SELECT 'S-034', 'Élève Test 34', '2012-04-30'
  UNION ALL SELECT 'S-035', 'Élève Test 35', '2012-07-03'
  UNION ALL SELECT 'S-036', 'Élève Test 36', '2012-10-20'
) AS sample
WHERE ay.school_id=@seed_school_id AND ay.year_label='2026-2027';
