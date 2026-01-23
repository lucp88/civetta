# e-Boekhouden REST API Documentatie

## Authenticatie

### Sessie starten
```
POST /v1/session
```

**Request:**
```json
{
    "accessToken": "jouw-api-token",
    "source": "App Naam"
}
```

**Response:**
```json
{
    "token": "sessie-token-voor-verdere-requests"
}
```

Alle verdere requests gebruiken:
```
Authorization: Bearer {sessie-token}
```

---

## Relaties

### Relaties ophalen
```
GET /v1/relation
```

**Query parameters:**
- `search` - Zoekterm (bijv. email)

**Response:**
```json
{
    "data": [
        {
            "id": 68216338,
            "name": "Bedrijfsnaam",
            "emailAddress": "email@example.com",
            "type": "B"
        }
    ]
}
```

### Relatie aanmaken
```
POST /v1/relation
```

**Request:**
```json
{
    "type": "B",
    "name": "Bedrijfsnaam",
    "contact": "Contactpersoon",
    "emailAddress": "email@example.com",
    "phoneNumber": "0612345678",
    "address": "Straat 123",
    "postalCode": "1234 AB",
    "city": "Plaats",
    "country": "Nederland",
    "companyRegistrationNumber": "12345678",
    "vatNumber": "NL123456789B01"
}
```

| Veld | Type | Verplicht | Beschrijving |
|------|------|-----------|--------------|
| type | string | Ja | "B" = Beide (debiteur + crediteur) |
| name | string | Ja | Bedrijfsnaam |
| contact | string | Nee | Contactpersoon |
| emailAddress | string | Nee | E-mailadres |
| phoneNumber | string | Nee | Telefoonnummer (10 cijfers) |
| address | string | Nee | Adres |
| postalCode | string | Nee | Postcode (formaat: "1234 AB") |
| city | string | Nee | Plaats |
| country | string | Nee | Land |
| companyRegistrationNumber | string | Nee | KvK nummer (8 cijfers) |
| vatNumber | string | Nee | BTW nummer |

---

## Facturen

### Factuur aanmaken
```
POST /v1/invoice
```

**Request:**
```json
{
    "relationId": 68216338,
    "date": "2026-01-21",
    "termOfPayment": 14,
    "templateId": 1486769,
    "items": [
        {
            "description": "Product omschrijving",
            "pricePerUnit": 4.00,
            "quantity": 1,
            "vatCode": "LAAG_VERK_9",
            "ledgerId": 48086686
        }
    ],
    "email": {
        "fromEmail": "info@example.nl",
        "fromName": "Bedrijfsnaam",
        "subject": "Uw factuur",
        "body": "HTML body van de email"
    }
}
```

| Veld | Type | Verplicht | Beschrijving |
|------|------|-----------|--------------|
| relationId | integer | Ja | ID van de relatie |
| date | string | Ja | Factuurdatum (YYYY-MM-DD) |
| termOfPayment | integer | Ja | Betaaltermijn in dagen |
| templateId | integer | Ja | ID van het factuurtemplate |
| items | array | Ja | Array met factuurregels |
| email | object | Nee | Email instellingen (indien meegegeven wordt factuur gemaild) |

**Item velden:**

| Veld | Type | Verplicht | Beschrijving |
|------|------|-----------|--------------|
| description | string | Ja | Omschrijving |
| pricePerUnit | float | Ja | Prijs per eenheid (excl. BTW) |
| quantity | integer | Ja | Aantal |
| vatCode | string | Ja | BTW code |
| ledgerId | integer | Ja | Grootboekrekening ID |

**Response:**
```json
{
    "id": 64701435,
    "invoiceNumber": "F00030"
}
```

### Factuur ophalen
```
GET /v1/invoice/{id}
```

**Response:**
```json
{
    "id": 64701435,
    "invoiceNumber": "Civetta_00013",
    "relationId": 68217630,
    "date": "2026-01-21",
    "reference": "",
    "text": "",
    "termOfPayment": 14,
    "inExVat": "EX",
    "templateId": 1486769,
    "totalExcl": 4,
    "totalAmount": 4.36,
    "vatAmount": 0.36,
    "urlPdfFile": "https://secure20.e-boekhouden.nl/v1/api/factuur/download/pdf?c=...",
    "items": [...]
}
```

---

## Mutaties

### Mutaties ophalen
```
GET /v1/mutation
```

**Query parameters:**
- `dateFrom` - Datum vanaf (YYYY-MM-DD)

**Response:**
```json
{
    "items": [
        {
            "id": 30,
            "type": 2,
            "date": "2026-01-21",
            "invoiceNumber": "Civetta_00000",
            "ledgerId": 48086677,
            "amount": 9,
            "entryNumber": ""
        }
    ],
    "count": 1
}
```

### Mutatie aanmaken (Factuur boeken op debiteuren)
```
POST /v1/mutation
```

**Request:**
```json
{
    "type": "2",
    "date": "2026-01-21",
    "ledgerId": 48086677,
    "invoiceNumber": "Civetta_00013",
    "relationId": 68217630,
    "inExVat": "EX",
    "rows": [
        {
            "ledgerId": 48086686,
            "vatCode": "LAAG_VERK_9",
            "amount": 4.00,
            "description": "Factuur Civetta_00013"
        }
    ]
}
```

| Veld | Type | Verplicht | Beschrijving |
|------|------|-----------|--------------|
| type | string | Ja | Mutatie type ("2" = verkoopfactuur) |
| date | string | Ja | Datum (YYYY-MM-DD) |
| ledgerId | integer | Ja | Hoofdgrootboek ID (bijv. 1300 Debiteuren) |
| invoiceNumber | string | Ja | Factuurnummer |
| relationId | integer | Ja | Relatie ID |
| inExVat | string | Ja | "EX" (excl. BTW) of "IN" (incl. BTW) |
| rows | array | Ja | Minimaal 1 boekingsregel |

**Row velden:**

| Veld | Type | Verplicht | Beschrijving |
|------|------|-----------|--------------|
| ledgerId | integer | Ja | Grootboekrekening ID (bijv. 8000 Omzet) |
| vatCode | string | Ja | BTW code |
| amount | float | Ja | Bedrag (excl. BTW als inExVat="EX") |
| description | string | Nee | Omschrijving |

**Response:**
```json
{
    "id": 31
}
```

---

## Grootboekrekeningen

### Grootboeken ophalen
```
GET /v1/ledger
```

**Response:**
```json
{
    "items": [
        {
            "id": 48086677,
            "code": "1300",
            "description": "Debiteuren",
            "category": "DEB"
        },
        {
            "id": 48086686,
            "code": "8000",
            "description": "Omzet groep 1",
            "category": "VW"
        }
    ],
    "count": 42
}
```

**Veelgebruikte grootboeken:**

| Code | Beschrijving | Gebruik |
|------|--------------|---------|
| 1300 | Debiteuren | Openstaande facturen |
| 8000 | Omzet groep 1 | Verkoopomzet |
| 1010 | Bank | Bankrekening |

---

## Factuur Templates

### Templates ophalen
```
GET /v1/invoicetemplate
```

---

## BTW Codes

| Code | Beschrijving |
|------|--------------|
| LAAG_VERK_9 | BTW laag tarief verkoop (9%) |
| HOOG_VERK_21 | BTW hoog tarief verkoop (21%) |

---

## Error Codes

| Code | Beschrijving |
|------|--------------|
| MUT_007 | Onbekende grootboekrekening |
| MUT_012 | Relatie ontbreekt |
| MUT_017 | inExVat moet 'IN' of 'EX' zijn |
| MUT_100 | Rows ontbreken |

---

## Belangrijke Notes

1. **Alle ID's zijn integers** - Gebruik `intval()` bij het meegeven van ID's
2. **Bedragen zijn floats** - Gebruik `floatval()` voor bedragen
3. **Type bij mutaties is een string** - Gebruik `"2"` niet `2`
4. **inExVat is verplicht bij mutaties** - Meestal "EX" voor excl. BTW
5. **rows array is verplicht bij mutaties** - Minimaal 1 regel
6. **relationId is verplicht bij mutaties** - Ook al geef je invoiceNumber mee

---

## Flow: Factuur aanmaken + boeken

1. **Relatie ophalen/aanmaken** via `/v1/relation`
2. **Factuur aanmaken** via `POST /v1/invoice`
3. **Factuur details ophalen** via `GET /v1/invoice/{id}` (voor totaalbedragen)
4. **Mutatie aanmaken** via `POST /v1/mutation` (boekt factuur op debiteuren)

Na stap 4 verschijnt de factuur in:
- Mutaties overzicht
- Openstaande posten
- Grootboekkaart debiteuren (1300)
