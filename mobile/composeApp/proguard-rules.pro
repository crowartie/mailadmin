# kotlinx.serialization: сгенерированные сериализаторы моделей API.
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**
-keepclassmembers class kotlinx.serialization.json.** { *** Companion; }
-keepclasseswithmembers class kotlinx.serialization.json.** { kotlinx.serialization.KSerializer serializer(...); }
-keep,includedescriptorclasses class su.innotec.mail.**$$serializer { *; }
-keepclassmembers class su.innotec.mail.** { *** Companion; }
-keepclasseswithmembers class su.innotec.mail.** { kotlinx.serialization.KSerializer serializer(...); }
# Ktor / OkHttp
-dontwarn org.slf4j.**
-dontwarn java.lang.management.**
-dontwarn io.ktor.**
-dontwarn okhttp3.internal.platform.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**
-keep class io.ktor.** { *; }
# WorkManager создаёт воркер по имени класса.
-keep class su.innotec.mail.MailCheckWorker { *; }
