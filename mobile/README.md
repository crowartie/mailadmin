# Мобильное приложение «Почта»

Kotlin Multiplatform + Compose Multiplatform: один код интерфейса и логики для Android, ПК (Windows,
Linux, macOS) и iPhone. Приложение ходит не в IMAP, а в API сервера (`/api/v1`, [docs/mobile-api.md](../docs/mobile-api.md)) —
те же контроллеры, что у веб-почты, вход по токену устройства.

```mermaid
flowchart LR
    UI["Compose: экраны (commonMain/ui)"] --> ST["Состояние: MailStore, CalStore, ContactsStore…"]
    ST --> API["Api.kt: /api/v1, Bearer-токен"]
    API --> SRV["Сервер веб-почты"]
    UI --> PL["platform: WebView письма, файлы, уведомления (androidMain / desktopMain / iosMain)"]
```

## Что где

| Путь | Что |
|---|---|
| `composeApp/src/commonMain/.../api` | модели и клиент API — всё, что умеет сервер |
| `.../data` | вход (`Session`: сервер, токен, настройки приложения), проверка новых писем (`MailCheck`) |
| `.../ui/mail` | папки, список, письмо, «Написать», карантин, «ждут отправки», доступ к папкам, правило для отправителя (`SenderRule.kt`), письма на устройстве (`MailCache.kt`) |
| `.../platform/Editor.kt` | текст письма с оформлением: страница contenteditable (логика `Editor.vue`) во встроенном браузере + мостик; на ПК — простое поле |
| `.../ui/Viewer.kt` | просмотр вложений и файлов облака: картинки с увеличением, PDF постранично, Office через PDF сервера |
| `.../ui/calendar/TimeGrid.kt` | сетка «День» и «Неделя»: раскладка пересечений, новое событие касанием, перенос пальцем |
| `.../ui/calendar`, `ui/contacts`, `ui/cloud`, `ui/more` | остальные разделы и «Ещё» (настройки, правила, безопасность, обращения, обновления) |
| `.../ui/IconPaths.kt` | значки веб-почты, генерируются `python mobile/tools/gen_icons.py` из `Icon.vue` |
| `composeApp/src/androidMain` | Android: WebView письма и редактора (пересоздаются, если процесс отрисовки упал), шифрованное хранилище токена (Android Keystore), загрузки, печать, PdfRenderer, уведомления (WorkManager и служба «Мгновенно» `MailWatchService`) |
| `composeApp/src/desktopMain` | ПК: окно, горячие клавиши (`Shortcuts.kt`), файлы в «Загрузки», письмо — текстом со ссылками |
| `composeApp/src/iosMain`, `iosApp/` | iPhone: заготовка, собирается только на Mac |

Экранов три раскладки: телефон (нижняя панель), планшет (панель слева + список и письмо рядом),
широкий экран (папки + список + письмо).

## Сборка

Нужны JDK 17+ и Android SDK (платформа 36). На ПК администратора всё лежит в `C:\Users\User\devtools`
(`jdk21`, `android-sdk`), путь к SDK — в `mobile/local.properties` (в репозиторий не попадает).

```bash
cd mobile
./gradlew :composeApp:assembleDebug        # APK для проверки: composeApp/build/outputs/apk/debug/
./gradlew :composeApp:run                  # версия для ПК
./gradlew :composeApp:packageMsi           # установщик для Windows
```

Выпуск для людей — только подписанный (`assembleRelease` + `mobile/keystore.properties`, ключ хранит
администратор). Порядок: поднять `appVersion`/`appVersionCode` в `composeApp/build.gradle.kts`, дописать
`CHANGELOG.md`, собрать APK (вручную или меткой `mobile-vX.Y.Z` через `.github/workflows/mobile-release.yml`)
и выложить его на сервер почты — `pochta.apk` и `latest.json` в `storage/app/private/mobile` (см. `MobileRelease`).
Установленное приложение увидит выпуск само («Ещё → О приложении»): сравнивает версию, а при равной — номер сборки.

## Тесты

- `CoreTest` (общий код): адреса папок, HTML ↔ текст, даты, разбор ответов и ошибок API на подставном сервере.
- `EditorTest`, `CalendarTest`, `MailCacheTest`, `ShortcutsTest`: обмен с редактором, раскладка сетки календаря
  и подписи повторов, кэш писем (и что чужой ящик его не видит), горячие клавиши.
- Скрипт редактора — в настоящем браузере: `EditorPageDump` кладёт страницу в `composeApp/build/editor-page.html`
  (вставка из Word, списки, очистка опасного HTML проверяются в ней).
- `LiveCalendarTest`, `LiveContactPhotoTest`: серия с днями недели и одна встреча серии, занятость, выгрузка .ics;
  фото контакта.
- `LiveApiTest`, `LiveWriteTest`: против живого сервера — чтение всех разделов и запись с откатом (контакт,
  событие, задача, метка, облако, письмо самому себе с вложением). Нужен токен тестового ящика в
  `MAILADMIN_TEST_TOKEN` (у администратора его выдаёт `ux/_mtest.py`, в репозиторий не попадает).
- `DesktopShotTest`: окно ПК со снимками в `composeApp/build/shots/`.
- Экраны Android — на эмуляторе (`ux/_mdev.py`: установка, вход токеном без ввода пароля, снимки).

## iPhone

Код для iPhone (`iosMain`, `iosApp/`) написан, но собран может быть только на Mac с Xcode, и нужен
аккаунт разработчика Apple. На первой сборке:

1. В Xcode создать проект `iosApp` (SwiftUI) из файлов `iosApp/iosApp`, добавить шаг сборки
   `cd "$SRCROOT/.." && ./gradlew :composeApp:embedAndSignAppleFrameworkForXcode` и подключить фреймворк `ComposeApp`.
2. Токен перенести из `NSUserDefaults` в Keychain (`KeyValueStore` в `Platform.ios.kt`).
3. Доделать выбор файлов (`rememberFilePicker` — UIDocumentPicker) и push через APNs.

## Уведомления

Без Firebase. Обычно Android проверяет «Входящие» примерно раз в 15 минут (WorkManager) и сразу при открытии
приложения. Переключатель «Мгновенно» («Ещё → Оформление и уведомления») включает службу с тихим постоянным
значком: проверка раз в минуту, пока есть сеть, после ошибок — реже (до 10 минут). Уведомление — «отправитель —
тема», всё берётся только с нашего сервера. На Huawei и Honor службе нужно разрешить работу в фоне
(«Батарея → Запуск приложений → Почта → вручную»), иначе после перезагрузки она запустится только вместе с приложением.
Настоящий push (сигнал через Firebase или RuStore) — docs/mobile-api.md, раздел 5, если понадобится экономить батарею.
