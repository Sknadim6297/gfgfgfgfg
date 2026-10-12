<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#172b45">
    <title>Slow down a moment | Quick PayMoney</title>
    <style>
        :root { color-scheme: light; font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { min-height: 100vh; display: grid; place-items: center; margin: 0; padding: 24px; background: #f4f7fb; color: #26364b; }
        main { width: min(100%, 460px); padding: 36px; border: 1px solid #e1e8f0; border-radius: 18px; background: #fff; box-shadow: 0 16px 44px rgba(24, 43, 70, .08); text-align: center; }
        .mark { display: grid; width: 52px; height: 52px; margin: 0 auto 18px; place-items: center; border-radius: 15px; background: #fff6e6; color: #9a6814; font-size: 24px; font-weight: 700; }
        h1 { margin: 0; color: #172b45; font-size: 24px; }
        p { margin: 12px 0 24px; color: #64748b; line-height: 1.6; }
        nav { display: flex; justify-content: center; flex-wrap: wrap; gap: 10px; }
        a { display: inline-block; padding: 11px 16px; border: 1px solid #d7e1ec; border-radius: 10px; color: #315f91; font-size: 14px; font-weight: 650; text-decoration: none; }
        a:first-child { border-color: #274e79; background: #274e79; color: #fff; }
        a:focus-visible { outline: 3px solid #8fb9e4; outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <span class="mark" aria-hidden="true">!</span>
        <h1>Please wait a moment</h1>
        @php($waitMessage = $retryAfter >= 60 ? ceil($retryAfter / 60).' minute'.(ceil($retryAfter / 60) === 1.0 ? '' : 's') : $retryAfter.' seconds')
        <p>We received several requests in a short time. Your account and transactions are safe. Please wait {{ $waitMessage }} before trying again.</p>
        <nav aria-label="Recovery links">
            <a href="{{ url('/') }}">Return to Quick PayMoney</a>
            <a href="{{ route('home') }}">Go to home</a>
        </nav>
    </main>
</body>
</html>
