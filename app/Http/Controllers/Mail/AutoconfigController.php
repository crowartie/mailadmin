<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Vmail\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Автонастройка почтовых программ по адресу ящика:
 *  - Microsoft Autodiscover (Outlook, почта Windows) — POST /autodiscover/autodiscover.xml;
 *  - Mozilla autoconfig (Thunderbird, Android-клиенты) — GET /mail/config-v1.1.xml;
 *  - профиль для iPhone/iPad/Mac — GET /mail/apple.mobileconfig?email=… (почта + контакты + календарь).
 * Пароль нигде не передаётся: клиент спросит его сам.
 */
class AutoconfigController extends Controller
{
    private function hosts(): array
    {
        $domain = config('areas.default_domain');
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'mail.' . $domain;

        return [
            'domain' => $domain,
            'imap' => 'imap.' . $domain,
            'smtp' => 'smtp.' . $domain,
            'web' => $host,
            'name' => 'Почта ' . $domain,
        ];
    }

    private function emailFrom(Request $request): string
    {
        $email = strtolower(trim((string) ($request->query('emailaddress') ?: $request->query('email') ?: '')));
        if ($email === '' && $request->isMethod('POST')) {
            if (preg_match('~<EMailAddress>\s*([^<\s]+)\s*</EMailAddress>~i', (string) $request->getContent(), $m)) {
                $email = strtolower($m[1]);
            }
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** Outlook: ответ в схеме outlook/responseschema/2006a. */
    public function autodiscover(Request $request): Response
    {
        $h = $this->hosts();
        $email = $this->emailFrom($request);
        $login = htmlspecialchars($email ?: '', ENT_XML1);
        $name = htmlspecialchars($email ? (Mailbox::query()->where('username', $email)->value('name') ?: $email) : '', ENT_XML1);
        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">
  <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a">
    <User><DisplayName>{$name}</DisplayName></User>
    <Account>
      <AccountType>email</AccountType>
      <Action>settings</Action>
      <Protocol>
        <Type>IMAP</Type>
        <Server>{$h['imap']}</Server>
        <Port>993</Port>
        <DomainRequired>off</DomainRequired>
        <LoginName>{$login}</LoginName>
        <SPA>off</SPA>
        <SSL>on</SSL>
        <Encryption>SSL</Encryption>
        <AuthRequired>on</AuthRequired>
      </Protocol>
      <Protocol>
        <Type>SMTP</Type>
        <Server>{$h['smtp']}</Server>
        <Port>465</Port>
        <DomainRequired>off</DomainRequired>
        <LoginName>{$login}</LoginName>
        <SPA>off</SPA>
        <SSL>on</SSL>
        <Encryption>SSL</Encryption>
        <AuthRequired>on</AuthRequired>
        <UsePOPAuth>on</UsePOPAuth>
        <SMTPLast>off</SMTPLast>
      </Protocol>
    </Account>
  </Response>
</Autodiscover>
XML;

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /** Thunderbird, K-9, FairEmail и др. */
    public function autoconfig(Request $request): Response
    {
        $h = $this->hosts();
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<clientConfig version="1.1">
  <emailProvider id="{$h['domain']}">
    <domain>{$h['domain']}</domain>
    <displayName>{$h['name']}</displayName>
    <displayShortName>{$h['domain']}</displayShortName>
    <incomingServer type="imap">
      <hostname>{$h['imap']}</hostname>
      <port>993</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </incomingServer>
    <outgoingServer type="smtp">
      <hostname>{$h['smtp']}</hostname>
      <port>465</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </outgoingServer>
    <outgoingServer type="smtp">
      <hostname>{$h['smtp']}</hostname>
      <port>587</port>
      <socketType>STARTTLS</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </outgoingServer>
    <documentation url="https://{$h['web']}/mail/settings/security"><descr lang="ru">Настройки подключения и пароли приложений</descr></documentation>
  </emailProvider>
  <webMail><loginPage url="https://{$h['web']}/mail/login"/></webMail>
</clientConfig>
XML;

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /** iPhone/iPad/Mac: один профиль ставит почту, контакты (CardDAV) и календарь (CalDAV). */
    public function mobileconfig(Request $request): Response
    {
        $h = $this->hosts();
        $email = $this->emailFrom($request);
        $e = htmlspecialchars($email, ENT_XML1);
        $name = htmlspecialchars($email ? (Mailbox::query()->where('username', $email)->value('name') ?: $email) : '', ENT_XML1);
        $uuid = fn (string $s) => strtoupper(substr(md5($h['domain'] . $s . $email), 0, 8) . '-' . substr(md5($s), 0, 4) . '-4' . substr(md5($s . '1'), 0, 3) . '-A' . substr(md5($s . '2'), 0, 3) . '-' . substr(md5($s . '3'), 0, 12));
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>PayloadContent</key>
  <array>
    <dict>
      <key>PayloadType</key><string>com.apple.mail.managed</string>
      <key>PayloadVersion</key><integer>1</integer>
      <key>PayloadIdentifier</key><string>{$h['domain']}.mail</string>
      <key>PayloadUUID</key><string>{$uuid('mail')}</string>
      <key>PayloadDisplayName</key><string>{$h['name']}</string>
      <key>EmailAccountDescription</key><string>{$h['name']}</string>
      <key>EmailAccountName</key><string>{$name}</string>
      <key>EmailAccountType</key><string>EmailTypeIMAP</string>
      <key>EmailAddress</key><string>{$e}</string>
      <key>IncomingMailServerAuthentication</key><string>EmailAuthPassword</string>
      <key>IncomingMailServerHostName</key><string>{$h['imap']}</string>
      <key>IncomingMailServerPortNumber</key><integer>993</integer>
      <key>IncomingMailServerUseSSL</key><true/>
      <key>IncomingMailServerUsername</key><string>{$e}</string>
      <key>OutgoingMailServerAuthentication</key><string>EmailAuthPassword</string>
      <key>OutgoingMailServerHostName</key><string>{$h['smtp']}</string>
      <key>OutgoingMailServerPortNumber</key><integer>465</integer>
      <key>OutgoingMailServerUseSSL</key><true/>
      <key>OutgoingMailServerUsername</key><string>{$e}</string>
      <key>OutgoingPasswordSameAsIncomingPassword</key><true/>
    </dict>
    <dict>
      <key>PayloadType</key><string>com.apple.carddav.account</string>
      <key>PayloadVersion</key><integer>1</integer>
      <key>PayloadIdentifier</key><string>{$h['domain']}.carddav</string>
      <key>PayloadUUID</key><string>{$uuid('carddav')}</string>
      <key>PayloadDisplayName</key><string>Контакты {$h['domain']}</string>
      <key>CardDAVAccountDescription</key><string>Контакты {$h['domain']}</string>
      <key>CardDAVHostName</key><string>{$h['web']}</string>
      <key>CardDAVPort</key><integer>443</integer>
      <key>CardDAVUseSSL</key><true/>
      <key>CardDAVPrincipalURL</key><string>/dav/principals/{$e}/</string>
      <key>CardDAVUsername</key><string>{$e}</string>
    </dict>
    <dict>
      <key>PayloadType</key><string>com.apple.caldav.account</string>
      <key>PayloadVersion</key><integer>1</integer>
      <key>PayloadIdentifier</key><string>{$h['domain']}.caldav</string>
      <key>PayloadUUID</key><string>{$uuid('caldav')}</string>
      <key>PayloadDisplayName</key><string>Календарь {$h['domain']}</string>
      <key>CalDAVAccountDescription</key><string>Календарь {$h['domain']}</string>
      <key>CalDAVHostName</key><string>{$h['web']}</string>
      <key>CalDAVPort</key><integer>443</integer>
      <key>CalDAVUseSSL</key><true/>
      <key>CalDAVPrincipalURL</key><string>/dav/principals/{$e}/</string>
      <key>CalDAVUsername</key><string>{$e}</string>
    </dict>
  </array>
  <key>PayloadDisplayName</key><string>{$h['name']}</string>
  <key>PayloadDescription</key><string>Почта, контакты и календарь {$h['domain']}: после установки введите пароль от почты (или пароль приложения).</string>
  <key>PayloadIdentifier</key><string>{$h['domain']}.profile</string>
  <key>PayloadOrganization</key><string>{$h['domain']}</string>
  <key>PayloadRemovalDisallowed</key><false/>
  <key>PayloadType</key><string>Configuration</string>
  <key>PayloadUUID</key><string>{$uuid('profile')}</string>
  <key>PayloadVersion</key><integer>1</integer>
</dict>
</plist>
XML;

        return response($xml, 200, ['Content-Type' => 'application/x-apple-aspen-config; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $h['domain'] . '.mobileconfig"']);
    }
}
