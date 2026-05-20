<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Приглашение в команду</title>
  <style>
    body { margin: 0; padding: 0; background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    .wrap { max-width: 520px; margin: 40px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .header { background: #2563eb; padding: 32px 40px; }
    .header h1 { margin: 0; color: #fff; font-size: 22px; font-weight: 700; }
    .body { padding: 36px 40px; }
    .body p { margin: 0 0 16px; color: #374151; font-size: 15px; line-height: 1.6; }
    .btn { display: inline-block; background: #2563eb; color: #fff !important; text-decoration: none; padding: 13px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; margin: 8px 0 20px; }
    .note { font-size: 13px !important; color: #9ca3af !important; }
    .link { word-break: break-all; color: #6b7280; font-size: 13px; }
    .footer { border-top: 1px solid #f3f4f6; padding: 20px 40px; color: #9ca3af; font-size: 12px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <h1>{{ config('app.name') }}</h1>
    </div>
    <div class="body">
      <p>Вас приглашают в команду магазина <strong>{{ $shopName }}</strong>.</p>
      @if($requiresRegistration)
        <p>Нажмите кнопку ниже, чтобы создать аккаунт и принять приглашение:</p>
        <a href="{{ $inviteUrl }}" class="btn">Создать аккаунт и войти</a>
      @else
        <p>Нажмите кнопку ниже, чтобы принять приглашение:</p>
        <a href="{{ $inviteUrl }}" class="btn">Принять приглашение</a>
      @endif
      <p class="note">Ссылка действует <strong>48 часов</strong>. Если вы не ожидали этого письма — просто проигнорируйте его.</p>
      <p class="note">Если кнопка не работает, скопируйте ссылку в браузер:</p>
      <p class="link">{{ $inviteUrl }}</p>
    </div>
    <div class="footer">
      &copy; {{ date('Y') }} {{ config('app.name') }}. Это автоматическое письмо — не отвечайте на него.
    </div>
  </div>
</body>
</html>
