<?php
require_once 'config.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Civetta Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f2ed;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 1.5rem; }
        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
        }
        .container {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .welcome {
            margin-bottom: 2rem;
        }
        .welcome h2 {
            color: #5c3d1e;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        .welcome p {
            color: #666;
        }
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        .dashboard-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 2rem;
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s, box-shadow 0.2s;
            display: block;
        }
        .dashboard-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .dashboard-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }
        .dashboard-card .icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.8rem;
        }
        .dashboard-card.disabled .icon {
            background: linear-gradient(135deg, #999, #666);
        }
        .dashboard-card h3 {
            color: #5c3d1e;
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
        }
        .dashboard-card.disabled h3 {
            color: #888;
        }
        .dashboard-card p {
            color: #666;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .dashboard-card .badge {
            display: inline-block;
            background: #e8dfd2;
            color: #8b5a2b;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-top: 1rem;
        }
        .dashboard-card.disabled .badge {
            background: #ddd;
            color: #888;
        }
        .quick-links {
            margin-top: 2rem;
            padding: 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .quick-links h3 {
            color: #5c3d1e;
            margin-bottom: 1rem;
        }
        .quick-links a {
            color: #8b5a2b;
            text-decoration: none;
            margin-right: 1.5rem;
        }
        .quick-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Civetta Admin</h1>
        <a href="logout.php">Uitloggen</a>
    </div>
    
    <div class="container">
        <div class="welcome">
            <h2>Welkom terug!</h2>
            <p>Beheer hier je bakkerij website.</p>
        </div>

        <div class="dashboard-grid">
            <a href="bakker/bakker-dashboard.php" class="dashboard-card" style="grid-column: 1 / -1; background: linear-gradient(135deg, #fff9e6, #fff3cd);">
                <div class="icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">📅</div>
                <h3>Bakker Planning</h3>
                <p>Kalenderoverzicht met bestellingen om te bereiden en te leveren. Inclusief route planning.</p>
                <span class="badge" style="background: #fef3c7; color: #92400e;">Nieuw</span>
            </a>

            <a href="bakker/bakcalculator.php" class="dashboard-card" style="background: linear-gradient(135deg, #fdf6e9, #f5e6cc);">
                <div class="icon" style="background: linear-gradient(135deg, #c8913a, #a0722e);">🧮</div>
                <h3>Bak Calculator</h3>
                <p>Baker's Math tool voor receptberekeningen. Meelsoorten, voordeeg, toevoegingen en meer.</p>
                <span class="badge" style="background: #f5e6cc; color: #8b5a2b;">Nieuw</span>
            </a>

            <a href="blog/posts.php" class="dashboard-card">
                <div class="icon">📝</div>
                <h3>Blog Posts</h3>
                <p>Schrijf en beheer nieuws berichten voor je website.</p>
                <span class="badge">Actief</span>
            </a>

            <a href="donaties/donations.php" class="dashboard-card">
                <div class="icon">💚</div>
                <h3>Donaties</h3>
                <p>Bekijk ontvangen crowdfunding donaties.</p>
                <span class="badge">Actief</span>
            </a>

            <a href="producten/products.php" class="dashboard-card">
                <div class="icon">🥖</div>
                <h3>Producten</h3>
                <p>Beheer je bakkerij producten.</p>
                <span class="badge">Actief</span>
            </a>

            <a href="accounts/accounts.php" class="dashboard-card">
                <div class="icon">👥</div>
                <h3>Accounts beheren</h3>
                <p>Beheer zakelijke en particuliere accounts.</p>
                <span class="badge">Actief</span>
            </a>

            <a href="bestellingen/orders.php" class="dashboard-card">
                <div class="icon">📦</div>
                <h3>Bestellingen</h3>
                <p>Bekijk en beheer klant bestellingen.</p>
                <span class="badge">Actief</span>
            </a>

            <a href="settings/settings-bedrijf.php" class="dashboard-card">
                <div class="icon">⚙️</div>
                <h3>Bedrijfsgegevens</h3>
                <p>Beheer je bedrijfsgegevens voor facturen en contact.</p>
                <span class="badge">Settings</span>
            </a>

            <a href="settings/settings-boekhouding.php" class="dashboard-card">
                <div class="icon">📊</div>
                <h3>Boekhouding</h3>
                <p>Facturatie instellingen en e-Boekhouden koppeling.</p>
                <span class="badge">Settings</span>
            </a>
        </div>

        <div class="apps-section" id="appsSection" style="display:none; margin-top: 2rem; padding: 1.5rem; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08);">
            <h3 style="color: #5c3d1e; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <span style="font-size: 1.3rem;">📱</span> Civetta Bakker App
            </h3>
            <p style="color: #666; font-size: 0.9rem; margin-bottom: 1.25rem;">
                Installeer de bakker app voor snelle toegang. De app werkt offline en wordt automatisch bijgewerkt met website-wijzigingen.
            </p>
            <div id="installChrome" style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <button id="installBtn" onclick="installPWA()" style="padding: 0.7rem 1.5rem; background: linear-gradient(135deg, #8b5a2b, #5c3d1e); color: white; border: none; border-radius: 8px; font-size: 0.95rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;">
                    <span id="installIcon">💻</span> Installeer App
                </button>
            </div>
            <div id="installIOS" style="display: none;">
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <span style="background: #f0f0f0; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #5c3d1e; flex-shrink: 0;">1</span>
                        <span style="color: #333; font-size: 0.95rem;">Tik op het <strong>Delen</strong>-icoon <span style="display: inline-block; border: 1px solid #ccc; border-radius: 4px; padding: 0 4px; font-size: 1.1rem; vertical-align: middle; line-height: 1.4;">&#xFEFF;<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#007AFF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle;"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg></span> onderaan het scherm</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <span style="background: #f0f0f0; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #5c3d1e; flex-shrink: 0;">2</span>
                        <span style="color: #333; font-size: 0.95rem;">Scroll naar beneden en tik op <strong>Zet op beginscherm</strong></span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <span style="background: #f0f0f0; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #5c3d1e; flex-shrink: 0;">3</span>
                        <span style="color: #333; font-size: 0.95rem;">Tik op <strong>Voeg toe</strong> rechtsboven</span>
                    </div>
                </div>
            </div>
            <p style="color: #999; font-size: 0.8rem; margin-top: 0.75rem;" id="installHint"></p>
        </div>

        <div id="notifSection" style="display:none; margin-top: 2rem; padding: 1.5rem; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08);">
            <h3 style="color: #5c3d1e; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <span style="font-size: 1.3rem;">🔔</span> Push Notificaties
            </h3>
            <p style="color: #666; font-size: 0.9rem; margin-bottom: 1.25rem;">
                Ontvang een melding als een klant een bestelling plaatst.
            </p>
            <button id="notifBtn" onclick="togglePushNotifications()" style="padding: 0.7rem 1.5rem; background: linear-gradient(135deg, #8b5a2b, #5c3d1e); color: white; border: none; border-radius: 8px; font-size: 0.95rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;">
                Notificaties inschakelen
            </button>
            <p style="color: #999; font-size: 0.8rem; margin-top: 0.75rem;" id="notifHint"></p>
        </div>

        <div class="quick-links">
            <h3>Snelle links</h3>
            <a href="../index.html" target="_blank">Bekijk website</a>
            <a href="../financiering.html" target="_blank">Crowdfunding pagina</a>
            <a href="../contact.html" target="_blank">Contact pagina</a>
        </div>
    </div>

    <script>
    let deferredPrompt = null;
    const isIOS = /iphone|ipad|ipod/.test(navigator.userAgent.toLowerCase()) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;

    if (isIOS && !isStandalone) {
        document.getElementById('appsSection').style.display = '';
        document.getElementById('installChrome').style.display = 'none';
        document.getElementById('installIOS').style.display = '';
        document.getElementById('installHint').textContent = 'Gebruik Safari om de app te installeren.';
    }

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        showInstallSection();
    });

    function showInstallSection() {
        document.getElementById('appsSection').style.display = '';
        document.getElementById('installChrome').style.display = '';
        document.getElementById('installIOS').style.display = 'none';
        const ua = navigator.userAgent.toLowerCase();
        const hint = document.getElementById('installHint');
        if (/android/.test(ua)) {
            document.getElementById('installIcon').textContent = '🤖';
            hint.textContent = 'Wordt toegevoegd aan je startscherm.';
        } else if (/macintosh|mac os/.test(ua)) {
            document.getElementById('installIcon').textContent = '💻';
            hint.textContent = 'Wordt geïnstalleerd als macOS app. Gebruik Chrome of Edge.';
        } else {
            hint.textContent = 'Gebruik Chrome of Edge om de app te installeren.';
        }
    }

    function installPWA() {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then((choice) => {
                if (choice.outcome === 'accepted') {
                    document.getElementById('installBtn').textContent = '✓ Geïnstalleerd';
                    document.getElementById('installBtn').disabled = true;
                    document.getElementById('installBtn').style.background = '#2e7d32';
                }
                deferredPrompt = null;
            });
        }
    }

    window.addEventListener('appinstalled', () => {
        document.getElementById('appsSection').style.display = 'none';
    });

    if (isStandalone) {
        document.getElementById('appsSection').style.display = 'none';
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js', { scope: '/admin/' });
    }

    async function initPushUI() {
        if (!('PushManager' in window) || !('serviceWorker' in navigator)) return;

        const isIOSDevice = /iphone|ipad|ipod/.test(navigator.userAgent.toLowerCase()) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const isStandaloneMode = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
        if (isIOSDevice && !isStandaloneMode) {
            document.getElementById('notifHint').textContent = 'Installeer eerst de app (via Delen > Zet op beginscherm), daarna kun je notificaties inschakelen.';
            document.getElementById('notifSection').style.display = '';
            document.getElementById('notifBtn').style.display = 'none';
            return;
        }

        document.getElementById('notifSection').style.display = '';

        const perm = Notification.permission;
        if (perm === 'denied') {
            document.getElementById('notifBtn').textContent = 'Notificaties geblokkeerd';
            document.getElementById('notifBtn').disabled = true;
            document.getElementById('notifBtn').style.background = '#999';
            document.getElementById('notifHint').textContent = 'Je hebt notificaties geblokkeerd. Wijzig dit in je browserinstellingen.';
            return;
        }

        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
            document.getElementById('notifBtn').textContent = 'Notificaties uitschakelen';
            document.getElementById('notifBtn').style.background = '#c0392b';
            document.getElementById('notifHint').textContent = 'Je ontvangt meldingen bij nieuwe bestellingen.';
        }
    }

    async function togglePushNotifications() {
        const reg = await navigator.serviceWorker.ready;
        const existing = await reg.pushManager.getSubscription();

        if (existing) {
            await fetch('/api/push-subscriptions.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ endpoint: existing.endpoint })
            });
            await existing.unsubscribe();
            document.getElementById('notifBtn').textContent = 'Notificaties inschakelen';
            document.getElementById('notifBtn').style.background = 'linear-gradient(135deg, #8b5a2b, #5c3d1e)';
            document.getElementById('notifHint').textContent = 'Notificaties uitgeschakeld.';
            return;
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            document.getElementById('notifHint').textContent = 'Notificaties geweigerd.';
            return;
        }

        try {
            const resp = await fetch('/api/push-subscriptions.php?action=vapid-key');
            const { publicKey } = await resp.json();

            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey)
            });

            const subJson = sub.toJSON();
            await fetch('/api/push-subscriptions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    endpoint: subJson.endpoint,
                    keys: {
                        p256dh: subJson.keys.p256dh,
                        auth: subJson.keys.auth
                    }
                })
            });

            document.getElementById('notifBtn').textContent = 'Notificaties uitschakelen';
            document.getElementById('notifBtn').style.background = '#c0392b';
            document.getElementById('notifHint').textContent = 'Je ontvangt nu meldingen bij nieuwe bestellingen.';
        } catch (e) {
            document.getElementById('notifHint').textContent = 'Fout bij inschakelen: ' + e.message;
        }
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = atob(base64);
        return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
    }

    initPushUI();
    </script>
</body>
</html>
