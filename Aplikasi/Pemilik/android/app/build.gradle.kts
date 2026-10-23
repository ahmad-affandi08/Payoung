import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Flavor dev/test tetap bisa dibangun tanpa kredensial. Saat google-services.json rilis dipasang, Firebase aktif.
if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
}

// Audit F-04: build rilis wajib ditandatangani kunci unggah produksi (Play App Signing), bukan kunci debug.
// Sumber kunci: android/key.properties (tidak di-commit, lihat .gitignore) atau variabel lingkungan CI
// PAYOUNG_KEYSTORE_FILE, PAYOUNG_KEYSTORE_PASSWORD, PAYOUNG_KEY_ALIAS, PAYOUNG_KEY_PASSWORD.
val propertiKunci = Properties().apply {
    val berkas = rootProject.file("key.properties")
    if (berkas.exists()) {
        FileInputStream(berkas).use { load(it) }
    }
}

fun nilaiKunci(properti: String, lingkungan: String): String? =
    (propertiKunci.getProperty(properti) ?: System.getenv(lingkungan))?.takeIf { it.isNotBlank() }

val berkasKeystore = nilaiKunci("storeFile", "PAYOUNG_KEYSTORE_FILE")

android {
    namespace = "id.payoung.owner"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "id.payoung.owner"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (berkasKeystore != null) {
            create("rilis") {
                storeFile = file(berkasKeystore)
                storePassword = nilaiKunci("storePassword", "PAYOUNG_KEYSTORE_PASSWORD")
                keyAlias = nilaiKunci("keyAlias", "PAYOUNG_KEY_ALIAS")
                keyPassword = nilaiKunci("keyPassword", "PAYOUNG_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            // Tanpa kunci rilis, build rilis dihentikan (lihat tugas pemeriksa di bawah), bukan diam-diam memakai debug.
            signingConfig = if (berkasKeystore != null) signingConfigs.getByName("rilis") else null
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}

// Audit F-04: hentikan build rilis yang tidak punya kunci penandatangan produksi (sebelum kompilasi dimulai).
gradle.taskGraph.whenReady {
    val minta = allTasks.any { it.project == project && (it.name == "assembleRelease" || it.name == "bundleRelease") }
    if (berkasKeystore == null && minta) {
        throw GradleException(
            "Build rilis butuh kunci penandatangan produksi: isi android/key.properties " +
                "(storeFile, storePassword, keyAlias, keyPassword) atau variabel PAYOUNG_KEYSTORE_*.",
        )
    }
}
