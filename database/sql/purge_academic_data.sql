-- One-time cleanup for the sdi_ibu MySQL database.
-- Run deliberately after reviewing the target database and taking a backup.
-- WARNING: this deletes every student. Registrations also cascade to students.
-- Keeps academic_years, role, admin/user accounts, and website content tables.
-- Uploaded files on the public disk are not removed by SQL.

START TRANSACTION;

-- Operational records linked to registrations, classes, subjects, and teachers.
DELETE FROM attendances;
DELETE FROM grades;
DELETE FROM schedules;
DELETE FROM teaching_assignments;
DELETE FROM homeroom_assignments;
DELETE FROM classroom_placements;
DELETE FROM graduations;
DELETE FROM students;

-- Requested source records.
DELETE FROM student_registrations;
DELETE FROM classrooms;
DELETE FROM subjects;
DELETE FROM teachers;

-- Revoke login state belonging only to accounts with the guru role.
DELETE pat
FROM personal_access_tokens AS pat
JOIN users AS u ON u.id = pat.tokenable_id
JOIN role AS r ON r.id = u.role_id
WHERE r.role_name = 'guru'
  AND pat.tokenable_type = CONCAT('App', CHAR(92), 'Models', CHAR(92), 'User');

DELETE s
FROM sessions AS s
JOIN users AS u ON u.id = s.user_id
JOIN role AS r ON r.id = u.role_id
WHERE r.role_name = 'guru';

DELETE pr
FROM password_reset_tokens AS pr
JOIN users AS u ON u.email = pr.email
JOIN role AS r ON r.id = u.role_id
WHERE r.role_name = 'guru';

DELETE u
FROM users AS u
JOIN role AS r ON r.id = u.role_id
WHERE r.role_name = 'guru';

COMMIT;
