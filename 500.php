<?php http_response_code(500); ?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server fout — Bakkerij Civetta</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Georgia', serif;
            background: #faf6f0;
            color: #2d2622;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2rem;
        }
        .error-code {
            font-size: 6rem;
            color: #d4a574;
            line-height: 1;
            margin-bottom: 1rem;
        }
        h1 {
            font-size: 1.75rem;
            color: #5c3d1e;
            margin-bottom: 0.75rem;
        }
        p {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            color: #4a433d;
            margin-bottom: 2rem;
            line-height: 1.6;
            max-width: 440px;
        }
        a {
            display: inline-block;
            padding: 0.75rem 2rem;
            background: #8b5a2b;
            color: #fff9f3;
            text-decoration: none;
            border-radius: 4px;
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            font-weight: 600;
        }
        a:hover { background: #5c3d1e; }
    </style>
</head>
<body>
    <div>
        <div class="error-code">500</div>
        <h1>Er is iets misgegaan</h1>
        <p>Er is een serverfout opgetreden. Probeer het later opnieuw. Als het probleem aanhoudt, neem dan contact met ons op.</p>
        <a href="/">Naar de homepage</a>
    </div>
</body>
</html>
