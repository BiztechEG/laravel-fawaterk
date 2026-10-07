<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    @yield('head')
    <title>@yield('title')</title>
    <style>
        :root {
            --fw-bg: #f4f5f7;
            --fw-card: #ffffff;
            --fw-text: #1f2328;
            --fw-muted: #59636e;
            --fw-border: #d8dee4;
            --fw-accent: #0b5cad;
            --fw-ok: #1a7f37;
            --fw-wait: #8a5a00;
            --fw-bad: #c62828;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --fw-bg: #0d1117;
                --fw-card: #161b22;
                --fw-text: #e6edf3;
                --fw-muted: #9198a1;
                --fw-border: #30363d;
                --fw-accent: #4493f8;
                --fw-ok: #2ea043;
                --fw-wait: #b07d12;
                --fw-bad: #e5534b;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-block-size: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: var(--fw-bg);
            color: var(--fw-text);
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif;
        }
        .fw-card {
            inline-size: 100%;
            max-inline-size: 440px;
            background: var(--fw-card);
            border: 1px solid var(--fw-border);
            border-radius: 12px;
            padding: 28px 24px;
            text-align: center;
        }
        .fw-icon {
            inline-size: 56px;
            block-size: 56px;
            margin: 0 auto 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
            color: #ffffff;
        }
        .fw-ok { background: var(--fw-ok); }
        .fw-wait { background: var(--fw-wait); }
        .fw-bad { background: var(--fw-bad); }
        h1 { font-size: 1.35rem; margin: 0 0 8px; }
        p { margin: 0 0 16px; color: var(--fw-muted); }
        dl { margin: 16px 0 0; padding: 0; text-align: start; border-block-start: 1px solid var(--fw-border); }
        dl div {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            padding-block: 10px;
            border-block-end: 1px solid var(--fw-border);
        }
        dt { color: var(--fw-muted); }
        dd { margin: 0; font-weight: 600; direction: ltr; unicode-bidi: isolate; }
        .fw-code { font-size: 1.5rem; letter-spacing: 0.08em; }
        .fw-actions { display: flex; flex-direction: column; gap: 10px; margin-block-start: 20px; }
        .fw-button {
            display: block;
            padding: 12px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }
        .fw-primary { background: var(--fw-accent); color: #ffffff; }
        .fw-secondary { color: var(--fw-accent); border: 1px solid var(--fw-border); }
        .fw-button:focus-visible { outline: 3px solid var(--fw-accent); outline-offset: 2px; }
    </style>
</head>
<body>
    <main class="fw-card">
        @yield('content')
    </main>
</body>
</html>
