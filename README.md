# 🚀 EliteZone Platform — Core Backend API

[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Laravel Framework](https://img.shields.io/badge/Laravel-11%20%2F%2013-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com/)
[![Tests](https://img.shields.io/badge/PHPUnit-100%25%20Passing-success?style=for-the-badge&logo=phpunit&logoColor=white)](https://phpunit.de/)
[![Architecture](https://img.shields.io/badge/Architecture-Clean%20%26%20Service--Oriented-blue?style=for-the-badge)](https://en.wikipedia.org/wiki/Service-oriented_architecture)
[![RFC 8030](https://img.shields.io/badge/WebPush-RFC%208030%20Compliant-brightgreen?style=for-the-badge)](https://datatracker.ietf.org/doc/html/rfc8030)

> **EliteZone** is a high-performance, enterprise-grade multi-sided B2B/B2C marketplace backend powering orders, supply chain logistics, multi-role financial ledgers, real-time push notifications, and complex approval workflows.

---

## 🌟 Key Architectural Highlights

### 1. 🏗️ Clean & Domain-Driven Architecture
- **Service & Repository Pattern:** Decoupled business rules from transport and presentation layers.
- **State Machine for Order Lifecycle:** Strict state transitions preventing race conditions, double fulfillment, and unverified status updates.
- **Financial Ledger & Debt Repayment:** Double-entry ledger mechanism ensuring auditability of wallet funding, debt adjustments, supplier payouts, and coupon applications.

### 2. 🔔 Real-Time Web Push Engine (RFC 8030 / RFC 8291)
- Built-in **VAPID Key Lifecycle Management** (`php artisan push:vapid-keys`) without third-party SaaS lock-in.
- **Device Token Registry:** Automatic endpoint deduplication, user-device pairing, and silent error resilience.
- **Environment Isolation (`dev_allowlist`):** Fail-safe guards preventing accidental push notifications to production customer devices during local development and staging testing.

### 3. 🔐 Enterprise Security & RBAC
- Multi-tier role permissions: **SuperAdmin**, **BackOffice Staff**, **Suppliers / Consultants**, and **End-Customers**.
- Token authentication with scoped abilities, rate limiting, and request signature verification.
- Sensitive credential protection: complete zero-trust configuration via clean `.env` structures.

### 4. 🧪 Rigorous Automated Testing
- Comprehensive test coverage with **PHPUnit**:
  - Feature tests for order lifecycle, financial settlements, and edge cases.
  - VAPID key generation and validation tests (`VapidKeyGenerationTest`).
  - Device token subscription and unsubscription tests (`PushDeviceTokenTest`).
  - Multi-event notification fan-out tests (`OrderEventsReachTheBellTest`).
- In-memory database isolation for rapid local test execution.

---

## 📂 Project Directory Structure

```text
├── app/
│   ├── Console/Commands/       # Artisan commands (VAPID keys, maintenance, sync)
│   ├── Http/
│   │   ├── Controllers/Api/    # Versioned REST API controllers (V1/V2, User, Admin)
│   │   ├── Requests/           # Strict Form Request validation rules
│   │   └── Resources/          # Transformational API JSON resources
│   ├── Jobs/                   # Queued asynchronous jobs (Push, SMS, Ledgers)
│   ├── Listeners/              # Event listeners (Order, Financial, Notification)
│   ├── Models/                 # Eloquent entities with typed casts & relationships
│   ├── Repositories/           # Data access layer interfaces & implementations
│   └── Services/               # Domain business logic (Pricing, Push, Notifier, Orders)
├── config/                     # Application configurations (Services, Auth, Database)
├── database/
│   ├── migrations/             # Schema migrations with foreign key constraints
│   └── seeders/                # Idempotent development & baseline seeders
├── routes/
│   ├── api/                    # Modular API route groups (admin, user, backoffice)
│   └── web.php
└── tests/
    ├── Feature/                # End-to-end integration and workflow test suites
    └── Unit/                   # Isolated unit test suites
```

---

## 🛠️ Tech Stack & Requirements

| Layer | Technology |
|---|---|
| **Runtime** | PHP 8.2+ / PHP 8.4 (Tested and fully compatible) |
| **Framework** | Laravel 10 / 11 / 13 |
| **Database** | PostgreSQL / MySQL / MariaDB (SQLite for In-Memory Testing) |
| **Caching & Queues** | Redis / Database Queue Worker |
| **Push Protocols** | Web Push RFC 8030 with VAPID Curve25519 / P-256 |
| **Testing** | PHPUnit 10.x & 9.x |

---

## 🚀 Getting Started

### 1. Clone the repository
```bash
git clone https://github.com/namirma/ElitezoneBackend.git
cd ElitezoneBackend
```

### 2. Install dependencies
```bash
composer install
```

### 3. Environment configuration
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Database Setup & Migrations
```bash
php artisan migrate --seed
```

### 5. Generate Web Push VAPID Keys
```bash
php artisan push:vapid-keys
```
*Copy the generated `PUSH_VAPID_PUBLIC_KEY` and `PUSH_VAPID_PRIVATE_KEY` into your `.env` file.*

---

## 🧪 Running Automated Tests

Run the test suite with PHPUnit:
```bash
# Run all notification tests
php artisan test --filter Notification

# Or directly with vendor PHPUnit
vendor/bin/phpunit tests/Feature/Notification/
```

Expected output:
```text
OK (17 tests, 46 assertions)
```

---

## 📄 License
This repository is private property and developed for commercial use. All rights reserved.
