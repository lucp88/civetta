# Admin Panel

Het admin panel is bereikbaar via `/admin/` en bevat alle beheersfuncties voor de bakkerij.

## Folder Structuur

```
admin/
├── config.php              - Database connectie, sessie config, helpers
├── login.php               - Login formulier met rate limiting
├── logout.php              - Sessie beëindigen
├── setup.php               - Eenmalige database setup
├── index.php               - Hoofddashboard met navigatie
├── migrations/             - Database migrations (handmatig)
├── bakker/                 - Bakker planning
│   ├── bakker-dashboard.php  - Dagelijks overzicht
│   ├── bereiden.php          - Bereidingsplanning (kalender)
│   └── leveren.php           - Leveringsplanning (kalender)
├── blog/                   - Blog / content
│   ├── posts.php             - Blog overzicht
│   ├── post-edit.php         - Post editor
│   ├── post-delete.php       - Post verwijderen
│   └── import-posts.php      - Posts importeren (eenmalig)
├── producten/              - Productbeheer
│   ├── products.php          - Productoverzicht
│   ├── product-edit.php      - Product editor
│   └── product-delete.php    - Product verwijderen
├── accounts/               - Klantbeheer
│   ├── accounts.php          - Account hub
│   └── accounts-bedrijven.php - Zakelijke accounts
├── bestellingen/           - Bestellingen
│   └── orders.php            - Bestellingenbeheer
├── donaties/               - Financieel
│   └── donations.php         - Donaties / crowdfunding
└── settings/               - Instellingen
    ├── settings-bedrijf.php    - Bedrijfsgegevens
    └── settings-boekhouding.php - Boekhouding / e-Boekhouden
```

## Authenticatie

- **login.php** - Login formulier met rate limiting (max 5 pogingen per 15 min)
- **logout.php** - Sessie beëindigen
- **config.php** - Database connectie (PDO), sessie config, `requireLogin()` helper
- **setup.php** - Eenmalige database setup en admin account aanmaken

Alle admin pagina's checken login via `requireLogin()`.

## Dashboard & Navigatie

### index.php - Hoofddashboard
Navigatiekaarten naar alle admin features.

### bakker/bakker-dashboard.php - Bakker Overzicht
Dagelijks planningsoverzicht met:
- Bereidingsstatistieken (aantal bestellingen, producten)
- Leveringsstatistieken
- Snelle navigatie naar bereiden.php en leveren.php

## Bestellingen

### bestellingen/orders.php - Bestellingenbeheer
Volledig overzicht van alle bestellingen met:
- Filters op betaalstatus, betaalmethode
- Betalingsstatus updates
- Gedetailleerde orderweergave

### bakker/bereiden.php - Bereidingsplanning
Kalenderweergave (dag/week/maand) van wat er bereid moet worden:
- **Bereiding = leverdatum - 1 dag** (bereidingsdatum is altijd de dag voor levering)
- Navigatie tussen periodes, vandaag-knop
- **dayModal**: Compacte producttotalen als tags + order rows per dag
  - Producttotalen: alle producten opgeteld over alle bestellingen van die dag
  - Order rows: bedrijfsnaam, productsamenvatting, betaalstatus, bedrag
- **orderModal**: Besteldetails exact zoals fix/admin-betstellingen branch
  - Detail grid: bedrijf, contactpersoon, telefoon, email, leveradres (full span)
  - Status flow visueel: Geplaatst -> Bereiden -> Onderweg -> Afgeleverd
  - Producten lijst met hoeveelheid en regelbedrag
  - Opmerkingen sectie (conditioneel)
  - Betaalmethode + totaalbedrag
  - Action buttons: Navigeren (Google Maps), Bellen, Bestelbon (PDF)
- Address data: haalt leveradres op via `delivery_same_as_business` flag of apart delivery adres

### bakker/leveren.php - Leveringsplanning
Kalenderweergave voor leveringen met:
- Dezelfde dag/week/maand navigatie als bereiden.php
- **dayModal (Route)**: Route overzicht per dag
  - Route summary: aantal stops, totaalbedrag, afgeleverde status
  - Route acties: Start Route (Google Maps waypoints), e-mail leverbevestigingen
  - Stops lijst: startpunt (bakkerij) -> klant stops -> eindpunt
  - Per stop: bedrijfsnaam, adres, producten, navigeren/bellen knoppen
  - Delivery status updates (markeer als afgeleverd)
- **orderModal**: Besteldetails met leveradres, status flow, producten, navigeren/bellen/bestelbon

## Klantbeheer

### accounts/accounts.php - Account Hub
Navigatie naar zakelijke en particuliere klantbeheer.

### accounts/accounts-bedrijven.php - Zakelijke Accounts
Volledig beheer van B2B accounts:
- Account goedkeuring (nieuwe aanmeldingen)
- Gegevens bewerken (bedrijfsinfo, leveradres, contactgegevens)
- Wachtwoord reset
- Account activeren/deactiveren

## Productbeheer

### producten/products.php - Productoverzicht
Catalogus met alle producten, prijzen en gewichtsvarianten.

### producten/product-edit.php - Product Bewerken
Volledige product editor:
- Naam, beschrijving, prijs
- Afbeelding upload
- Gewichtsvarianten beheer
- Live preview

### producten/product-delete.php - Product Verwijderen
Verwijdert een product uit de catalogus.

## Blog / Content

### blog/posts.php - Blog Overzicht
Lijst van alle blog posts met aanmaak- en bewerkfuncties.

### blog/post-edit.php - Post Bewerken
Blog post editor met datum selectie en content beheer.

### blog/post-delete.php - Post Verwijderen
Verwijdert een blog post.

### blog/import-posts.php - Posts Importeren
Eenmalig script voor het importeren van initiële blog posts.

## Financieel

### donaties/donations.php - Donaties / Crowdfunding
Donatie tracking met:
- Handmatige invoer
- Mollie betalingsintegratie overzicht

## Instellingen

### settings/settings-bedrijf.php - Bedrijfsinstellingen
- Bedrijfsnaam, adres, contactgegevens
- Bankgegevens voor facturatie
- QR-betaalgegevens

### settings/settings-boekhouding.php - Boekhouding Instellingen
- e-Boekhouden API koppeling (gebruikersnaam, beveiligingscode)
- Grootboekrekeningen selectie (dropdown)
- Factuur templates selectie
- BTW codes
- Automatische facturatie configuratie (interval, tijdstip)
- Bank grootboekrekening voor betalingscontrole

## Database Migrations

Migrations worden handmatig uitgevoerd via CLI:

```bash
php admin/migrations/000_initial_setup.php
php admin/migrations/002_payment_status_model.php
php admin/migrations/003_order_status_bestelbon.php
php admin/migrations/004_invoice_delivery_status.php
php admin/migrations/005_mollie_refund_columns.php
php admin/migrations/006_product_variants.php
php admin/migrations/007_recurring_paused.php
php admin/migrations/008_recurring_templates.php
```

## Technische Details

- Alle pagina's gebruiken PHP met directe PDO queries
- Frontend styling is inline CSS per pagina (geen gedeelde stylesheet)
- Bootstrap Icons (CDN) voor iconen
- Geen JavaScript framework in admin (vanilla JS)
- Modals zijn custom gebouwd met `.modal-overlay` / `.modal` pattern
- Kalenderweergaves ondersteunen dag/week/maand met PHP server-side rendering
- Subfolders gebruiken `require_once '../config.php'` voor database connectie
- API referenties vanuit subfolders: `../../api/` (twee niveaus omhoog)
