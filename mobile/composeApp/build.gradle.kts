import org.jetbrains.compose.desktop.application.dsl.TargetFormat
import org.jetbrains.kotlin.gradle.dsl.JvmTarget
import java.util.Properties

plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidApplication)
    alias(libs.plugins.composeMultiplatform)
    alias(libs.plugins.composeCompiler)
    alias(libs.plugins.kotlinSerialization)
}

// Версия приложения — одна на все платформы. Сервер сравнивает её с minApp (/.well-known/mailadmin).
val appVersion = "1.2.7"
val appVersionCode = 12

// AppInfo.kt с версией и номером сборки для общего кода (User-Agent, «О программе», проверка обновлений:
// при равной версии сервер может выложить сборку с большим code).
val genDir = layout.buildDirectory.dir("generated/appinfo/commonMain/kotlin")
val genAppInfo by tasks.registering {
    val out = genDir
    inputs.property("v", appVersion)
    inputs.property("code", appVersionCode)
    outputs.dir(out)
    doLast {
        val f = out.get().file("su/innotec/mail/AppInfo.kt").asFile
        f.parentFile.mkdirs()
        f.writeText(
            """
            package su.innotec.mail

            object AppInfo {
                const val VERSION = "$appVersion"
                const val CODE = $appVersionCode
            }
            """.trimIndent() + "\n"
        )
    }
}

kotlin {
    androidTarget {
        compilerOptions { jvmTarget.set(JvmTarget.JVM_17) }
    }
    jvm("desktop") {
        compilerOptions { jvmTarget.set(JvmTarget.JVM_17) }
    }
    listOf(iosArm64(), iosSimulatorArm64()).forEach {
        it.binaries.framework {
            baseName = "ComposeApp"
            isStatic = true
        }
    }

    compilerOptions {
        freeCompilerArgs.addAll("-Xexpect-actual-classes", "-opt-in=kotlin.time.ExperimentalTime")
    }

    sourceSets {
        val desktopMain by getting
        val desktopTest by getting

        commonMain {
            kotlin.srcDir(genAppInfo)
            dependencies {
                implementation(compose.runtime)
                implementation(compose.foundation)
                implementation(compose.material3)
                implementation(compose.ui)
                implementation(compose.components.resources)
                implementation(libs.ktor.client.core)
                implementation(libs.ktor.client.content.negotiation)
                implementation(libs.ktor.serialization.json)
                implementation(libs.kotlinx.coroutines.core)
                implementation(libs.kotlinx.serialization.json)
                implementation(libs.kotlinx.datetime)
                // Жизненный цикл в Compose: уход в фон — выполнить отложенные действия (App.kt).
                implementation("org.jetbrains.androidx.lifecycle:lifecycle-runtime-compose:2.9.6")
            }
        }
        commonTest.dependencies {
            implementation(kotlin("test"))
            implementation(libs.ktor.client.mock)
            implementation(libs.kotlinx.coroutines.test)
        }
        androidMain.dependencies {
            implementation(libs.androidx.activity.compose)
            implementation(libs.androidx.work)
            implementation(libs.ktor.client.okhttp)
            implementation(libs.okhttp)
            implementation(libs.kotlinx.coroutines.android)
        }
        desktopMain.dependencies {
            implementation(compose.desktop.currentOs)
            implementation(libs.ktor.client.okhttp)
            implementation(libs.okhttp)
            implementation(libs.kotlinx.coroutines.swing)
        }
        desktopTest.dependencies {
            @OptIn(org.jetbrains.compose.ExperimentalComposeLibrary::class)
            implementation(compose.uiTest)
            implementation(compose.desktop.currentOs)
        }
        iosMain.dependencies {
            implementation(libs.ktor.client.darwin)
        }
    }
}

android {
    namespace = "su.innotec.mail"
    compileSdk = 36

    defaultConfig {
        applicationId = "su.innotec.mail"
        minSdk = 26
        targetSdk = 36
        versionCode = appVersionCode
        versionName = appVersion
    }
    buildTypes {
        getByName("release") {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            // Подпись релиза: ключ хранит администратор (mobile/keystore.properties не в репозитории).
            val ks = rootProject.file("keystore.properties")
            if (ks.exists()) {
                val p = Properties().apply { ks.inputStream().use { load(it) } }
                signingConfig = signingConfigs.create("release") {
                    storeFile = rootProject.file(p.getProperty("storeFile"))
                    storePassword = p.getProperty("storePassword")
                    keyAlias = p.getProperty("keyAlias")
                    keyPassword = p.getProperty("keyPassword")
                }
            }
        }
    }
    // qa — выпускная сборка (R8, сжатие), но отладочная подпись и вход токеном для автотестов на эмуляторе.
    buildTypes.create("qa") {
        initWith(buildTypes.getByName("release"))
        isDebuggable = true
        signingConfig = signingConfigs.getByName("debug")
        matchingFallbacks += "release"
        applicationIdSuffix = ".qa"
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    packaging {
        resources.excludes += setOf("/META-INF/{AL2.0,LGPL2.1}", "/META-INF/INDEX.LIST", "/META-INF/io.netty.versions.properties")
    }
    // BuildConfig.DEBUG нужен MainActivity: вход токеном для автотестов компилируется только в отладочные сборки
    // (в release R8 выбрасывает эту ветку целиком, а флаг debuggable в манифесте подменить проще, чем код).
    buildFeatures { buildConfig = true }
}

compose.desktop {
    application {
        mainClass = "su.innotec.mail.MainKt"
        nativeDistributions {
            targetFormats(TargetFormat.Msi, TargetFormat.Deb, TargetFormat.Dmg)
            packageName = "Pochta"
            packageVersion = appVersion
            description = "Почта"
            vendor = "innotec"
        }
    }
}

// Тесты против живого сервера получают вход из окружения (ux/_mtest.py), в репозитории его нет.
tasks.withType<Test>().configureEach {
    listOf("MAILADMIN_TEST_SERVER", "MAILADMIN_TEST_LOGIN", "MAILADMIN_TEST_PASSWORD", "MAILADMIN_TEST_IP").forEach { k ->
        System.getenv(k)?.let { environment(k, it) }
    }
    testLogging { events("passed", "skipped", "failed"); showStandardStreams = true; exceptionFormat = org.gradle.api.tasks.testing.logging.TestExceptionFormat.FULL }
}
