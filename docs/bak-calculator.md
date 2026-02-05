# Bak Calculator

Interne tool voor de bakker om recepten te berekenen op basis van baker's percentages.

## Locatie

- **Pagina**: `admin/bakker/bakcalculator.php`
- **API**: `api/baker-recipes.php`
- **Migration**: `admin/migrations/010_baker_recipes.php`
- **Navigatie**: Toegankelijk via admin dashboard en bakker-dashboard

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

1. **Recept** - Basisinstellingen: aantal bollen/broden, gewicht per stuk, hydratatie, zoutpercentage, rijsmiddel
2. **Meel & Voordeeg** - Meelsoorten configureren voor hoofd- en voordeeg, voordeeg toggle met eigen hydratatie
3. **Toevoegingen** - Mix-ins (% van meel of deeg) en toppings (% van deeg)
4. **Overzicht** - Compleet receptoverzicht met baker's percentages tabel
5. **Methode** - Vrij tekstveld voor bereidingswijze
6. **Recepten** - Opgeslagen recepten laden, bewerken en verwijderen

### Live Berekening Sidebar

Sticky sidebar rechts met real-time berekeningen:
- Totaal meel, water, zout, rijsmiddel
- Voordeeg details (indien actief)
- Mix-ins per categorie (geintegreerd, vast, vloeistof)
- Toppings totaal
- Gewicht per stuk en totaalgewicht
- Visuele percentagebalk (meel/water/overig)

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
- **Geintegreerd (integrated)**: Lijnzaad, chiazaad, havervlokken, boter, kaas, ei, suiker, etc. (tellen mee voor zoutberekening)
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

## Recept Data Structuur (JSON)

```json
{
  "numberOfBalls": 24,
  "weightPerBall": 300,
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
