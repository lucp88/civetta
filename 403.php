<?php http_response_code(403); ?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geen toegang — Bakkerij Civetta</title>
    <link rel="icon" type="image/png" sizes="192x192" href="/img/icon-192.png">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+Pro:wght@400;600&display=swap" rel="stylesheet">
    <style>
        .error-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 6rem 2rem;
            text-align: center;
        }
        .error-section .error-code {
            font-family: var(--font-display);
            font-size: 6rem;
            color: var(--color-wheat);
            line-height: 1;
            margin-bottom: 1rem;
        }
        .error-section h1 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        .error-section p {
            color: var(--color-stone);
            max-width: 480px;
            margin: 0 auto 2rem;
        }
        .error-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <header>
        <nav>
            <div class="logo">
                <a href="/">Civetta</a>
            </div>
            <ul class="nav-links">
                <li><a href="/">Home</a></li>
                <li><a href="/ons-verhaal.html">Ons Verhaal</a></li>
                <li><a href="/producten.html">Producten</a></li>
                <li><a href="/galerij.html">Galerij</a></li>
                <li><a href="/leveranciers.html">Leveranciers</a></li>
                <li><a href="/financiering.html">Crowdfunding</a></li>
                <li><a href="/contact.html">Contact</a></li>
                <li class="nav-login">
                    <a href="/login.html" class="login-trigger">Inloggen</a>
                </li>
            </ul>
            <button class="mobile-menu-btn" aria-label="Menu">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </nav>
    </header>

    <main>
        <section class="error-section">
            <div>
                <div class="error-code">403</div>
                <h1>Geen toegang</h1>
                <p>Je hebt geen toegang tot deze pagina. Als je denkt dat dit een fout is, neem dan contact met ons op.</p>
                <div class="error-actions">
                    <a href="/" class="btn">Naar de homepage</a>
                    <a href="/contact.html" class="btn btn-outline">Neem contact op</a>
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
                <img src="/img/logo.jpeg" alt="Civetta" class="footer-logo">
                <div class="footer-contact">
                    <p><a href="mailto:info@bakkerij-civetta.nl">info@bakkerij-civetta.nl</a></p>
                </div>
            </div>
        </div>
    </footer>

    <script src="/js/main.js?v=2"></script>
</body>
</html>
