# Почта для Windows (desktop/)

Приложение на Electron: веб-почта своего сервера в отдельном окне и то, чего нет у вкладки браузера.
Интерфейс почты не дублируется — окно открывает `https://<сервер>/mail`, поэтому всё новое в веб-почте
появляется в приложении без выпуска новой версии. Приложение отвечает только за оболочку:

| Что | Где |
|---|---|
| Значок в трее, подсказка «N непрочитанных», меню (написать, уведомления, автозапуск, обновления, сервер, выход) | `src/main.js` → `createTray`, `rebuildTrayMenu` |
| Кружок с числом на значке в панели задач | `setUnread` + `assets/badges/*` |
| Уведомления Windows о новых письмах при закрытом окне или на другой странице | `watchInbox` (раз в 30 с `/mail/api/status`, затем `/mail/api/list/INBOX?filter=unread`) |
| Крестик — в трей, выход — из меню | `win.on('close')` |
| mailto: из других программ, второй запуск передаёт ссылку первому | `openMailto`, `second-instance`, `protocols` в `package.json` |
| «Нет связи» с автоповтором | `src/pages/offline.html`, `showOffline` |
| Первый запуск: адрес почты → сервер (`mail.<домен>`) | `src/pages/setup.html`, `setup:check` |
| Куда можно ходить: страницы сервера — в окне, чужие сайты — в браузере, `file:` и прочее — никуда | `lib.classifyUrl`, `will-navigate`, `setWindowOpenHandler` |
| Разрешения только странице своего сервера: уведомления, буфер обмена | `lib.allowPermission` |
| Обновление само себя с того же сервера: `/app/windows/latest.yml` | `setupUpdates` (electron-updater, provider generic) |

Страница почты видит только `window.pochta` (`src/preload.js`): `desktop`, `version`, `notificationsOn()`,
`setUnread(n)`, `show()`. Node и файлы ей недоступны (`contextIsolation`, `sandbox`); главный процесс
принимает сообщения только со страниц своего сервера. Веб-почта пользуется мостом в
`resources/js/mail/push.js` (`setBadge`) и `resources/js/mail/useLiveUpdates.js` (уведомления, опрос в фоне).

## Сборка и проверки

Нужен Node 22 (на машине разработчика — переносной, `C:\Users\User\devtools\node22`).

```
npm ci
npm test          # модульные: src/lib.js, настройки (node --test)
npm run e2e       # сквозные: настоящее окно против заглушки сервера (Playwright + Electron)
npm run dist      # установщик dist/Pochta-Setup-<версия>.exe + latest.yml + .blockmap
```

Сервер по умолчанию в исходниках не зашит (`defaultServer` пустой — на первом запуске спрашивается адрес
почты). Для своей компании его подставляет сборка: `electron-builder … -c.extraMetadata.defaultServer=https://mail.example.ru`.

Иконки делает `python tools/make-icons.py` из `public/icon-512.png`; результат лежит в репозитории.

## Выкладка

Установщик, `.blockmap`, `latest.yml` и `notes.txt` (раздел из `CHANGELOG.md`) кладутся на сервер в
`storage/app/private/desktop`. Сервер отдаёт их по `/app/windows/<файл>` (самообновление) и
`/app/pochta-setup.exe` (кнопка на странице `/app`), см. `DesktopAppController`, `DesktopRelease`.
Перед выпуском: поднять `version` в `package.json`, дописать `CHANGELOG.md`.

Установщик без платной цифровой подписи: при первом запуске Windows SmartScreen показывает
«Система Windows защитила ваш компьютер» → «Подробнее» → «Выполнить в любом случае». Ставится в профиль
пользователя (`%LOCALAPPDATA%\Programs`), права администратора не нужны; настройки — `%APPDATA%\Почта\settings.json`.
