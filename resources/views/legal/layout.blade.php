<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — Smart Publisher</title>
    <style>
        :root { color-scheme: light; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            max-width: 720px;
            margin: 0 auto;
            padding: 2.5rem 1.5rem 4rem;
            line-height: 1.6;
            color: #1a1a1a;
            background: #fff;
        }
        h1 { font-size: 1.6rem; margin-bottom: 0.25rem; }
        h2 { font-size: 1.15rem; margin-top: 2rem; }
        .meta { color: #666; font-size: 0.9rem; margin-bottom: 2rem; }
        code {
            background: #f2f2f2;
            padding: 0.15rem 0.35rem;
            border-radius: 3px;
            font-size: 0.9em;
        }
        pre {
            background: #f5f5f5;
            padding: 1rem;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 0.85em;
        }
        a { color: #1a56db; }
        footer {
            margin-top: 3rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e5e5;
            font-size: 0.85rem;
            color: #666;
        }
        footer a { color: inherit; text-decoration: underline; }
    </style>
</head>
<body>
    <h1>@yield('title')</h1>
    <p class="meta">Smart Publisher — Effective @yield('effectiveDate')</p>

    @yield('content')

    <footer>
        <p>
            Operated by University of Kufa — College of Nursing, Iraq.
            Support: <a href="mailto:jaafarw.alkuby@uokufa.edu.iq">jaafarw.alkuby@uokufa.edu.iq</a>.
        </p>
        <p>
            <a href="/legal/privacy-policy">Privacy Policy</a> ·
            <a href="/legal/terms-of-service">Terms of Service</a> ·
            <a href="/legal/data-deletion">Data Deletion</a>
        </p>
    </footer>
</body>
</html>
