# Noble Health Software

Online claim/bill management software for **RGHS** (Rajasthan Government
Health Scheme) and **ECHS** (Ex-Servicemen Contributory Health Scheme).

Built for **Subhash Kaler** · runs at **noble.subhashkaler.com**

## Features

- 🔐 Private login (single admin) with hashed passwords + CSRF protection
- 🏥 **RGHS** and 🎖️ **ECHS** as two separate modules under one login
- 👥 Patient / beneficiary master (card no, relation, category, contact)
- 🧾 Bills / Claims with multiple medicine line items and live totals
- 🖨️ Printable bill/invoice with your shop header
- 💊 Medicine master with auto-suggest during billing
- 📊 Dashboard + date-range Reports with CSV export
- ⚙️ Settings: shop details + change password
- 🚀 Auto-deploy from GitHub to hosting (GitHub Actions FTP)

## Tech

Plain **PHP + MySQL (PDO)** — no build step, no Composer. Works on any
shared / cPanel hosting with PHP 7.4+.

## Setup

See **[DEPLOY.md](DEPLOY.md)** for full step-by-step instructions (Hindi).

Quick version:
1. Create subdomain + MySQL database on your hosting.
2. Copy `config/secrets.sample.php` → `config/secrets.php` and add DB details.
3. Upload files (or use auto-deploy).
4. Open `/install/` in the browser, create your admin login.
5. Delete the `/install/` folder, then log in.

## Structure

```
config/     database connection + app config (secrets.php is git-ignored)
includes/   auth, header/footer, shared functions
assets/     css + js
install/    one-click web installer + schema.sql
api/        csv export
*.php       app pages (login, home, dashboard, patients, bills, etc.)
.github/    auto-deploy workflow
```
