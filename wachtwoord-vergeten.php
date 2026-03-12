<?php
require_once 'admin/config.php';
require_once 'lib/shared.php';

$bedrijf = [];
try {
    $bedrijf = getBedrijfsGegevens($pdo);
} catch (Exception $e) {}

$bedrijfsnaam = $bedrijf['bedrijfsnaam'] ?? 'Bakkerij Civetta';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wachtwoord vergeten | <?= htmlspecialchars($bedrijfsnaam) ?></title>
    <link rel="icon" type="image/png" sizes="192x192" href="img/icon-192.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+Pro:wght@400;600&display=swap" rel="stylesheet">
    <style>
        .login-page {
            min-height: calc(100vh - 200px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--spacing-lg) var(--spacing-md);
            background: linear-gradient(180deg, var(--color-parchment) 0%, var(--color-cream) 100%);
        }
        .login-container {
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
        }
        .login-page > .container {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
        }
        .login-card {
            background: var(--color-warm-white);
            border-radius: 16px;
            padding: var(--spacing-lg);
            box-shadow: 0 8px 30px rgba(139, 90, 43, 0.12);
            text-align: center;
        }
        .login-logo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin: 0 auto var(--spacing-md);
            border: 3px solid var(--color-wheat-light);
        }
        .login-card h1 {
            font-size: 1.5rem;
            color: var(--color-crust-dark);
            margin-bottom: var(--spacing-xs);
        }
        .login-card .subtitle {
            color: var(--color-stone);
            margin-bottom: var(--spacing-md);
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .form-group {
            text-align: left;
            margin-bottom: var(--spacing-sm);
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--color-stone);
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--color-parchment);
            border-radius: 10px;
            font-size: 1rem;
            font-family: var(--font-body);
            transition: border-color var(--transition), box-shadow var(--transition);
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--color-wheat);
            box-shadow: 0 0 0 4px rgba(212, 165, 116, 0.2);
        }
        .btn {
            width: 100%;
            margin-top: var(--spacing-sm);
        }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: var(--spacing-sm);
            text-align: left;
        }
        .alert-error { background: #fee; color: #c00; border: 1px solid #fcc; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .login-links {
            margin-top: var(--spacing-md);
            padding-top: var(--spacing-md);
            border-top: 1px solid var(--color-parchment);
            font-size: 0.9rem;
            color: var(--color-stone);
        }
        .login-links a {
            color: var(--color-crust);
            font-weight: 600;
            text-decoration: none;
        }
        .login-links a:hover { color: var(--color-terracotta); }
        [v-cloak] { display: none; }
    </style>
</head>
<body>
    <header>
        <nav>
            <div class="logo">
                <a href="index.html">Civetta</a>
            </div>
            <ul class="nav-links">
                <li><a href="index.html">Home</a></li>
                <li><a href="ons-verhaal.html">Ons Verhaal</a></li>
                <li><a href="producten.html">Producten</a></li>
                <li><a href="galerij.html">Galerij</a></li>
                <li><a href="leveranciers.html">Leveranciers</a></li>
                <li><a href="financiering.html">Crowdfunding</a></li>
                <li><a href="contact.html">Contact</a></li>
                <li class="nav-login">
                    <a href="login.html" class="login-trigger">Inloggen</a>
                </li>
            </ul>
            <button class="mobile-menu-btn" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>
        </nav>
    </header>

    <main>
        <section class="login-page">
            <div class="container">
                <div id="forgot-app" v-cloak class="login-container">
                    <div class="login-card">
                        <img src="img/logo.jpeg" alt="Civetta" class="login-logo">
                        <h1>Wachtwoord vergeten</h1>

                        <div v-if="sent">
                            <div class="alert alert-success">
                                Als uw e-mailadres bij ons bekend is, ontvangt u een e-mail met een link om uw wachtwoord opnieuw in te stellen.
                            </div>
                            <div class="login-links">
                                <a href="login.html">← Terug naar inloggen</a>
                            </div>
                        </div>

                        <template v-else>
                            <p class="subtitle">Vul uw e-mailadres in en we sturen u een link om een nieuw wachtwoord in te stellen.</p>

                            <div v-if="error" class="alert alert-error">{{ error }}</div>

                            <form @submit.prevent="submit">
                                <div class="form-group">
                                    <label for="email">E-mailadres</label>
                                    <input type="email" id="email" v-model="email" required autocomplete="email" placeholder="uw@bedrijf.nl">
                                </div>
                                <button type="submit" class="btn" :disabled="loading">
                                    {{ loading ? 'Bezig...' : 'Verstuur resetlink' }}
                                </button>
                            </form>

                            <div class="login-links">
                                <a href="login.html">← Terug naar inloggen</a>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-info">
                    <p><strong>Bakkerij Civetta</strong></p>
                    <p>Leersum, Utrecht</p>
                </div>
                <img src="img/logo.jpeg" alt="Civetta" class="footer-logo">
                <div class="footer-contact">
                    <p><a href="mailto:info@bakkerij-civetta.nl">info@bakkerij-civetta.nl</a></p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="js/main.js"></script>
    <script>
    const { createApp } = Vue;
    createApp({
        data() {
            return { email: '', error: '', loading: false, sent: false };
        },
        methods: {
            async submit() {
                this.error = '';
                this.loading = true;
                try {
                    const response = await fetch('api/forgot-password.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ email: this.email })
                    });
                    const data = await response.json();
                    if (data.success) {
                        this.sent = true;
                    } else {
                        this.error = data.error || 'Er ging iets mis. Probeer het later opnieuw.';
                    }
                } catch (err) {
                    this.error = 'Er ging iets mis. Probeer het later opnieuw.';
                } finally {
                    this.loading = false;
                }
            }
        }
    }).mount('#forgot-app');
    </script>
</body>
</html>
