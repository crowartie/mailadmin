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

/** Палитра веб-почты (resources/scss/_variables.scss), светлая и тёмная. */
@Immutable
data class Palette(
    val bg: Color, val surface: Color, val surface2: Color, val border: Color, val border2: Color,
    val text: Color, val muted: Color, val faint: Color,
    val accent: Color, val accentSoft: Color, val accentInk: Color, val accentOn: Color,
    val mark: Color, val markInk: Color,
    val ok: Color, val okSoft: Color, val okInk: Color,
    val warn: Color, val warnSoft: Color, val warnInk: Color,
    val no: Color, val noSoft: Color, val noInk: Color,
    val chipOff: Color,
    val dark: Boolean,
)

val LightPalette = Palette(
    bg = Color(0xFFF3F5F8), surface = Color(0xFFFFFFFF), surface2 = Color(0xFFF8FAFC), border = Color(0xFFE3E8EF), border2 = Color(0xFFCBD3DE),
    text = Color(0xFF1B2430), muted = Color(0xFF5A6472), faint = Color(0xFF5F6D81),
    accent = Color(0xFF2F6FEB), accentSoft = Color(0xFFE8F0FE), accentInk = Color(0xFF1B4FC4), accentOn = Color.White,
    mark = Color(0xFFFFD84D), markInk = Color(0xFF5A4300),
    ok = Color(0xFF16A05C), okSoft = Color(0xFFE3F6EB), okInk = Color(0xFF117C47),
    warn = Color(0xFFD9791F), warnSoft = Color(0xFFFDF0E1), warnInk = Color(0xFFA35B17),
    no = Color(0xFFC0392B), noSoft = Color(0xFFFBE7E4), noInk = Color(0xFFC0392B),
    chipOff = Color(0xFFEDF0F4), dark = false,
)

val DarkPalette = Palette(
    bg = Color(0xFF0F1419), surface = Color(0xFF171D25), surface2 = Color(0xFF1D242E), border = Color(0xFF27303C), border2 = Color(0xFF34404F),
    text = Color(0xFFE6EBF2), muted = Color(0xFF98A3B3), faint = Color(0xFF8993A1),
    accent = Color(0xFF6D9BFF), accentSoft = Color(0xFF1B2A45), accentInk = Color(0xFFA9C3FF), accentOn = Color(0xFF0F1419),
    mark = Color(0xFFE0B62A), markInk = Color(0xFF2A2000),
    ok = Color(0xFF3DC47A), okSoft = Color(0xFF14301F), okInk = Color(0xFF3DC47A),
    warn = Color(0xFFF0A250), warnSoft = Color(0xFF3A2A14), warnInk = Color(0xFFF0A250),
    no = Color(0xFFF0705F), noSoft = Color(0xFF3C1C18), noInk = Color(0xFFF0705F),
    chipOff = Color(0xFF232B36), dark = true,
)

val LocalPalette = staticCompositionLocalOf { LightPalette }

val P: Palette @Composable get() = LocalPalette.current

private fun scheme(p: Palette): ColorScheme = if (p.dark) darkColorScheme(
    primary = p.accent, onPrimary = p.accentOn, primaryContainer = p.accentSoft, onPrimaryContainer = p.accentInk,
    secondary = p.accent, onSecondary = p.accentOn, secondaryContainer = p.accentSoft, onSecondaryContainer = p.accentInk,
    background = p.bg, onBackground = p.text, surface = p.surface, onSurface = p.text, surfaceVariant = p.surface2, onSurfaceVariant = p.muted,
    surfaceContainer = p.surface, surfaceContainerHigh = p.surface2, surfaceContainerHighest = p.surface2, surfaceContainerLow = p.surface, surfaceContainerLowest = p.bg,
    outline = p.border2, outlineVariant = p.border, error = p.no, onError = Color.White, errorContainer = p.noSoft, onErrorContainer = p.noInk,
) else lightColorScheme(
    primary = p.accent, onPrimary = p.accentOn, primaryContainer = p.accentSoft, onPrimaryContainer = p.accentInk,
    secondary = p.accent, onSecondary = p.accentOn, secondaryContainer = p.accentSoft, onSecondaryContainer = p.accentInk,
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

/** theme: system | light | dark — как настройка «Тема» веб-почты. */
@Composable
fun MailTheme(theme: String, content: @Composable () -> Unit) {
    val dark = when (theme) { "dark" -> true; "light" -> false; else -> isSystemInDarkTheme() }
    val p = if (dark) DarkPalette else LightPalette
    su.innotec.mail.platform.SystemBarsTheme(dark)
    CompositionLocalProvider(LocalPalette provides p) {
        MaterialTheme(colorScheme = scheme(p), typography = typo, shapes = shapes, content = content)
    }
}

/** Цвет метки/календаря из «#RRGGBB». */
fun hexColor(s: String?, fallback: Color = Color(0xFF2F6FEB)): Color {
    val h = s?.trim()?.removePrefix("#") ?: return fallback
    return when (h.length) {
        6 -> h.toLongOrNull(16)?.let { Color(0xFF000000 or it) } ?: fallback
        8 -> h.toLongOrNull(16)?.let { Color(it) } ?: fallback
        3 -> h.map { "$it$it" }.joinToString("").toLongOrNull(16)?.let { Color(0xFF000000 or it) } ?: fallback
        else -> fallback
    }
}
