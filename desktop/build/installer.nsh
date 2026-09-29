; Удаление «Почты»: убрать регистрацию mailto (её пишет само приложение, main.js registerMailto)
; и запись автозапуска. При обновлении (старая версия удаляется перед установкой новой) ничего не трогаем —
; новая версия перепишет то же самое при запуске.
!macro customUnInstall
  ${ifNot} ${isUpdated}
    DeleteRegKey HKCU "Software\Classes\Pochta.mailto"
    DeleteRegKey HKCU "Software\Pochta"
    DeleteRegValue HKCU "Software\RegisteredApplications" "Pochta"
    DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "ru.mailadmin.pochta"
    ; Скачанные обновления (electron-updater) — до сотни мегабайт, после удаления не нужны.
    RMDir /r "$LOCALAPPDATA\pochta-desktop-updater"
  ${endIf}
!macroend
