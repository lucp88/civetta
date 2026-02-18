# Bak Calculator

Interne tool voor de bakker om recepten te berekenen op basis van baker's percentages.

## Locatie

- **Pagina**: `admin/bakker/bakcalculator.php`
- **Dagproductie**: `admin/bakker/dagproductie.php`
- **API**: `api/baker-recipes.php`
- **Dagproductie API**: `api/production-day.php`
- **PDF API**: `api/recipe-pdf.php`
- **PDF Generator**: `lib/recipe/RecipePDF.php`
- **Migration recepten**: `admin/migrations/010_baker_recipes.php`
- **Migration product-recept link**: `admin/migrations/011_product_recipe_link.php`
- **Navigatie**: Toegankelijk via admin dashboard, bakker-dashboard en bereiden pagina

## Database

### Tabel: `baker_recipes`

| Kolom | Type | Beschrijving |
|-------|------|-------------|
| id | INT AUTO_INCREMENT | Primary key |
| name | VARCHAR(255) | Receptnaam |
| recipe_data | JSON | Volledige receptconfiguratie |
| created_at | TIMESTAMP | Aanmaakdatum |
| updated_at | TIMESTAMP | Laatste wijziging |

**Migration draaien**: `php admin/migrations/010_baker_recipes.php`

### Product-Recept Koppeling

Producten kunnen gekoppeld worden aan recepten via:

| Kolom | Type | Beschrijving |
|-------|------|-------------|
| products.recipe_id | INT NULL | FK naar baker_recipes.id |
| products.standard_weight | INT DEFAULT 300 | Standaard gewicht per stuk (gram) |

**Migration draaien**: `php admin/migrations/011_product_recipe_link.php`

## API Endpoints

`api/baker-recipes.php` - Vereist admin login.

| Methode | Parameters | Beschrijving |
|---------|-----------|-------------|
| GET | - | Lijst van alle recepten (id, name, created_at, updated_at) |
| GET | `?id=X` | Enkel recept ophalen met volledige recipe_data |
| POST | `{name, recipe_data}` | Nieuw recept opslaan, retourneert `{success, id}` |
| PUT | `{id, name, recipe_data}` | Bestaand recept bijwerken |
| DELETE | `?id=X` | Recept verwijderen |

## Functionaliteit

### 6 Tabs

1. **Recept** - Basisinstellingen: aantal bollen/broden, gewicht per stuk, hydratatie, zoutpercentage, rijsmiddel, "gewicht uit bestelling" toggle
2. **Meel & Voordeeg** - Meelsoorten configureren voor hoofd- en voordeeg, voordeeg toggle met eigen hydratatie
3. **Toevoegingen** - Mix-ins (% van meel of deeg) en toppings (% van deeg)
4. **Overzicht** - Compleet receptoverzicht met baker's percentages tabel, print PDF knop
5. **Methode** - Vrij tekstveld voor bereidingswijze
6. **Recepten** - Opgeslagen recepten laden, bewerken, dupliceren en verwijderen

### Live Berekening Sidebar

Sticky sidebar rechts met real-time berekeningen:
- Totaal meel, water, zout, rijsmiddel
- Totaal volkoren percentage (berekend uit alle meelsoorten)
- Voordeeg details (indien actief)
- Mix-ins per categorie (geintegreerd, vast, vloeistof)
- Toppings totaal
- Gewicht per stuk en totaalgewicht
- Visuele percentagebalk (meel/water/overig)

**Gewicht uit bestelling modus:** Wanneer `weightFromOrder` actief is, toont de sidebar alleen percentages (geen gewichten).

### Meelsoorten (10)

Tarwe, Spelt, Durum, Emmer, Rogge, Einkorn, Boekweit, Rijst, Gerst, Teff

Per meelsoort instelbaar:
- Aandeel percentage (verhouding t.o.v. andere meelsoorten)
- Volkoren percentage (0-100%)

### Rijsmiddelen (5)

| Type | Standaard % |
|------|------------|
| Geen | 0% |
| Zuurdesem | 20% |
| Desemcultuur | 4% |
| Verse gist | 4% |
| Instant gist | 2.8% |

### Mix-ins (48 ingredienten)

Drie categorieen:
- **Vast (non-integrated)**: Zonnebloempitten, pompoenpitten, noten, gedroogd fruit, kruiden, etc.
- **Geintegreerd (integrated)**: Lijnzaad, chiazaad, havervlokken, boter, kaas, ei, suiker, etc. (tellen mee voor totalDryWeight en dus zoutberekening)
- **Vloeistof (liquid)**: Extra water, melk, karnemelk, koffie, bier, appelciderazijn

Modus instelbaar: percentage van meel OF percentage van deeg.

### Toppings (23)

Zadenmix, pitten, noten, kruiden, zeezoutvlokken, suiker, groenten, etc. Percentage van deeggewicht.

### Voordeeg (Pre-ferment)

- Percentage van totaal meel
- Eigen hydratatie (onafhankelijk van hoofddeeg)
- Eigen meelsoorten configuratie
- Effectieve hydratatie hoofddeeg wordt automatisch herberekend

## Berekeningslogica

```
totalDoughWeight = numberOfBalls * weightPerBall
totalFlour = totalDoughWeight / (1 + hydration/100)
totalWater = totalFlour * (hydration/100)

// Voordeeg
preFermentWeight = totalFlour * (preFermentPct/100)    [= meel+water in voordeeg]
preFermentFlour = preFermentWeight / (1 + preFermentHydration/100)
preFermentWater = preFermentWeight - preFermentFlour

// Hoofddeeg (rest)
mainDoughFlour = totalFlour - preFermentFlour
mainDoughWater = totalWater - preFermentWater

// Zout: gebaseerd op totalDryWeight (meel + geintegreerde mix-ins)
totalDryWeight = totalFlour + integratedMixinWeight
saltWeight = totalDryWeight * (saltPct/100)

// Rijsmiddel
levenerWeight = totalFlour * (levenerPct/100)

// Eindgewicht
totalFinalWeight = totalDoughWeight + saltWeight + levenerWeight + mixinWeight + toppingWeight
finalWeightPerBall = totalFinalWeight / numberOfBalls
```

## Product-Recept Koppeling

Producten kunnen gekoppeld worden aan recepten voor automatische ingrediëntenberekening.

### Configuratie in Product Bewerken

In `admin/producten/product-edit.php`:
- **Recept dropdown**: Selecteer een opgeslagen recept uit de calculator
- **Standaard gewicht**: Gewicht per stuk voor berekeningen (default 300g)

### Bereiden Pagina

De bereiden pagina (`admin/bakker/bereiden.php`) toont nu:
- **Producten tab**: Alle producten met aantallen en bedragen
- **Recepten tab**: Producten gegroepeerd per recept met totaal stuks en kg
- **Bekijk ingrediënten knop**: Link naar dagproductie pagina

### Dagproductie Pagina

`admin/bakker/dagproductie.php` toont voor een bereidingsdag:
- Overzicht van alle recepten met benodigde aantallen
- **Per recept:**
  - Welke producten dit recept gebruiken
  - Totaal meel (per meelsoort)
  - Water en zout
  - Rijsmiddelen (gist, zuurdesem)
  - Voordeeg berekeningen (indien van toepassing)
  - Mix-ins en toppings
- Print-vriendelijke layout voor in de bakkerij
- Waarschuwing voor producten zonder gekoppeld recept

### Dagproductie API

`api/production-day.php?date=YYYY-MM-DD`

Retourneert JSON met:
```json
{
  "success": true,
  "date": "2024-01-15",
  "delivery_date": "2024-01-16",
  "recipes": [{
    "id": 1,
    "name": "Volkoren",
    "products": {"Volkoren 400g": {"qty": 24, "weight": 400}},
    "total_qty": 24,
    "total_weight": 9600,
    "ingredients": {
      "totalFlour": 5800,
      "totalWater": 3596,
      "saltWeight": 151,
      "grains": [{"name": "Tarwe volkoren", "weight": 5800, "pct": 100}],
      "leveners": [{"name": "Instant gist", "weight": 81, "pct": 1.4}],
      "sourdough": null,
      "preFerment": null,
      "mixins": [],
      "toppings": []
    }
  }],
  "no_recipe": {"products": {...}, "total_qty": 5},
  "totals": {"products": 29, "weight": 10500, "recipe_count": 1}
}
```

## Gewicht uit Bestelling Toggle

### `weightFromOrder: false` (standaard)
- Gebruiker vult handmatig in: aantal bollen × gewicht per stuk
- Calculator berekent exacte gewichten

### `weightFromOrder: true`
- Aantal en gewicht velden zijn verborgen
- Sidebar toont alleen percentages, geen gewichten
- Overzicht toont alleen percentages
- Baker's Percentages tabel verbergt gewichtskolom
- Print PDF toont alleen percentages
- Recept slaat alleen percentages op
- Gewichten worden berekend via dagproductie pagina

## Overzicht Tab

Het overzicht toont:
- Receptnaam en basisinstellingen
- Meelsoorten met percentages en volkoren aandeel
- Totaal volkoren percentage
- Baker's Percentages tabel (meel, water, zout, rijsmiddel, toevoegingen)
- Print Recept knop (genereert PDF via `api/recipe-pdf.php`)

**Gewicht uit bestelling modus:** Wanneer actief, toont het overzicht alleen percentages. De gewichtskolom in Baker's Percentages is verborgen.

## Recepten Tab

- **Laden**: Klik op een opgeslagen recept om te laden
- **Opslaan**: Sla het huidige recept op (overschrijft bij bestaande naam)
- **Dupliceren**: Maak een kopie van het geladen recept met nieuwe naam
- **Verwijderen**: Verwijder een opgeslagen recept

## Print Recept (PDF)

Genereert een PDF van het recept via FPDF:
- Receptnaam, datum
- Basisinstellingen (aantal, gewicht, hydratatie, zout, rijsmiddel)
- Meelsoorten tabel met volkoren percentages
- Baker's Percentages tabel
- Bereidingsmethode (indien ingevuld)

Bij `weightFromOrder` modus worden alleen percentages getoond.

## Recept Data Structuur (JSON)

```json
{
  "numberOfBalls": 24,
  "weightPerBall": 300,
  "weightFromOrder": false,
  "hydration": 62,
  "saltPct": 2.6,
  "levenerType": "instant_yeast",
  "levenerPct": 1.3,
  "levenerInPreFermentPct": 0,
  "usePreFerment": false,
  "preFermentPct": 20,
  "preFermentHydration": 100,
  "preFermentGrains": [{"type": "wheat", "pct": 100, "wholeGrainPct": 0}],
  "mainDoughGrains": [{"type": "wheat", "pct": 100, "wholeGrainPct": 0}],
  "mixinMode": "flour",
  "mixins": [{"ingredient": "Walnoten", "pct": 5, "category": "non-integrated"}],
  "toppings": [{"ingredient": "Sesamzaad", "pct": 2}],
  "method": "Beschrijving bereidingswijze..."
}
```
