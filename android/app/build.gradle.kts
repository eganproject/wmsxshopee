import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val versionProps = Properties().apply {
    rootProject.file("version.properties").inputStream().use { load(it) }
}

android {
    namespace = "tech.optisync.gudang"
    compileSdk = 35

    defaultConfig {
        applicationId = "tech.optisync.gudang"
        minSdk = 26
        targetSdk = 34
        versionCode = versionProps.getProperty("versionCode").toInt()
        versionName = versionProps.getProperty("versionName")
        // Server bawaan saat pertama kali dibuka; bisa diganti di layar awal.
        buildConfigField("String", "DEFAULT_SERVER", "\"https://optisync.tech\"")
    }

    // Kunci tanda tangan rilis TIDAK disimpan di repo (repo publik). GitHub Actions
    // mengambilnya dari secret; kunci yang sama wajib dipakai terus supaya APK baru
    // bisa dipasang menimpa versi lama. Tanpa kunci, build rilis memakai kunci debug.
    val keystorePath = System.getenv("OPTIMA_KEYSTORE")
    signingConfigs {
        if (keystorePath != null) {
            create("release") {
                storeFile = file(keystorePath)
                storePassword = System.getenv("OPTIMA_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("OPTIMA_KEY_ALIAS")
                keyPassword = System.getenv("OPTIMA_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.findByName("release") ?: signingConfigs.getByName("debug")
        }
        debug {
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
        }
    }

    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    lint {
        abortOnError = false
        checkReleaseBuilds = false
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.activity:activity-ktx:1.9.3")
    implementation("androidx.webkit:webkit:1.12.1")

    val camerax = "1.4.1"
    implementation("androidx.camera:camera-core:$camerax")
    implementation("androidx.camera:camera-camera2:$camerax")
    implementation("androidx.camera:camera-lifecycle:$camerax")
    implementation("androidx.camera:camera-view:$camerax")

    // Model barcode ikut di dalam APK, jadi scan tetap jalan tanpa Google Play / internet.
    implementation("com.google.mlkit:barcode-scanning:17.3.0")
}
