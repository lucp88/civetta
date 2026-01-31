# Civetta - Bakkerij Webapplicatie

Een webapplicatie voor een ambachtelijke bakkerij met productverkoop, B2B systeem en e-Boekhouden integratie.

## Tech Stack

- **Backend**: PHP (geen framework), MySQL, PDO
- **Frontend**: Vue.js 3 (CDN, geen build step), Options API
- **Betalingen**: Mollie API
- **Boekhouding**: e-Boekhouden REST API
- **PDF**: FPDF library (in /lib)

## Project Structuur

```
/admin/           - Admin panel (PHP)
  /migrations/    - Database migrations (handmatig runnen)
/api/             - REST API endpoints (PHP)
/cron/            - Cronjobs (auto-invoice, check-payments, delivery-status)
/css/             - Stylesheets
/docs/            - Documentatie (zie voor details)
/img/             - Afbeeldingen en uploads
/js/              - Vue.js apps
/lib/             - FPDF library
```

## Belangrijke Bestanden

### Backend
- `admin/config.php` - Database connectie, helper functies, session config
- `api/products.php` - Producten met varianten
- `api/business-orders.php` - B2B bestellingen CRUD
- `api/mollie.php` - Payment creatie
- `api/eboekhouden.php` - EBoekhoudenClient class
- `api/delivery-status.php` - Centrale delivery status functies

### Frontend
- `js/order-app.js` - Bestelproces, winkelwagen
- `js/mijn-bestellingen-app.js` - Klant bestellingenoverzicht
- `js/dashboard-app.js` - Zakelijk dashboard

## Conventies

### PHP
- Directe PDO queries met prepared statements
- API endpoints retourneren JSON met `success` boolean
- Admin pages checken login via `requireLogin()`
- Environment variables via getenv() (DB_HOST, DB_NAME, DB_USER, DB_PASS, MOLLIE_API_KEY)

### JavaScript
- Vue.js 3 Options API
- Na wijzigingen: verhoog cache buster versie `?v=X` in HTML

### Database
- `business_orders` - Hoofdtabel bestellingen
- `business_order_items` - Orderregels
- `business_accounts` - Zakelijke klanten
- `product_variants` - Gewichtsvarianten per product
- `settings` - Key-value instellingen

### Status Model (business_orders)
- `invoice_status`: bestelbon / gefactureerd
- `delivery_status`: geplaatst / wordt_bereid / onderweg / afgeleverd
- `payment_status`: pending / paid
- `payment_type`: ideal / factuur

## Commands

### Migrations
```bash
php admin/migrations/006_product_variants.php
```

### Cron Jobs
```bash
php cron/auto-invoice.php          # Automatische facturatie
php cron/check-payments.php        # Mollie status check
php cron/update-delivery-status.php # Delivery status update
```

## Documentatie

Zie `/docs/` voor gedetailleerde documentatie:
- `context.md` - Project overview
- `database-structuur.md` - Volledige database schema
- `bedrijven-bestelsysteem.md` - B2B systeem details
- `e-boekhouden-api.md` - Boekhouding API
- `blog-systeem.md` - Blog functionaliteit
