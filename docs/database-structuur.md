# Database Structuur

## Overzicht

De Civetta database bestaat uit twee groepen tabellen:
1. **Algemene tabellen** - Admin, blog, producten, donaties
2. **Business tabellen** - B2B bestelsysteem

## Entity Relationship Diagram

```
┌─────────────────────┐
│      users          │  Admin gebruikers
└─────────────────────┘

┌─────────────────────┐
│    blog_posts       │  Blog artikelen
└─────────────────────┘

┌─────────────────────┐
│     products        │  Producten catalogus
└─────────────────────┘

┌─────────────────────┐
│      settings       │  Applicatie instellingen
└─────────────────────┘

┌─────────────────────┐
│     donations       │  Crowdfunding donaties
└─────────────────────┘

┌─────────────────────┐
│   login_attempts    │  Rate limiting
└─────────────────────┘

┌─────────────────────┐
│      orders         │  Consumenten bestellingen (legacy)
└─────────────────────┘


═══════════════════════════════════════════════════════════════
                    BUSINESS (B2B) SYSTEEM
═══════════════════════════════════════════════════════════════

┌─────────────────────┐
│  business_accounts  │────────┬────────────┬─────────────┐
└─────────────────────┘        │            │             │
         │                     │            │             │
         │ 1:N                 │ 1:N        │ 1:N         │ 1:N
         ▼                     ▼            ▼             ▼
┌─────────────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
│  business_orders    │ │  business_  │ │ invoice_log │ │ renewal_    │
└─────────────────────┘ │  favorites  │ └─────────────┘ │ reminders_  │
         │              └─────────────┘                 │ sent        │
         │ 1:N                 │ 1:N                    └─────────────┘
         ▼                     ▼
┌─────────────────────┐ ┌─────────────────────┐
│business_order_items │ │business_favorite_   │
└─────────────────────┘ │items                │
                        └─────────────────────┘
```

---

## Algemene Tabellen

### users
Admin gebruikers voor het CMS.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| username | VARCHAR(50) | Unieke gebruikersnaam |
| password | VARCHAR(255) | Gehashte wachtwoord |
| created_at | TIMESTAMP | Aanmaakdatum |

### blog_posts
Blog artikelen.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| title | VARCHAR(255) | Titel |
| content | TEXT | Inhoud (HTML) |
| post_date | DATE | Publicatiedatum |
| created_at | TIMESTAMP | Aanmaakdatum |
| updated_at | TIMESTAMP | Laatste wijziging |

### products
Producten catalogus (broden, gebak, etc.).

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| naam | VARCHAR(255) | Productnaam |
| ingredienten | TEXT | Ingrediëntenlijst |
| beschrijving | TEXT | Omschrijving |
| prijs | DECIMAL(10,2) | Prijs in EUR |
| foto | VARCHAR(255) | Pad naar afbeelding |
| created_at | TIMESTAMP | Aanmaakdatum |
| updated_at | TIMESTAMP | Laatste wijziging |

### settings
Applicatie-instellingen (key-value).

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| setting_key | VARCHAR(100) | Unieke sleutel |
| setting_value | TEXT | Waarde |
| updated_at | TIMESTAMP | Laatste wijziging |

**Belangrijke settings:**
- `btw_tarief` - BTW percentage (9 of 21)
- `facturatie_systeem` - 'eigen' of 'eboekhouden'
- `facturatie_moment` - 'voor_leverdag', 'op_leverdag', 'na_leverdag'
- `facturatie_uur` - Tijdstip voor cron (bijv. '17:00')
- `facturatie_dagen_offset` - Dagen voor/na leverdag
- `eboekhouden_api_token` - API token voor e-Boekhouden
- `eboekhouden_template_id_openstaand` - Factuur template ID
- `eboekhouden_ledger_id` - Grootboek voor omzet
- `eboekhouden_debiteuren_ledger_id` - Grootboek voor debiteuren

### donations
Crowdfunding donaties via Mollie.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| mollie_payment_id | VARCHAR(50) | Mollie betaling ID |
| amount | DECIMAL(10,2) | Bedrag in EUR |
| donor_name | VARCHAR(255) | Naam donateur |
| message | TEXT | Optioneel bericht |
| status | VARCHAR(20) | 'pending', 'paid', etc. |
| paid_at | TIMESTAMP | Betaaldatum |
| created_at | TIMESTAMP | Aanmaakdatum |

### login_attempts
Rate limiting voor login pogingen.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| identifier | VARCHAR(255) | IP of username |
| attempt_time | TIMESTAMP | Tijdstip poging |

---

## Business (B2B) Tabellen

### business_accounts
Zakelijke klanten accounts.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| bedrijfsnaam | VARCHAR(255) | Naam bedrijf |
| adres | TEXT | Factuuradres |
| postcode | VARCHAR(10) | Postcode |
| plaats | VARCHAR(100) | Plaats |
| contactpersoon | VARCHAR(255) | Naam contactpersoon |
| email | VARCHAR(255) | E-mailadres |
| telefoon | VARCHAR(20) | Telefoonnummer |
| website | VARCHAR(255) | Website URL |
| kvk_nummer | VARCHAR(20) | KVK nummer |
| btw_id | VARCHAR(30) | BTW-ID |
| opmerkingen | TEXT | Interne notities |
| status | ENUM | 'pending', 'approved', 'rejected' |
| password_hash | VARCHAR(255) | Gehashte wachtwoord |
| delivery_same_as_business | TINYINT(1) | Leveradres = factuuradres |
| delivery_adres | VARCHAR(255) | Afwijkend leveradres |
| delivery_postcode | VARCHAR(10) | Leveradres postcode |
| delivery_plaats | VARCHAR(100) | Leveradres plaats |
| delivery_contactpersoon | VARCHAR(255) | T.a.v. bij levering |
| created_at | TIMESTAMP | Aanvraagdatum |
| approved_at | TIMESTAMP | Goedkeuringsdatum |

### business_orders
Bestellingen van zakelijke klanten.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| account_id | INT FK | → business_accounts.id |
| delivery_date | DATE | Leverdatum |
| delivery_address | VARCHAR(500) | Leveradres (snapshot) |
| total_amount | DECIMAL(10,2) | Totaalbedrag excl. BTW |
| notes | TEXT | Opmerkingen bij bestelling |
| **Status velden** | | |
| payment_status | VARCHAR(20) | 'pending', 'paid' |
| payment_type | VARCHAR(20) | 'factuur', 'invoice', 'ideal', 'mollie_direct' |
| invoice_status | VARCHAR(20) | 'bestelbon', 'gefactureerd' |
| delivery_status | VARCHAR(20) | 'geplaatst', 'wordt_bereid', 'onderweg', 'afgeleverd' |
| order_status | VARCHAR(30) | Legacy: 'bestelbon', 'gefactureerd', 'afgeleverd' |
| is_cancelled | TINYINT(1) | Geannuleerd ja/nee |
| is_paused | TINYINT(1) | Gepauzeerd (voor recurring) |
| **Bestelbon/Factuur** | | |
| bestelbon_number | VARCHAR(50) | B2024-0001 formaat |
| invoice_number | VARCHAR(50) | F2024-0001 formaat (lokaal) |
| invoiced_at | DATETIME | Facturatiedatum |
| facturatie_systeem | VARCHAR(20) | 'eigen' of 'eboekhouden' |
| **e-Boekhouden** | | |
| eboekhouden_invoice_id | INT | Factuur ID in e-Boekhouden |
| eboekhouden_factuurnummer | VARCHAR(50) | Factuurnummer uit e-Boekhouden |
| eboekhouden_pdf_url | TEXT | URL naar PDF |
| **Mollie** | | |
| mollie_payment_id | VARCHAR(50) | Mollie betaling ID |
| mollie_status | VARCHAR(20) | Mollie status |
| mollie_status_updated_at | TIMESTAMP | Laatste status update |
| **Recurring velden** | | |
| is_recurring | TINYINT(1) | Terugkerende bestelling ja/nee |
| recurring_group_id | VARCHAR(50) | UUID voor groep |
| recurring_parent_id | INT | ID van eerste order in groep |
| recurring_name | VARCHAR(255) | Naam van de recurring |
| recurring_frequency | VARCHAR(20) | 'weekly', 'biweekly', 'monthly' |
| recurring_day | TINYINT | Dag van de week (1-7) |
| recurring_end_date | DATE | Einddatum recurring |
| recurring_confirmed_until | DATE | Bevestigd tot datum |
| recurring_order_id | INT | Legacy FK |
| is_auto_generated | TINYINT(1) | Auto-gegenereerd door cron |
| **Timestamps** | | |
| created_at | TIMESTAMP | Aanmaakdatum |
| updated_at | TIMESTAMP | Laatste wijziging |

**Indexes:**
- `idx_recurring_group` op `recurring_group_id`
- `idx_delivery_date` op `delivery_date`
- `idx_is_paused` op `is_paused`

### business_order_items
Producten binnen een bestelling.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| order_id | INT FK | → business_orders.id (CASCADE DELETE) |
| product_name | VARCHAR(255) | Productnaam |
| quantity | INT | Aantal |
| unit_price | DECIMAL(10,2) | Stukprijs excl. BTW |

### business_favorites
Opgeslagen favoriete bestellingen.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| account_id | INT FK | → business_accounts.id (CASCADE DELETE) |
| naam | VARCHAR(255) | Naam van favoriet |
| created_at | TIMESTAMP | Aanmaakdatum |

### business_favorite_items
Producten binnen een favoriet.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| favorite_id | INT FK | → business_favorites.id (CASCADE DELETE) |
| product_id | INT | Optioneel: → products.id |
| product_name | VARCHAR(255) | Productnaam |
| quantity | INT | Aantal |
| unit_price | DECIMAL(10,2) | Stukprijs |

### invoice_log
Log van gegenereerde (maand)facturen.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| account_id | INT FK | → business_accounts.id (CASCADE DELETE) |
| invoice_number | VARCHAR(50) | Factuurnummer |
| total_amount | DECIMAL(10,2) | Totaalbedrag |
| order_count | INT | Aantal orders |
| period_start | DATE | Periode start |
| period_end | DATE | Periode eind |
| created_at | DATETIME | Aanmaakdatum |

### renewal_reminders_sent
Tracking van verzonden herinneringen voor recurring verlenging.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT PK | Primaire sleutel |
| recurring_group_id | VARCHAR(50) | Recurring groep UUID |
| reminder_type | VARCHAR(20) | Type herinnering |
| sent_at | TIMESTAMP | Verzenddatum |

---

## Deprecated Tabellen

De volgende tabellen zijn deprecated en worden niet meer actief gebruikt:

### business_recurring_orders (deprecated)
Originele recurring orders tabel - vervangen door velden in `business_orders`.

### business_recurring_order_items (deprecated)
Items voor deprecated recurring orders tabel.

### orders (legacy)
Originele consumenten bestellingen - niet in gebruik.

---

## Relaties Samenvatting

| Parent | Child | Relatie | On Delete |
|--------|-------|---------|-----------|
| business_accounts | business_orders | 1:N | CASCADE |
| business_accounts | business_favorites | 1:N | CASCADE |
| business_accounts | invoice_log | 1:N | CASCADE |
| business_orders | business_order_items | 1:N | CASCADE |
| business_favorites | business_favorite_items | 1:N | CASCADE |

---

## Status Flows

### Payment Status
```
pending → paid
```

### Invoice Status
```
bestelbon → gefactureerd
```

### Delivery Status
```
geplaatst → wordt_bereid → onderweg → afgeleverd
```

### Paused Status (Recurring)
```
is_paused = 0 (actief) → is_paused = 1 (gepauzeerd) → is_paused = 0 (hervat)
                                                    → is_cancelled = 1 (gemist, als leverdatum verstreken)
```

### Payment Type Waarden
- `factuur` - Betaling op factuur (enkele bestelling)
- `invoice` - Betaling op factuur (recurring bestelling)
- `ideal` / `mollie_direct` - iDEAL betaling via Mollie
