# CommServe Demo Bank

A simulated online banking platform built with PHP 8.3+, MySQL 8 and Bootstrap 5.

> **DEMO ONLY:** This application uses simulated money and does not connect to real banking rails or process real funds.

## Phase 1
- PHP MVC-style structure
- PDO/MySQL database layer
- Secure session authentication
- Customer registration/login/logout
- Role-based admin foundation
- Bootstrap responsive customer/admin layouts
- CSRF protection
- Password hashing
- Database schema and seed data

## Setup
1. Copy `.env.example` to `.env` and configure MySQL.
2. Create the database and import `database/schema.sql`.
3. Import `database/seed.sql` for demo accounts.
4. Point Apache/Nginx document root to `public/`.
5. Ensure PHP has PDO MySQL enabled.

Demo accounts are documented in `database/seed.sql`. Change seeded passwords before using outside a local demo environment.
