package su.innotec.mail.platform

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.unit.dp
import su.innotec.mail.ui.Html

/** На ПК встроенного браузера нет: простое поле, HTML собирается из текста (как было до редактора). */
@Composable
actual fun RichEditor(
    state: RichEditorState,
    dark: Boolean,
    placeholder: String,
    modifier: Modifier,
    loadResource: suspend (path: String) -> Pair<String, ByteArray>?,
) {
    LaunchedEffect(Unit) { state.rich = false }
    var text by remember { mutableStateOf(Html.toText(state.html)) }
    val color = if (dark) Color(0xFFE6EAF0) else Color(0xFF1B2430)
    Box(modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp)) {
        if (text.isEmpty()) Text(placeholder, color = color.copy(alpha = .45f), style = MaterialTheme.typography.bodyLarge)
        BasicTextField(
            text, { v -> text = v; state.setFromPlain(Html.fromText(v)) }, Modifier.fillMaxWidth(),
            textStyle = MaterialTheme.typography.bodyLarge.copy(color = color),
            cursorBrush = SolidColor(color),
            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences),
        )
    }
}
