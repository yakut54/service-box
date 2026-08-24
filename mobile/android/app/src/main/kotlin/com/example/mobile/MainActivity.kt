package com.example.mobile

import android.content.Intent
import android.net.Uri
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
    private var methodChannel: MethodChannel? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        methodChannel = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, channelName)
        // Холодный старт: приложения не было в памяти, intent пришёл вместе с запуском.
        handleShareIntent(intent)
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
