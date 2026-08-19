plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.example.mobile"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        // Базовый applicationId — реально не используется: каждый флейвор
        // ниже полностью переопределяет свой, чтобы APK разных шоперов
        // ставились на телефон бок о бок, не затирая друг друга.
        applicationId = "ru.yakut54.servicebox"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    // Один флейвор = один шопер (см. PLAN.md, МФ2). У каждого свой
    // applicationId и имя (res/values/strings.xml в src/<flavor>/), а код
    // магазина/api_key/цвет передаются отдельно через
    // --dart-define-from-file=flavors/<flavor>.json — см. mobile/README.md.
    flavorDimensions += "shop"
    productFlavors {
        create("barbariska") {
            dimension = "shop"
            applicationId = "ru.yakut54.servicebox.barbariska"
        }
    }

    buildTypes {
        release {
            // TODO: Add your own signing config for the release build.
            // Signing with the debug keys for now, so `flutter run --release` works.
            signingConfig = signingConfigs.getByName("debug")
        }
    }
}

flutter {
    source = "../.."
}
