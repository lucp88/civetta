# Civetta Project Context

Dit document geeft een snelle overview voor Claude instances om snel aan de slag te kunnen.

## Wat is Civetta?

Een webapplicatie voor een ambachtelijke bakkerij met:
- **Publieke website**: Productoverzicht, bestelpagina, donaties, blog
- **B2B systeem**: Zakelijke klanten met eigen login, favorieten, terugkerende bestellingen
- **Admin panel**: Producten, bestellingen, facturen, e-Boekhouden integratie

## Tech Stack

| Component | Technologie |
|-----------|-------------|
| Backend | PHP (geen framework) |
| Database | MySQL |
| Frontend | Vue.js 3 (via CDN, geen build step) |
| Betalingen | Mollie API |
| Boekhouding | e-Boekhouden API |
| PDF generatie | FPDF library (in /lib) |

## Directory Structuur

```
/admin/           - Admin panel (PHP pages)
  /migrations/    - Database migrations (handmatig runnen)
/api/             - REST API endpoints (PHP)
/cron/            - Cronjobs voor payments, invoices, delivery status
/css/             - Stylesheets
/docs/            - Documentatie
/img/             - Afbeeldingen en uploads
/js/              - Vue.js apps en components
/lib/             - FPDF library
```

## Belangrijke Bestanden

### Frontend (Vue.js)
- `js/order-app.js` - Bestelproces logica, winkelwagen
- `js/product-card.js` - ProductCard component (herbruikbaar)
- `js/products-app.js` - Productenpagina
- `js/mijn-bestellingen-app.js` - Klant bestellingenoverzicht
- `js/dashboard-app.js` - Zakelijk dashboard

### Backend (PHP)
- `admin/config.php` - Database connectie, helper functies
- `api/products.php` - Producten ophalen met varianten
- `api/business-orders.php` - B2B bestellingen CRUD
- `api/mollie.php` - Payment creatie
- `api/eboekhouden.php` - Boekhouding integratie

### HTML Pages
- `bestelling-plaatsen.html` - Bestelformulier (Vue app)
- `producten.html` - Productoverzicht (Vue app)
- `mijn-bestellingen.html` - Klant bestellingen
- `zakelijk-dashboard.html` - B2B dashboard

## Database Tabellen (belangrijkste)

- `products` - Producten met naam, prijs, beschrijving, afbeelding
- `product_variants` - Gewichtsvarianten per product (gewicht in gram, prijs)
- `business_orders` - Bestellingen met Mollie/factuur status
- `business_order_items` - Bestelregels met variant_id
- `business_accounts` - Zakelijke klanten
- `recurring_orders` - Terugkerende bestellingen

Zie `docs/database-structuur.md` voor volledige schema.

## Product Varianten Systeem

Producten kunnen meerdere gewichtsvarianten hebben met verschillende prijzen:
- Opgeslagen in `product_variants` tabel
- Admin: dynamische rijen toevoegen/verwijderen in product-edit.php
- Frontend: dropdown voor selectie, aparte cart items per variant
- Cart: `cart` object voor reguliere producten, `variantCart` array voor variant items

## Betalingen

1. **iDEAL via Mollie**: Direct betalen, webhook updates status
2. **Factuur**: Admin maakt factuur via e-Boekhouden, PDF generatie met FPDF

Flow: `api/mollie.php` → payment → `api/mollie-webhook.php` → status update

## e-Boekhouden Integratie

- Facturen aanmaken vanuit orders
- Grootboekrekeningen en templates via dropdown
- Mutaties ophalen voor betalingscontrole
- Zie `docs/e-boekhouden-api.md` voor details

## Migrations

Migrations worden handmatig gedraaid:
```bash
php admin/migrations/006_product_variants.php
```

Check altijd eerst of migration al is uitgevoerd (ze zijn idempotent).

## Cronjobs

- `cron/check-payments.php` - Mollie payment status checken
- `cron/auto-invoice.php` - Automatisch facturen versturen
- `cron/update-delivery-status.php` - Delivery status bijwerken

## Conventies

- **PHP**: Geen framework, directe PDO queries
- **JavaScript**: Vue.js 3 Options API, geen build step
- **CSS**: Inline styles in HTML of aparte .css bestanden
- **Taal**: Nederlands in UI, code comments mix NL/EN
- **Cache busting**: `?v=X` parameter op script tags bij wijzigingen

## Tips

1. Na JS wijzigingen: verhoog cache buster versie in HTML
2. API endpoints retourneren JSON met `success` boolean
3. Admin pages checken login via `requireLogin()` functie
4. Gebruik `htmlspecialchars()` voor output escaping
5. Mollie test mode via `TEST_MODE` in config

## Gerelateerde Docs

- `bedrijven-bestelsysteem.md` - B2B systeem details
- `blog-systeem.md` - Blog functionaliteit
- `database-structuur.md` - Volledige database schema
- `e-boekhouden-api.md` - Boekhouding integratie
