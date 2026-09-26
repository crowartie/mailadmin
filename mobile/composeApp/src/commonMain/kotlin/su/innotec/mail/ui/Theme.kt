package su.innotec.mail.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * Гамма «А» (макеты 27.09): тёплая бумага и оранжевый акцент. Оранжевый — только у действий (кнопки,
 * FAB, активная вкладка и фильтр, счётчики непрочитанных, напоминания); ссылки в тексте — синий [link],
 * непрочитанное в списке — жирным начертанием обычного цвета, а не акцентом.
 */
@Immutable
data class Palette(
    val bg: Color, val surface: Color, val surface2: Color, val border: Color, val border2: Color,
    val text: Color, val muted: Color, val faint: Color,
    val accent: Color, val accentSoft: Color, val accentInk: Color, val accentOn: Color,
    val mark: Color, val markInk: Color,
    val ok: Color, val okSoft: Color, val okInk: Color,
    val warn: Color, val warnSoft: Color, val warnInk: Color,
    val no: Color, val noSoft: Color, val noInk: Color,
    /** Ссылки в тексте (письмо, редактор, подсказки) — синие, чтобы не путались с действиями. */
    val link: Color,
    val chipOff: Color,
    /** Аватары-инициалы: глубокие тона с белыми буквами, выбор — по адресу (см. [Avatar]). */
    val avatars: List<Color>,
    val dark: Boolean,
)

val LightPalette = Palette(
    bg = Color(0xFFF5F3EF), surface = Color(0xFFFFFFFF), surface2 = Color(0xFFFAF9F6), border = Color(0xFFE6E4E0), border2 = Color(0xFFCFCBC4),
    text = Color(0xFF2B3036), muted = Color(0xFF646B76), faint = Color(0xFF646B76),
    // Акцент темнее, чем на сайте (#FF6A00): белые буквы на нём читаются (4.6:1 по WCAG), на #E85D04 было 3.5.
    accent = Color(0xFFC94E00), accentSoft = Color(0xFFFFF1E8), accentInk = Color(0xFF9A3D00), accentOn = Color.White,
    mark = Color(0xFFFFD84D), markInk = Color(0xFF5A4300),
    ok = Color(0xFF1F7A4D), okSoft = Color(0xFFE6F4EC), okInk = Color(0xFF1F7A4D),
    warn = Color(0xFF9A6700), warnSoft = Color(0xFFFFF6E0), warnInk = Color(0xFF9A6700),
    no = Color(0xFFC62828), noSoft = Color(0xFFFCE8E8), noInk = Color(0xFFC62828),
    link = Color(0xFF1D5FD1),
    chipOff = Color(0xFFEFEDE8),
    avatars = listOf(Color(0xFFB84A00), Color(0xFF1F7A4D), Color(0xFF1D5FD1), Color(0xFF6B3FA0), Color(0xFF0F766E), Color(0xFF9A6700)),
    dark = false,
)

val DarkPalette = Palette(
    bg = Color(0xFF1E2226), surface = Color(0xFF2A2F35), surface2 = Color(0xFF31373E), border = Color(0xFF3A4048), border2 = Color(0xFF4A515A),
    text = Color(0xFFEDEBE7), muted = Color(0xFFA3A9B2), faint = Color(0xFFA3A9B2),
    accent = Color(0xFFFF8A3D), accentSoft = Color(0xFF3D2A1E), accentInk = Color(0xFFFFB380), accentOn = Color(0xFF1E2226),
    mark = Color(0xFFE0B62A), markInk = Color(0xFF2A2000),
    ok = Color(0xFF3DC47A), okSoft = Color(0xFF17362A), okInk = Color(0xFF3DC47A),
    warn = Color(0xFFE0A93A), warnSoft = Color(0xFF3A2F14), warnInk = Color(0xFFE0A93A),
    no = Color(0xFFF07070), noSoft = Color(0xFF3C1F1F), noInk = Color(0xFFF07070),
    link = Color(0xFF7FA8FF),
    chipOff = Color(0xFF363C44),
    // Тона темнее светлых аналогов — белые буквы должны читаться и в тёмной теме.
    avatars = listOf(Color(0xFFB84A00), Color(0xFF1F7A4D), Color(0xFF2F63C9), Color(0xFF6B3FA0), Color(0xFF0F766E), Color(0xFF8A5C00)),
    dark = true,
)

// Классическая схема — синяя, как приложение и веб-почта выглядели до 27.09.2026. Включается настройкой ящика
// «Цветовая схема» (общая с веб-почтой); у неё свои светлая и тёмная темы.
val ClassicLightPalette = Palette(
    bg = Color(0xFFF3F5F8), surface = Color(0xFFFFFFFF), surface2 = Color(0xFFF8FAFC), border = Color(0xFFE3E8EF), border2 = Color(0xFFCBD3DE),
    text = Color(0xFF1B2430), muted = Color(0xFF5A6472), faint = Color(0xFF5F6D81),
    accent = Color(0xFF2F6FEB), accentSoft = Color(0xFFE8F0FE), accentInk = Color(0xFF1B4FC4), accentOn = Color.White,
    mark = Color(0xFFFFD84D), markInk = Color(0xFF5A4300),
    ok = Color(0xFF16A05C), okSoft = Color(0xFFE3F6EB), okInk = Color(0xFF117C47),
    warn = Color(0xFFD9791F), warnSoft = Color(0xFFFDF0E1), warnInk = Color(0xFFA35B17),
    no = Color(0xFFC0392B), noSoft = Color(0xFFFBE7E4), noInk = Color(0xFFC0392B),
    link = Color(0xFF1B4FC4),
    chipOff = Color(0xFFEDF0F4),
    avatars = listOf(Color(0xFF1B4FC4), Color(0xFF117C47), Color(0xFF6B3FA0), Color(0xFFA35B17), Color(0xFF0F766E), Color(0xFF7A2E8F)),
    dark = false,
)

val ClassicDarkPalette = Palette(
    bg = Color(0xFF0F1419), surface = Color(0xFF171D25), surface2 = Color(0xFF1D242E), border = Color(0xFF27303C), border2 = Color(0xFF34404F),
    text = Color(0xFFE6EBF2), muted = Color(0xFF98A3B3), faint = Color(0xFF8993A1),
    accent = Color(0xFF6D9BFF), accentSoft = Color(0xFF1B2A45), accentInk = Color(0xFFA9C3FF), accentOn = Color(0xFF0F1419),
    mark = Color(0xFFE0B62A), markInk = Color(0xFF2A2000),
    ok = Color(0xFF3DC47A), okSoft = Color(0xFF14301F), okInk = Color(0xFF3DC47A),
    warn = Color(0xFFF0A250), warnSoft = Color(0xFF3A2A14), warnInk = Color(0xFFF0A250),
    no = Color(0xFFF0705F), noSoft = Color(0xFF3C1C18), noInk = Color(0xFFF0705F),
    link = Color(0xFFA9C3FF),
    chipOff = Color(0xFF232B36),
    avatars = listOf(Color(0xFF2F63C9), Color(0xFF1F7A4D), Color(0xFF6B3FA0), Color(0xFFB84A00), Color(0xFF0F766E), Color(0xFF8A3AA6)),
    dark = true,
)

/** Палитра по схеме и теме: brand — фирменная (оранжевая), classic — синяя. */
fun paletteFor(scheme: String, dark: Boolean): Palette = when {
    scheme == "classic" && dark -> ClassicDarkPalette
    scheme == "classic" -> ClassicLightPalette
    dark -> DarkPalette
    else -> LightPalette
}

val LocalPalette = staticCompositionLocalOf { LightPalette }

val P: Palette @Composable get() = LocalPalette.current

/** Цвет аватара по адресу — одинаковый в списке, письме и карточке (индекс стабилен между запусками). */
fun Palette.avatarColor(key: String): Color = avatars[(key.lowercase().hashCode() and 0x7fffffff) % avatars.size]

private fun scheme(p: Palette): ColorScheme = if (p.dark) darkColorScheme(
    primary = p.accent, onPrimary = p.accentOn, primaryContainer = p.accentSoft, onPrimaryContainer = p.accentInk,
    secondary = p.accent, onSecondary = p.accentOn, secondaryContainer = p.accentSoft, onSecondaryContainer = p.accentInk,
    tertiary = p.link, onTertiary = p.accentOn,
    background = p.bg, onBackground = p.text, surface = p.surface, onSurface = p.text, surfaceVariant = p.surface2, onSurfaceVariant = p.muted,
    surfaceContainer = p.surface, surfaceContainerHigh = p.surface2, surfaceContainerHighest = p.surface2, surfaceContainerLow = p.surface, surfaceContainerLowest = p.bg,
    outline = p.border2, outlineVariant = p.border, error = p.no, onError = p.bg, errorContainer = p.noSoft, onErrorContainer = p.noInk,
) else lightColorScheme(
    primary = p.accent, onPrimary = p.accentOn, primaryContainer = p.accentSoft, onPrimaryContainer = p.accentInk,
    secondary = p.accent, onSecondary = p.accentOn, secondaryContainer = p.accentSoft, onSecondaryContainer = p.accentInk,
    tertiary = p.link, onTertiary = Color.White,
    background = p.bg, onBackground = p.text, surface = p.surface, onSurface = p.text, surfaceVariant = p.surface2, onSurfaceVariant = p.muted,
    surfaceContainer = p.surface, surfaceContainerHigh = p.surface2, surfaceContainerHighest = p.surface2, surfaceContainerLow = p.surface, surfaceContainerLowest = p.bg,
    outline = p.border2, outlineVariant = p.border, error = p.no, onError = Color.White, errorContainer = p.noSoft, onErrorContainer = p.noInk,
)

private val typo = Typography(
    titleLarge = TextStyle(fontSize = 20.sp, fontWeight = FontWeight.SemiBold, lineHeight = 26.sp),
    titleMedium = TextStyle(fontSize = 16.sp, fontWeight = FontWeight.SemiBold, lineHeight = 22.sp),
    titleSmall = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.SemiBold, lineHeight = 20.sp),
    bodyLarge = TextStyle(fontSize = 15.sp, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, lineHeight = 20.sp),
    bodySmall = TextStyle(fontSize = 12.5.sp, lineHeight = 17.sp),
    labelLarge = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Medium),
    labelMedium = TextStyle(fontSize = 12.sp, fontWeight = FontWeight.Medium),
    labelSmall = TextStyle(fontSize = 11.sp, fontWeight = FontWeight.Medium),
)

private val shapes = Shapes(
    extraSmall = RoundedCornerShape(6.dp),
    small = RoundedCornerShape(8.dp),
    medium = RoundedCornerShape(12.dp),
    large = RoundedCornerShape(16.dp),
    extraLarge = RoundedCornerShape(24.dp),
)

/** theme: system | light | dark; scheme: brand | classic — как настройки «Тема» и «Цветовая схема» веб-почты. */
@Composable
fun MailTheme(theme: String, scheme: String = "brand", content: @Composable () -> Unit) {
    val dark = when (theme) { "dark" -> true; "light" -> false; else -> isSystemInDarkTheme() }
    val p = paletteFor(scheme, dark)
    su.innotec.mail.platform.SystemBarsTheme(dark)
    CompositionLocalProvider(LocalPalette provides p) {
        MaterialTheme(colorScheme = scheme(p), typography = typo, shapes = shapes, content = content)
    }
}

/** Цвет метки/календаря из «#RRGGBB». */
fun hexColor(s: String?, fallback: Color = Color(0xFF1D5FD1)): Color {
    val h = s?.trim()?.removePrefix("#") ?: return fallback
    return when (h.length) {
        6 -> h.toLongOrNull(16)?.let { Color(0xFF000000 or it) } ?: fallback
        8 -> h.toLongOrNull(16)?.let { Color(it) } ?: fallback
        3 -> h.map { "$it$it" }.joinToString("").toLongOrNull(16)?.let { Color(0xFF000000 or it) } ?: fallback
        else -> fallback
    }
}
