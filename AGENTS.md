# BLIS System Agent Quick Guide

This file is for coding agents. Keep it short, execution-focused, and updated when behavior changes.

## Project Snapshot

- Root app: Laravel 12 API + server-rendered entry.
- Frontend app: Vue 3 SPA in `frontend/` (Pinia + Vue Router + Axios).
- Auth: Laravel Sanctum bearer tokens (SPA stores token in `localStorage`).
- Database: MySQL in Docker.

## Primary Entry Points

- API routes: `routes/api.php`
- Web route: `routes/web.php`
- Role model/constants: `app/Models/User.php`
- Room model: `app/Models/Room.php`
- Exam model: `app/Models/Exam.php`
- Audit model: `app/Models/AuditLog.php`
- Settings model: `app/Models/SystemSetting.php`
- DB schema: `database/migrations/*`
- Seeder accounts: `database/seeders/DatabaseSeeder.php`
- Frontend auth store: `frontend/src/stores/auth.js`
- Frontend router guard: `frontend/src/router/index.js`
- Role-based sidebar/nav: `frontend/src/views/Dashboard.vue`
- Docker stack: `docker-compose.yml`, `dockerfile`, `nginx/default.conf`

## Role and Access Rules

- Canonical roles:
  - `student`
  - `staff_master_examiner` (teacher/faculty-equivalent)
  - `admin`
- Public registration always creates `student` (`POST /api/auth/register`).
- Management behavior checks include:
  - Backend: `admin`, `staff_master_examiner`, and legacy `faculty` string.
  - Frontend: same set for management sidebar.
- Admin-only modules:
  - User management (`/api/admin/users`)
  - Audit logs (`/api/admin/audit-logs`)
  - Global room oversight (`/api/rooms` for admin, or `/api/admin/rooms`)

## Current Seed Users (Local Dev)

Defined in `database/seeders/DatabaseSeeder.php`:

- Students (12 total): `student@example.com`, `student1@example.com` ... `student11@example.com`
  - Password: `pass`
  - Role: `student`
  - Student IDs: `2301290` ... `2301301`
- Teachers (3 total): `teacher@example.com`, `teacher1@example.com`, `teacher2@example.com`
  - Password: `pass`
  - Role: `staff_master_examiner`
- Admin: `admin@example.com` / `pass` / `admin`

## Docker/Runtime Notes

- Service ports:
  - App (nginx): `http://localhost:8080`
  - phpMyAdmin: `http://localhost:8081`
- MySQL container is intentionally named `lnu_lle_db`.
- MySQL volume name is `blis_system_mysql_data`.

## Common Commands

- Start stack: `docker compose up -d`
- Stop stack: `docker compose down`
- Reset DB schema + reseed: `docker compose exec -T php php artisan migrate:fresh --seed`
- Seed only: `docker compose exec -T php php artisan db:seed`
- Run migrations: `docker compose exec -T php php artisan migrate`
- Install PHP deps in container: `docker compose exec -T php composer install`
- List users/roles quickly:
  - `docker compose exec -T php php artisan tinker --execute="dump(App\\Models\\User::select('id','name','email','student_id','role')->get()->toArray());"`

## Known Fast Fixes

- Error: `vendor/autoload.php` missing
  - Run `docker compose exec -T php composer install`
- 500 with storage/log permission denied
  - Run:
    - `docker compose exec -T php sh -lc 'mkdir -p storage/logs bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwx storage bootstrap/cache'`
- Container name/port conflicts with old projects
  - Check `docker ps` and remove conflicting containers before `up -d`.
- New backend modules (API-backed):
  - Exams: `/api/exams` (CRUD + room assignment + optional `scheduled_at` datetime)
    - Exams now support optional `question_bank_id`.
  - Student exam attempts:
    - `POST /api/student/exams/{exam}/start` (room-scoped start/resume)
    - `GET /api/student/exam-attempts/{attempt}`
    - `PATCH /api/student/exam-attempts/{attempt}/answers`
    - `POST /api/student/exam-attempts/{attempt}/submit`
  - Reports: `/api/reports/overview`
  - System settings: `/api/settings/system` (admin can update)
  - Student room details (`GET /api/rooms/{room}`) expose assigned exams; attempt availability is enforced by `scheduled_at` and question set presence.

## Agent Working Style for This Repo

- Default to direct implementation.
- Avoid broad rediscovery unless files changed or task is unclear.
- For role/sidebar issues, inspect `routes/api.php` and `frontend/src/views/Dashboard.vue` first.
- Do not revert unrelated local changes.
