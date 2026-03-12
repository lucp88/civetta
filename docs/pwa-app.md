# Civetta Bakker - Progressive Web App (PWA)

De bakker-pagina's zijn beschikbaar als installeerbare app via PWA-technologie. De app draait in een standalone venster, werkt offline, ontvangt push notificaties bij nieuwe bestellingen en wordt automatisch bijgewerkt bij code-wijzigingen.

## Bestanden

| Bestand | Beschrijving |
|---------|-------------|
| `admin/manifest.json` | PWA manifest (app naam, icoon, start URL, display mode) |
| `admin/sw.js` | Service worker (caching, offline fallback, push handler) |
| `admin/index.php` | Install-sectie en push notificatie toggle |
| `lib/web-push.php` | Web Push server-side (VAPID keys, encryptie, verzending) |
| `api/push-subscriptions.php` | API: subscribe, unsubscribe, VAPID public key |
| `admin/migrations/011_push_subscriptions.php` | Database migratie voor push subscriptions |

## Scope

De PWA scope is `/admin/`. Alle pagina's onder `/admin/bakker/` bevatten:
- `<link rel="manifest">` naar het manifest
- `<meta name="theme-color">` (per pagina)
- `<meta name="apple-mobile-web-app-capable">` voor iOS
- `<link rel="apple-touch-icon">` voor iOS icoon
- Service worker registratie script

## Platform Support

| Platform | Browser | Installatie |
|----------|---------|-------------|
| Android | Chrome | Automatisch install prompt via `beforeinstallprompt` |
| macOS | Chrome / Edge | Automatisch install prompt via `beforeinstallprompt` |
| iOS / iPadOS | Safari | Handmatig: Delen > Zet op beginscherm > Voeg toe |
| Windows | Chrome / Edge | Automatisch install prompt via `beforeinstallprompt` |

Op iOS/iPadOS wordt een visuele stap-voor-stap instructie getoond in plaats van een install-knop. De detectie werkt op zowel iPhone als iPad (inclusief `navigator.maxTouchPoints > 1` voor iPadOS).

## Automatische Updates

De app updatet automatisch. De service worker gebruikt een **network-first** strategie:

1. Haalt altijd eerst de nieuwste versie op van de server
2. Slaat een kopie op in de cache
3. Bij geen netwerk: toont de gecachte versie

Bij grote wijzigingen: verhoog `CACHE_NAME` in `admin/sw.js` (bijv. `civetta-bakker-v2` -> `civetta-bakker-v3`). De oude cache wordt automatisch verwijderd bij activatie.

## Service Worker Caching

### Precache (bij installatie)
- `bakker-dashboard.php`, `bereiden.php`, `leveren.php`, `bakcalculator.php`
- `css/admin-bakker.css`, `js/bakker-calendar.js`
- `img/logo.jpeg`
- Bootstrap Icons CSS (CDN)

### Runtime caching
- **Pagina's/assets**: Network-first met cache fallback
- **API calls** (`/api/*`): Network-only, bij offline een JSON error response `{ success: false, error: 'Offline' }`

## Install Sectie (admin/index.php)

De "Civetta Bakker App" sectie in het admin dashboard:

- **Verborgen** als de app al geinstalleerd is (standalone mode)
- **Chrome/Edge/Android**: Toont een "Installeer App" knop die `beforeinstallprompt` triggert
- **iOS/iPadOS**: Toont stap-voor-stap instructie met Safari share-icoon
- **Na installatie**: Sectie verdwijnt automatisch via `appinstalled` event

## Iconen

Momenteel wordt `img/logo.jpeg` gebruikt als app-icoon. Voor een betere ervaring op alle platformen:

1. Maak een 512x512 PNG icoon aan (bijv. `img/icon-512.png`)
2. Maak een 192x192 PNG icoon aan (bijv. `img/icon-192.png`)
3. Voeg toe aan `admin/manifest.json`:

```json
"icons": [
    { "src": "/img/icon-192.png", "sizes": "192x192", "type": "image/png" },
    { "src": "/img/icon-512.png", "sizes": "512x512", "type": "image/png" }
]
```

## Push Notificaties

Admin-gebruikers ontvangen een push notificatie wanneer een klant een bestelling plaatst.

### Setup

1. Draai de migratie: `php admin/migrations/011_push_subscriptions.php`
2. Ga naar het admin dashboard en klik **"Notificaties inschakelen"**
3. VAPID keys worden automatisch gegenereerd en opgeslagen in de `settings` tabel (`vapid_public_key`, `vapid_private_key`)

### Werking

1. Admin schakelt notificaties in via de toggle op het dashboard
2. Browser vraagt toestemming en maakt een push subscription aan
3. Subscription (endpoint + keys) wordt opgeslagen in `push_subscriptions` tabel
4. Bij een nieuwe bestelling (`api/business-orders.php`, `api/admin-orders.php`) wordt `sendPushNotification()` aangeroepen
5. Server versleutelt de payload (AES-128-GCM) en stuurt via VAPID-gesigneerde request naar de push service
6. Service worker ontvangt het push event en toont een notificatie
7. Klik op de notificatie opent het bakker dashboard

### Platform Support (Push)

| Platform | Push Support |
|----------|-------------|
| Chrome / Edge (desktop) | Ja, ook als browser op achtergrond draait |
| Chrome / Edge (Android) | Ja, ook als browser gesloten is |
| Firefox (desktop) | Ja |
| iOS / iPadOS (Safari) | Alleen als PWA geïnstalleerd, iOS 16.4+ |

Op iOS in Safari (niet-geïnstalleerd) wordt een hint getoond om eerst de app te installeren.

### Technische Details

- **Encryptie**: RFC 8291 (aes128gcm) - ECDH key exchange + AES-128-GCM
- **Authenticatie**: VAPID (RFC 8292) - ES256 gesigneerde JWT
- **Crypto**: Volledig in PHP via OpenSSL (geen externe dependencies)
- **Cleanup**: Ongeldige subscriptions (HTTP 400+) worden automatisch verwijderd
- **Auto-subscribe**: Bakker-pagina's herregistreren automatisch als permission al granted is

### Database

```sql
push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint TEXT NOT NULL,
    key_p256dh VARCHAR(255) NOT NULL,
    key_auth VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_endpoint (endpoint(500))
)
```

VAPID keys worden opgeslagen in de `settings` tabel als `vapid_public_key` en `vapid_private_key`.
