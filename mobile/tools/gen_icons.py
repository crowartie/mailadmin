# Значки приложения = значки веб-почты: переносит пути из resources/js/Components/Icon.vue в IconPaths.kt.
# Запуск из корня репозитория: python mobile/tools/gen_icons.py
import re, io
src = io.open('resources/js/Components/Icon.vue', encoding='utf-8').read()
body = src.split('const paths = {')[1].split('};')[0]
items = re.findall(r"^\s*([A-Za-z0-9_]+)\s*:\s*'([^']+)'", body, re.M)
out = ['package su.innotec.mail.ui', '',
 '// Сгенерировано из resources/js/Components/Icon.vue (ux: тот же набор контурных значков, что в веб-почте).',
 '// Обновить: python mobile/tools/gen_icons.py', '',
 'internal object IconPaths {', '    val all: Map<String, String> = mapOf(']
for n, p in items:
    out.append(f'        "{n}" to "{p}",')
out += ['    )', '}', '']
io.open('mobile/composeApp/src/commonMain/kotlin/su/innotec/mail/ui/IconPaths.kt', 'w', encoding='utf-8', newline='\n').write('\n'.join(out))
print(len(items), 'значков')
