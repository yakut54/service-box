<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $shopName }} — Запись онлайн</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 0;
      min-height: 100vh;
      background: #f1f5f9;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    header {
      width: 100%;
      background: #fff;
      border-bottom: 1px solid #e2e8f0;
      padding: 14px 20px;
      text-align: center;
    }
    header h1 {
      margin: 0;
      font-size: 18px;
      font-weight: 700;
      color: #1e293b;
    }
    main {
      width: 100%;
      max-width: 480px;
      padding: 24px 16px;
    }
  </style>
</head>
<body>
  <header>
    <h1>{{ $shopName }}</h1>
  </header>
  <main>
    <p style="text-align:center;color:#64748b;font-size:14px;margin-top:40px;">Загрузка...</p>
  </main>
  <script>
    localStorage.setItem('sb-open:{{ $apiKey }}', 'true')
  </script>
  <script src="/widget.js" data-shop-id="{{ $apiKey }}"></script>
</body>
</html>
