<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результат платежа — ServiceBox</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 40px 32px;
            max-width: 440px;
            width: 100%;
            text-align: center;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
        }
        .icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .icon-success { background: #d1fae5; color: #059669; }
        .icon-pending { background: #fef3c7; color: #d97706; }
        .icon svg { width: 32px; height: 32px; }
        h1 {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        p {
            color: #6b7280;
            font-size: 15px;
            line-height: 1.5;
            margin-bottom: 24px;
        }
        .order-id {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #374151;
        }
        .order-id span { font-weight: 600; font-family: monospace; }
        .btn {
            display: inline-block;
            padding: 12px 28px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
            background: #2563eb;
            color: #fff;
        }
        .btn:hover { background: #1d4ed8; }
        .note {
            margin-top: 16px;
            font-size: 13px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
<div class="card" id="app">
    <!-- filled by JS based on query params -->
</div>

<script>
(function () {
    var params = new URLSearchParams(window.location.search);
    var orderId = params.get('order_id') || '';
    var paid = params.get('paid') === '1';

    var card = document.getElementById('app');

    if (paid || !orderId) {
        // Успешная оплата (или прямой переход без параметров)
        card.innerHTML =
            '<div class="icon icon-success">' +
            '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' +
            '</div>' +
            '<h1>Оплата прошла успешно!</h1>' +
            '<p>Ваш заказ оплачен. Ожидайте подтверждения.</p>' +
            (orderId ? '<div class="order-id">Заказ <span>#' + orderId.slice(0, 8) + '</span></div>' : '') +
            '<button class="btn" onclick="window.close()">Закрыть</button>' +
            '<p class="note">Вы можете закрыть эту страницу и вернуться в магазин.</p>';
    } else {
        // Вернулись после оплаты — статус ещё неизвестен (webhook придёт позже)
        card.innerHTML =
            '<div class="icon icon-pending">' +
            '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' +
            '</div>' +
            '<h1>Обрабатываем платёж</h1>' +
            '<p>Платёж принят. Статус заказа обновится в течение нескольких минут.</p>' +
            '<div class="order-id">Заказ <span>#' + orderId.slice(0, 8) + '</span></div>' +
            '<button class="btn" onclick="window.close()">Закрыть</button>' +
            '<p class="note">Если оплата прошла, вы получите уведомление.</p>';
    }
})();
</script>
</body>
</html>
