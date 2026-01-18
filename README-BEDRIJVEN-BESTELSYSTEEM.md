# Bedrijven Bestelsysteem - Bakkerij Civetta

## Inhoudsopgave
1. [Textuele Beschrijving](#textuele-beschrijving)
2. [Technische Documentatie](#technische-documentatie)

---

# Textuele Beschrijving

## Wat is het Bedrijven Bestelsysteem?

Het Bedrijven Bestelsysteem is een B2B-platform waarmee zakelijke klanten (horeca, restaurants, catering, etc.) online bestellingen kunnen plaatsen bij Bakkerij Civetta. Het systeem biedt een complete workflow van accountaanvraag tot facturatie.

## Hoofdfuncties

### 1. Zakelijke Accountregistratie
Bedrijven kunnen een zakelijk account aanvragen via de website. De aanvraag bevat bedrijfsgegevens zoals:
- Bedrijfsnaam, adres, KVK-nummer en BTW-id
- Contactpersoon met e-mailadres en telefoonnummer
- Eventuele opmerkingen of speciale wensen

Na indiening ontvangt de bakkerij een e-mail en kan de aanvraag goedkeuren of afwijzen via het admin panel. Bij goedkeuring ontvangt het bedrijf automatisch inloggegevens per e-mail.

### 2. Zakelijk Dashboard
Ingelogde bedrijven hebben toegang tot hun eigen dashboard met:
- **Overzicht**: Statistieken van lopende en afgeronde bestellingen
- **Nieuwe bestelling plaatsen**: Producten selecteren en leverdatum kiezen
- **Mijn bestellingen**: Historie van alle bestellingen met status en facturen
- **Bedrijfsgegevens beheren**: Profiel aanpassen en wachtwoord wijzigen

### 3. Bestellingen Plaatsen
Het bestelproces werkt als volgt:
1. Producten selecteren met gewenste hoeveelheden
2. Leverdatum kiezen (minimaal 2 dagen vooruit)
3. Optionele opmerkingen toevoegen
4. Kiezen voor directe betaling (Mollie iDEAL) of betalen op factuur
5. Optioneel: bestelling opslaan als favoriet voor hergebruik
6. Optioneel: instellen als terugkerende bestelling

### 4. Terugkerende Bestellingen
Zakelijke klanten kunnen vaste bestellingen instellen die automatisch worden herhaald:
- **Frequenties**: Wekelijks, tweewekelijks of maandelijks
- **Bezorgdag**: Vaste dag in de week
- **Einddatum**: Optioneel, anders doorlopend
- **Maandelijkse facturatie**: Alle leveringen worden gebundeld op één factuur

### 5. Favorieten
Vaak bestelde productcombinaties kunnen worden opgeslagen als favoriet. Bij een nieuwe bestelling kan een favoriet worden geladen als startpunt.

### 6. Betalingen
Het systeem ondersteunt twee betaalmethodes:
- **Directe betaling**: Via Mollie (iDEAL, creditcard, etc.)
- **Betalen op factuur**: Voor bedrijven met factuurafspraken

### 7. Facturatie
Facturen worden gegenereerd via twee systemen:
- **Eigen systeem**: PDF-facturen via FPDF library
- **e-Boekhouden integratie**: Automatische facturatie via e-Boekhouden API

Bij directe betaling wordt de factuur automatisch verzonden na betalingsbevestiging. Bij terugkerende bestellingen worden alle leveringen maandelijks gebundeld gefactureerd.

### 8. Admin Beheer
De bakkerij heeft een admin panel met:
- Overzicht van alle zakelijke accounts en aanvragen
- Bestellingenbeheer met statusupdates
- e-Boekhouden instellingen configureren
- BTW-tarieven en bedrijfsgegevens beheren

---

# Technische Documentatie

## Architectuur

```
┌─────────────────────────────────────────────────────────────────┐
│                        Frontend (HTML/JS/CSS)                    │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐   │
│  │ Vue.js Apps  │  │ HTML Pagina's│  │ CSS Styling          │   │
│  └──────────────┘  └──────────────┘  └──────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                        API Layer (PHP)                           │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐   │
│  │ Auth APIs    │  │ Order APIs   │  │ Integration APIs     │   │
│  │ - login      │  │ - orders     │  │ - mollie-webhook     │   │
│  │ - logout     │  │ - recurring  │  │ - eboekhouden        │   │
│  │ - accounts   │  │ - favorites  │  │ - factuur            │   │
│  └──────────────┘  └──────────────┘  └──────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                        Database (MySQL)                          │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ business_accounts, business_orders, business_order_items │   │
│  │ business_favorites, business_recurring_orders, settings  │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     External Services                            │
│  ┌──────────────────────┐  ┌──────────────────────────────┐     │
│  │ Mollie Payments API  │  │ e-Boekhouden API             │     │
│  └──────────────────────┘  └──────────────────────────────┘     │
└─────────────────────────────────────────────────────────────────┘
```

## Database Schema

### business_accounts
Zakelijke klantgegevens.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT | Primary key |
| bedrijfsnaam | VARCHAR(255) | Naam van het bedrijf |
| adres | TEXT | Straat en huisnummer |
| postcode | VARCHAR(10) | Postcode |
| plaats | VARCHAR(100) | Plaats |
| contactpersoon | VARCHAR(255) | Naam contactpersoon |
| email | VARCHAR(255) | E-mailadres (uniek) |
| telefoon | VARCHAR(20) | Telefoonnummer |
| website | VARCHAR(255) | Website URL |
| kvk_nummer | VARCHAR(20) | KVK-nummer |
| btw_id | VARCHAR(30) | BTW-identificatienummer |
| opmerkingen | TEXT | Opmerkingen bij aanvraag |
| status | ENUM | 'pending', 'approved', 'rejected' |
| password_hash | VARCHAR(255) | Gehashte wachtwoord |
| created_at | TIMESTAMP | Aanmaakdatum |
| approved_at | TIMESTAMP | Goedkeuringsdatum |

### business_orders
Bestellingen van zakelijke klanten.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT | Primary key |
| account_id | INT | FK naar business_accounts |
| delivery_date | DATE | Gewenste leverdatum |
| status | ENUM | 'pending', 'pending_invoice', 'paid', 'confirmed', 'delivered', 'cancelled' |
| total_amount | DECIMAL(10,2) | Totaalbedrag incl. BTW |
| notes | TEXT | Opmerkingen |
| payment_type | VARCHAR(20) | 'direct' of 'later' |
| mollie_payment_id | VARCHAR(50) | Mollie payment ID |
| mollie_status | VARCHAR(20) | Mollie betalingsstatus |
| is_recurring | TINYINT(1) | Terugkerende bestelling flag |
| recurring_name | VARCHAR(255) | Naam terugkerende bestelling |
| recurring_frequency | VARCHAR(20) | 'weekly', 'biweekly', 'monthly' |
| recurring_day | TINYINT | Dag van de week (0-6) |
| recurring_end_date | DATE | Einddatum terugkerende bestelling |
| eboekhouden_invoice_id | INT | e-Boekhouden factuur ID |
| eboekhouden_factuurnummer | VARCHAR(50) | Factuurnummer |
| eboekhouden_pdf_url | TEXT | URL naar PDF factuur |
| created_at | TIMESTAMP | Aanmaakdatum |

### business_order_items
Bestelregels per order.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT | Primary key |
| order_id | INT | FK naar business_orders |
| product_name | VARCHAR(255) | Productnaam |
| quantity | INT | Aantal |
| unit_price | DECIMAL(10,2) | Prijs per stuk incl. BTW |

### business_favorites
Opgeslagen favoriete bestellingen.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT | Primary key |
| account_id | INT | FK naar business_accounts |
| naam | VARCHAR(255) | Naam van de favoriet |
| created_at | TIMESTAMP | Aanmaakdatum |

### business_favorite_items
Items binnen een favoriet.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT | Primary key |
| favorite_id | INT | FK naar business_favorites |
| product_id | INT | Product ID |
| product_name | VARCHAR(255) | Productnaam |
| quantity | INT | Standaard aantal |
| unit_price | DECIMAL(10,2) | Laatst bekende prijs |

## API Endpoints

### Authenticatie

#### POST /api/business-login.php
Login voor zakelijke klanten.

**Request:**
```json
{
  "email": "bedrijf@example.nl",
  "password": "wachtwoord123"
}
```

**Response:**
```json
{
  "success": true,
  "account": {
    "id": 1,
    "bedrijfsnaam": "Restaurant X",
    "contactpersoon": "Jan Jansen",
    "email": "bedrijf@example.nl"
  }
}
```

**Beveiliging:**
- Rate limiting (max 5 pogingen per 15 minuten per IP)
- Session regeneration bij login
- Password hashing met `password_hash()`

#### POST /api/business-logout.php
Uitloggen en sessie beëindigen.

### Account Beheer

#### GET /api/business-dashboard.php?action=profile
Ophalen van accountgegevens.

#### PUT /api/business-dashboard.php
Bijwerken van profiel of wachtwoord.

**Acties:**
- `update_profile`: Bedrijfsgegevens bijwerken
- `change_password`: Wachtwoord wijzigen

### Bestellingen

#### GET /api/business-orders.php
Ophalen van alle bestellingen voor ingelogd account.

**Response bevat:**
- Order details met items
- BTW-berekening
- Factuur URL (indien beschikbaar)
- Betaal-URL voor openstaande Mollie betalingen

#### POST /api/business-orders.php
Nieuwe bestelling plaatsen.

**Request:**
```json
{
  "items": [
    {"product_name": "Brood", "quantity": 10, "unit_price": 3.50}
  ],
  "delivery_date": "2024-01-15",
  "notes": "Vroeg leveren",
  "total_amount": 35.00,
  "payment_type": "direct",
  "save_as_favorite": false,
  "is_recurring": false
}
```

**Response (directe betaling):**
```json
{
  "success": true,
  "order_id": 123,
  "payment_url": "https://checkout.mollie.com/..."
}
```

### Terugkerende Bestellingen

#### GET /api/business-recurring.php
Ophalen van actieve terugkerende bestellingen.

#### POST /api/business-recurring.php
Nieuwe terugkerende bestelling aanmaken.

#### PUT /api/business-recurring.php
Beheren van bestaande terugkerende bestelling.

**Acties:**
- `pause`: Pauzeren
- `resume`: Hervatten
- `cancel`: Annuleren
- `update`: Gegevens wijzigen

### Favorieten

#### GET /api/business-favorites.php
Ophalen van alle favorieten.

#### POST /api/business-favorites.php
Nieuwe favoriet opslaan.

#### DELETE /api/business-favorites.php?id=X
Favoriet verwijderen.

## Frontend Componenten

### Vue.js Applicaties

| Bestand | Pagina | Functie |
|---------|--------|---------|
| `js/dashboard-app.js` | zakelijk-dashboard.html | Hoofddashboard |
| `js/order-app.js` | bestelling-plaatsen.html | Bestelformulier |
| `js/products-app.js` | producten.html | Productcatalogus |
| `js/product-card.js` | - | Herbruikbare productkaart |
| `js/recurring-modal.js` | - | Modal voor terugkerende instellingen |

### HTML Pagina's

| Bestand | Beschrijving |
|---------|--------------|
| `zakelijk-dashboard.html` | Hoofddashboard na login |
| `bestelling-plaatsen.html` | Bestelformulier met productlijst |
| `checkout.html` | Checkout en betaalopties |
| `mijn-bestellingen.html` | Overzicht van bestellingen |
| `bedrijfsgegevens.html` | Profiel bewerken |
| `login-bedrijven.html` | Inlogpagina |
| `zakelijk.html` | Accountaanvraag formulier |

## Externe Integraties

### Mollie Payments

**Configuratie:**
- API key via environment variable `MOLLIE_API_KEY`

**Workflow:**
1. Order wordt aangemaakt met status 'pending'
2. Mollie payment wordt gecreëerd met redirect URL
3. Klant betaalt via Mollie checkout
4. Webhook ontvangt betalingsupdate
5. Bij 'paid' status: order bijwerken, factuur verzenden

**Webhook endpoint:** `/api/mollie-webhook.php`

### e-Boekhouden

**Configuratie via settings tabel:**
- `eboekhouden_api_token`: API access token
- `eboekhouden_template_id_betaald`: Template voor betaalde facturen
- `eboekhouden_template_id_openstaand`: Template voor openstaande facturen
- `eboekhouden_ledger_id`: Grootboekrekening

**Functies:**
- Automatisch relaties aanmaken/ophalen
- Facturen genereren en e-mailen
- PDF URL ophalen voor downloads

**Klasse:** `EBoekhoudenClient` in `/api/eboekhouden.php`

## Cron Jobs

### process-recurring-orders.php
**Schedule:** Dagelijks om 06:00
```
0 6 * * * /usr/bin/php /path/to/cron/process-recurring-orders.php
```

**Functie:**
- Zoekt terugkerende orders met leverdatum over 2 dagen
- Maakt automatisch nieuwe bestellingen aan
- Berekent volgende leverdatum
- Verstuurt notificaties

**CLI opties:**
- `--dry-run`: Test zonder wijzigingen
- `-v, --verbose`: Uitgebreide logging

### generate-monthly-invoices.php
**Schedule:** 1e van de maand om 08:00
```
0 8 1 * * /usr/bin/php /path/to/cron/generate-monthly-invoices.php
```

**Functie:**
- Verzamelt alle recurring leveringen van vorige maand per klant
- Genereert gebundelde factuur via e-Boekhouden
- Update order statussen naar 'invoiced'

**CLI opties:**
- `--dry-run`: Test zonder facturen
- `-v, --verbose`: Uitgebreide logging
- `--month=YYYY-MM`: Specifieke maand factureren

## Beveiliging

### Authenticatie
- Session-based authenticatie
- Password hashing met bcrypt (`PASSWORD_DEFAULT`)
- Session regeneration bij login
- CSRF-bescherming via session tokens

### Rate Limiting
- Login pogingen: max 5 per 15 minuten per IP
- Opslag in `login_attempts` tabel
- Automatische blokkering bij overschrijding

### Autorisatie
- Sessie validatie op elke API call
- Account ID verificatie voor data toegang
- Admin-only endpoints gescheiden

### CORS
- Whitelist voor toegestane origins
- Credentials ondersteuning
- OPTIONS preflight handling

## Configuratie

### Environment Variables
```
MOLLIE_API_KEY=live_xxx
```

### Settings Tabel
| Key | Beschrijving |
|-----|--------------|
| btw_tarief | BTW percentage (9 of 21) |
| facturatie_systeem | 'eigen' of 'eboekhouden' |
| eboekhouden_api_token | API token |
| eboekhouden_template_id_betaald | Template ID |
| eboekhouden_template_id_openstaand | Template ID |
| eboekhouden_ledger_id | Grootboekrekening ID |
| bedrijf_naam | Bakkerij naam voor facturen |
| bedrijf_adres | Adres voor facturen |
| bedrijf_kvk | KVK-nummer |
| bedrijf_btw_id | BTW-id |

## Admin Panel

### Pagina's
| URL | Functie |
|-----|---------|
| `/admin/orders.php` | Bestellingenbeheer |
| `/admin/accounts-bedrijven.php` | Zakelijke accounts beheren |
| `/admin/settings-boekhouding.php` | e-Boekhouden configuratie |
| `/admin/setup-business-accounts.php` | Database setup |

### Bestellingenbeheer Features
- Overzicht lopende en afgeronde bestellingen
- Status wijzigen (pending → paid → delivered)
- Factuur downloaden
- Klantgegevens inzien

## Deployment Checklist

1. **Database**
   - Run `/admin/setup-business-accounts.php` voor tabel creatie
   - Configureer settings via admin panel

2. **Environment**
   - Set `MOLLIE_API_KEY` environment variable
   - Configureer e-Boekhouden credentials

3. **Cron Jobs**
   - Setup dagelijkse recurring orders job
   - Setup maandelijkse facturatie job

4. **Directories**
   - Maak `/facturen/` directory writable
   - Maak `/api/webhook-debug.log` writable

5. **Mollie**
   - Configureer webhook URL: `https://domain.nl/api/mollie-webhook.php`
   - Test met test API key eerst

## Troubleshooting

### Webhook Debug Log
Locatie: `/api/webhook-debug.log`
Bevat: Mollie webhook calls, e-Boekhouden API responses

### Veelvoorkomende Problemen

**Login werkt niet:**
- Check login_attempts tabel voor rate limiting
- Verify password_hash in database

**Betalingen komen niet door:**
- Check Mollie webhook configuratie
- Verify webhook-debug.log voor errors

**Facturen niet gegenereerd:**
- Check e-Boekhouden credentials
- Verify template IDs en ledger ID
- Check API response in webhook-debug.log
