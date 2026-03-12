# Civetta - Bakkerij Webapplicatie

Een webapplicatie voor een ambachtelijke bakkerij met productverkoop, B2B bestelsysteem, leveringenbeheer en e-Boekhouden integratie.

## Tech Stack

- **Backend**: PHP (geen framework), MySQL, PDO
- **Frontend**: Vue.js 3 (CDN, geen build step), Options API
- **Betalingen**: Mollie API
- **Boekhouding**: e-Boekhouden REST API
- **PDF**: FPDF library (in /lib)
- **E-mail**: PHP mail() met HTML templates (api/email-templates.php)

## Project Structuur

```
/                         - Publieke HTML pagina's (index.html, producten.html, etc.)
/admin/                   - Admin panel (PHP, login-protected)
  /accounts/              - Account beheer (bedrijven goedkeuren, aanmaken, bewerken)
  /bakker/                - Bakker werkplek (dashboard, bereiden, leveren, shared components)
  /bestellingen/          - Bestellingen overzicht (orders)
  /blog/                  - Blog beheer (posts CRUD, import)
  /donaties/              - Donaties overzicht
  /producten/             - Producten beheer (CRUD, varianten)
  /reporting/             - Rapportages en analytics
  /settings/              - Instellingen (bedrijfsgegevens, boekhouding)
  /migrations/            - Database migrations (handmatig runnen)
  config.php              - Database connectie, helper functies, session config
  index.php               - Admin dashboard homepage
/api/                     - REST API endpoints (JSON responses)
/cron/                    - Cronjobs
/css/                     - Stylesheets
/docs/                    - Documentatie
/img/                     - Afbeeldingen en uploads
/js/                      - Vue.js apps (CDN, geen build step)
/lib/                     - Libraries (FPDF, PDF generators, shared helpers)
  /bestelbon/             - Bestelbon PDF generatie (BestelbonPDF.php, functions.php)
  /factuur/               - Factuur PDF generatie (FactuurPDF.php, functions.php)
  shared.php              - Gedeelde functies (getBedrijfsGegevens, euro)
  fpdf.php                - FPDF base library
/facturen/                - Gegenereerde factuur PDF bestanden
```

## Belangrijke Bestanden

### Backend - API
- `api/business-accounts.php` - B2B accounts CRUD, admin kan accounts aanmaken (auto-approve met `admin_create` flag)
- `api/business-orders.php` - B2B bestellingen CRUD
- `api/business-recurring.php` - Herhalende bestellingen
- `api/admin-orders.php` - Admin bestelling aanmaken (met klant/product data ophalen)
- `api/delivery-route.php` - Leverroute per dag, start route, markeer afgeleverd
- `api/delivery-status.php` - Centrale delivery status functies
- `api/products.php` - Producten met varianten
- `api/mollie.php` - Payment creatie
- `api/mollie-webhook.php` - Mollie payment webhook
- `api/eboekhouden.php` - EBoekhoudenClient class
- `api/factuur.php` - Factuur generatie
- `api/bestelbon.php` - Bestelbon generatie
- `api/email-templates.php` - HTML e-mail templates en sendHtmlEmail()
- `api/analytics.php` - Analytics data API (omzet, producten, locaties)
- `api/cors.php` - CORS headers helper

### Backend - Admin
- `admin/config.php` - Database connectie, requireLogin(), session config
- `admin/accounts/accounts-bedrijven.php` - Bedrijfsaccounts beheer (goedkeuren, afwijzen, bewerken, nieuw aanmaken)
- `admin/bakker/leveren.php` - Leveringen kalender (dag/week/maand), route planning, nieuwe bestellingen modal
- `admin/bakker/bereiden.php` - Bereidingsoverzicht voor bakker
- `admin/bakker/bakker-dashboard.php` - Bakker overzichtspagina
- `admin/bakker/order-detail-modal.php` - Gedeelde bestelling detail modal (gebruikt door bereiden + leveren, kleur via PHP variabelen)
- `admin/bestellingen/orders.php` - Admin bestellingen overzicht
- `admin/reporting/analytics.php` - Analytics dashboard (omzet, verkoop, locaties)

### Frontend - JavaScript
- `js/order-app.js` - Bestelproces, winkelwagen
- `js/mijn-bestellingen-app.js` - Klant bestellingenoverzicht met filters
- `js/dashboard-app.js` - Zakelijk klant dashboard
- `js/zakelijk-app.js` - Zakelijk aanmeldformulier
- `js/products-app.js` - Producten overzicht
- `js/recurring-modal.js` - Herhalende bestellingen modal
- `js/login-app.js` - Zakelijk login
- `js/blog-app.js` - Blog frontend

### Frontend - HTML pagina's
- `index.html` - Homepage
- `producten.html` - Producten pagina
- `bestelling-plaatsen.html` - Bestelflow
- `checkout.html` - Checkout/betaling
- `mijn-bestellingen.html` - Klant bestellingenoverzicht
- `zakelijk-dashboard.html` - Zakelijk klant dashboard
- `login-bedrijven.html` - Zakelijk login
- `zakelijk.html` - Zakelijk account aanvragen

## Conventies

### PHP
- Directe PDO queries met prepared statements
- API endpoints retourneren JSON met `success` boolean
- Admin pages checken login via `requireLogin()`
- Environment variables via getenv() (DB_HOST, DB_NAME, DB_USER, DB_PASS, MOLLIE_API_KEY)
- PDF generatie via FPDF in /lib/bestelbon/ en /lib/factuur/
- HTML e-mails via email-templates.php (getEmailHeader, getEmailFooter, build*Email functies)

### JavaScript
- Vue.js 3 Options API (via CDN, geen build step)
- Na wijzigingen: verhoog cache buster versie `?v=X` in HTML
- Admin pagina's gebruiken vanilla JS (geen Vue)

### CSS
- Admin pagina's hebben inline `<style>` blokken (geen aparte CSS bestanden)
- Publieke pagina's gebruiken bestanden in /css/
- Design: aarde-tinten (#8b5a2b, #5c3d1e) voor admin, oranje (#ff6b35, #e55a2b) voor bereiden, blauw (#2196f3, #1976d2) voor leveren
- Gedeelde componenten in bakker/ via PHP include met kleur-variabelen ($detailAccentColor, $detailAccentColorDark)

### Database
- `business_orders` - Hoofdtabel bestellingen
- `business_order_items` - Orderregels
- `business_accounts` - Zakelijke klanten (met delivery adres velden)
- `products` - Producten (met `deegtype_id` FK naar deegtypes, intern gebruik)
- `deegtypes` - Deegtypes voor producten (alleen intern/bakker)
- `product_variants` - Gewichtsvarianten per product
- `settings` - Key-value instellingen (bedrijfsgegevens, boekhouding config)
- `posts` - Blog posts
- `donations` - Donaties

### Status Model (business_orders)
- `invoice_status`: bestelbon / gefactureerd
- `delivery_status`: geplaatst / wordt_bereid / onderweg / afgeleverd
- `payment_status`: pending / paid
- `payment_type`: ideal / factuur
- `is_cancelled`: 0 / 1

### Business Accounts Status
- `status`: pending / approved / rejected
- Bij admin aanmaken (`admin_create`): direct approved met gegenereerd wachtwoord
- `delivery_same_as_business`: boolean - leveradres = bedrijfsadres
- Aparte delivery velden: `delivery_adres`, `delivery_postcode`, `delivery_plaats`

## Migration Template

Nieuwe migrations in `admin/migrations/` moeten altijd deze layout volgen (zie 025 als referentie):

```php
<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration XXX</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #5c3d1e; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info    { color: #666; }
        .error   { color: #c62828; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        a { color: #8b5a2b; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #8b5a2b; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #5c3d1e; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration XXX: Titel</h1>
<pre><?php
// Migration queries hier, elk in try/catch:
// Success: echo "<span class='success'>✓ beschrijving</span>\n";
// Already exists: echo "<span class='info'>- beschrijving</span>\n";
// Error: echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";

echo "\n<span class='success'>✓ Migration XXX voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Beschrijving van elke wijziging</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
```

## Commands

### Migrations
```bash
php admin/migrations/009_deegtypes.php
```

### Cron Jobs
```bash
php cron/auto-invoice.php               # Automatische facturatie
php cron/check-payments.php             # Mollie status check
php cron/update-delivery-status.php     # Delivery status update
php cron/recurring-renewal-reminder.php # Herinnering herhalende bestellingen
```

## Documentatie

Zie `/docs/` voor gedetailleerde documentatie:
- `context.md` - Project overview
- `database-structuur.md` - Volledige database schema
- `bedrijven-bestelsysteem.md` - B2B systeem details
- `e-boekhouden-api.md` - Boekhouding API
- `blog-systeem.md` - Blog functionaliteit
- `admin-panel.md` - Admin panel documentatie

# important-instruction-reminders
Do what has been asked; nothing more, nothing less.
NEVER create files unless they're absolutely necessary for achieving your goal.
ALWAYS prefer editing an existing file to creating a new one.
NEVER proactively create documentation files (*.md) or README files. Only create documentation files if explicitly requested by the User.
