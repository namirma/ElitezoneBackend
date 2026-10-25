# EliteZone (Ario Elite) — B2B Construction Materials Marketplace API

[![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![Tests](https://img.shields.io/badge/Tests-268%20Passed-brightgreen?style=for-the-badge)](https://phpunit.de)
[![Sanctum](https://img.shields.io/badge/Auth-Sanctum%20OTP-red?style=for-the-badge)](https://laravel.com/docs/sanctum)
[![Laratrust](https://img.shields.io/badge/RBAC-Laratrust-blue?style=for-the-badge)](https://laratrust.santigarcor.me)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=for-the-badge)](LICENSE)

> A modern, high-throughput RESTful API engineered for **EliteZone (Ario Elite)** — a multi-persona B2B/B2C marketplace for construction materials (rebar, structural steel, brick, and bulk aggregates). Features dynamic attribute-based pricing matrices, automated order lifecycles with reservation holds, digital wallet ledger with escrow, structural takeoff (Listopher) processing, and comprehensive administrative back-office suites.

---

## 🏛 Key Architecture & Modules

### 1. Multi-Persona Role-Based Access Control (RBAC)
- Fine-grained permission matrix with **Laratrust** and **Sanctum** OTP tokens.
- Specialized personas:
  - **Contractors & Project Managers:** Quote requests, credit orders, and job-site material scheduling.
  - **Suppliers & Factories:** Factory location mapping, dynamic price brackets, weighbridge consents, and dispatch manifests.
  - **Civil Engineers & EliteYar:** Technical consultation, site inspections, and structural takeoff assessments.
  - **Super Admins:** Full back-office catalog suite (15 dedicated controllers, ~87 endpoints) with price-adjustments, badge curation, and financial arbitration.

### 2. Dynamic Catalog & Variant Pricing Engine
- Attribute-Value modeling for multi-dimensional construction variants (dimensions, steel grades, specifications, factory origins).
- Advanced pricing matrix:
  - Base price calculations with category-level and supplier-level adjustments.
  - Tiered quantity breaks, real-time fee calculations, and VAT handling.

### 3. Integrated Financial Ledger & Digital Wallet
- Double-entry accounting principles with atomic transactions (`lockForUpdate`).
- Escrow holds during order processing, debit/credit ledger, debt repayment tracking, and automated refund management.

### 4. Deterministic Order Lifecycle & Background Automation
- Strict state machine across quotation, reservation, payment verification, preparation, weighbridge consent, and fulfillment.
- Scheduled artisan commands (`orders:cancel-expired`) automatically releasing locked inventory upon reservation timeouts.

### 5. Construction Takeoff Subsystem (Listopher)
- Specialized engineering module designed for structural takeoff (rebar scheduling, beam/column specifications).
- Direct parsing and price estimation of bulk materials directly integrated into checkout pipelines.

---

## 🛠 Tech Stack

| Component | Technology |
|---|---|
| **Framework** | Laravel 13 (PHP 8.4) |
| **Authentication** | Laravel Sanctum (OTP-based SMS authentication) |
| **Authorization** | Laratrust (Multi-role, permissions & team guards) |
| **Database** | MySQL / MariaDB with optimized indexing |
| **Testing** | PHPUnit 11 (268 unit and feature tests) |
| **Code Style** | Laravel Pint |
| **API Documentation** | Scramble OpenAPI |
| **Date & Localization** | Verta (Jalali calendar support for Iranian enterprise standards) |

---

## 🧪 Testing & Quality Assurance

The codebase includes an extensive automated test suite with **268 passing tests**:

```bash
# Run all automated tests
php artisan test

# Run code style fixer
./vendor/bin/pint --test
```

---

## 🚀 Getting Started

### Prerequisites
- PHP `>= 8.4`
- Composer `>= 2.0`
- MySQL `>= 8.0`

### Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/namirma/ElitezoneBackend.git
   cd ElitezoneBackend
   ```

2. **Install PHP dependencies:**
   ```bash
   composer install --optimize-autoloader
   ```

3. **Configure Environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Run Migrations & Seeders:**
   ```bash
   php artisan migrate --seed
   ```

5. **Start Development Server:**
   ```bash
   php artisan serve
   ```

---

## 🔒 Security Best Practices

- **Zero-Secret Commit History:** All sensitive credentials, tokens, and environment configs are strictly excluded via comprehensive `.gitignore` rules.
- **Strict Payload Validation:** Strongly-typed Laravel `FormRequest` validation classes with sanitization.
- **Race Condition Prevention:** Pessimistic locking on wallet balances and critical order transitions.

---

## 👨‍💻 Author

**Navid Mirtorabi**  
- GitHub: [@namirma](https://github.com/namirma)  
- Email: [navidm632@gmail.com](mailto:navidm632@gmail.com)
