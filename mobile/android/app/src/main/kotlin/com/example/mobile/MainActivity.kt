package com.example.mobile

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File
import java.io.FileOutputStream

/**
 * Приём фото через системное «Поделиться» (Android ACTION_SEND, mime-тип image).
 * Ручками через platform channel, а не через сторонний пакет — единственный
 * готовый вариант (receive_sharing_intent) требует Swift Package Manager на
 * iOS-стороне и из-за этого ломает `flutter pub get`/`analyze` во всём
 * проекте на Windows-машине разработки (проверено 2026-08-24, пакет
 * выкинут). iOS-сторона этой фичи не сделана — актуально только для
 * Android, эта реализация того и касается.
 */
class MainActivity : FlutterActivity() {
    private val channelName = "app.barbariska/share"
    private val notifChannelName = "app.barbariska/notifications"
    private var methodChannel: MethodChannel? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        methodChannel = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, channelName)
        // Холодный старт: приложения не было в памяти, intent пришёл вместе с запуском.
        handleShareIntent(intent)

        createNotificationChannels()
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, notifChannelName)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "openNotificationSettings" -> {
                        openNotificationSettings()
                        result.success(null)
                    }
                    else -> result.notImplemented()
                }
            }
    }

    /**
     * Каналы уведомлений. createNotificationChannel идемпотентен — зовём при
     * каждом старте. Сервер шлёт push с channel_id = orders/delivery/chat/promo;
     * без существующего канала Android 8+ уведомление просто не покажет.
     * «Напоминания о записи» появятся вместе с booking-push (см. PLAN.md).
     */
    /** Экран настроек уведомлений приложения; на API < 26 — просто карточка приложения. */
    private fun openNotificationSettings() {
        val intent = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                .putExtra(Settings.EXTRA_APP_PACKAGE, packageName)
        } else {
            Intent(
                Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                Uri.fromParts("package", packageName, null)
            )
        }
        try {
            startActivity(intent)
        } catch (e: Exception) {
            // Настройки недоступны — молча, строка в профиле просто не сработает.
        }
    }

    private fun createNotificationChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val nm = getSystemService(NotificationManager::class.java) ?: return
        val high = NotificationManager.IMPORTANCE_HIGH
        val default = NotificationManager.IMPORTANCE_DEFAULT
        listOf(
            Triple("orders", "Заказы", high),
            Triple("delivery", "Доставка", high),
            Triple("chat", "Чат", high),
            Triple("promo", "Акции магазина", default),
        ).forEach { (id, name, importance) ->
            nm.createNotificationChannel(NotificationChannel(id, name, importance))
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        // Приложение уже было открыто (в фоне/на переднем плане).
        handleShareIntent(intent)
    }

    private fun handleShareIntent(intent: Intent?) {
        if (intent == null || intent.action != Intent.ACTION_SEND) return
        if (intent.type?.startsWith("image/") != true) return

        @Suppress("DEPRECATION")
        val uri = intent.getParcelableExtra<Uri>(Intent.EXTRA_STREAM) ?: return

        val path = copyToCacheFile(uri) ?: return
        methodChannel?.invokeMethod("sharedImage", path)
    }

    /** content:// нельзя читать напрямую из dart:io — копируем в обычный файл в кэше. */
    private fun copyToCacheFile(uri: Uri): String? {
        return try {
            val input = contentResolver.openInputStream(uri) ?: return null
            val file = File(cacheDir, "shared_${System.currentTimeMillis()}.jpg")
            FileOutputStream(file).use { output -> input.use { it.copyTo(output) } }
            file.absolutePath
        } catch (e: Exception) {
            null
        }
    }
}
