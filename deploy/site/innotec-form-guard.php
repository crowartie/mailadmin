<?php
/**
 * Plugin Name: Innotec — защита формы и отправка от site@
 * Description: Письма с сайта уходят от site@innotec.su (Return-Path тот же), почтовый сервер компании принимает их с этого хостинга без пароля. Заявки роботов через Contact Form 7 отсекаются: поле-ловушка, время заполнения, текст без кириллицы со ссылками.
 * Version: 1.0 (2026-09-29)
 */
if (! defined('ABSPATH')) exit;

const INNOTEC_SITE_FROM = 'site@innotec.su';
const INNOTEC_SITE_NAME = 'Сайт innotec.su';

// ── Отправитель: всегда site@ (и в конверте, и в From). Имя посетителя остаётся в From-имени, его адрес — в Reply-To (его ставит CF7).
add_action('phpmailer_init', function ($phpmailer) {
    $name = $phpmailer->FromName ?: INNOTEC_SITE_NAME;
    $phpmailer->setFrom(INNOTEC_SITE_FROM, $name, false);
    $phpmailer->Sender = INNOTEC_SITE_FROM;
}, 99);
add_filter('wp_mail_from', fn () => INNOTEC_SITE_FROM, 99);

// ── Поле-ловушка и метка времени в каждой форме CF7 (посетитель их не видит, роботы заполняют/не ждут).
add_filter('wpcf7_form_elements', function ($html) {
    $ts = base64_encode((string) time());
    $trap = '<span class="ig-trap" aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden">'
        . '<label>Оставьте пустым<input type="text" name="ig_company_site" value="" tabindex="-1" autocomplete="off"></label></span>'
        . '<input type="hidden" name="ig_t" value="' . esc_attr($ts) . '">';
    return $html . $trap;
});

// ── Проверка заявки. Возвращаем «спам» — CF7 не отправит письмо и покажет обычный ответ, робот ничего не поймёт.
add_filter('wpcf7_spam', function ($spam, $submission = null) {
    if ($spam) return true;
    $why = innotec_form_spam_reason($_POST);
    if ($why === null) return false;
    if ($submission && method_exists($submission, 'add_spam_log')) {
        $submission->add_spam_log(['agent' => 'innotec-form-guard', 'reason' => $why]);
    }
    error_log('[innotec-form-guard] отклонено: ' . $why . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    return true;
}, 10, 2);

/** Причина отказа или null, если заявка похожа на настоящую. */
function innotec_form_spam_reason(array $post): ?string {
    if (! empty($post['ig_company_site'])) return 'заполнено поле-ловушка';
    $t = isset($post['ig_t']) ? (int) base64_decode((string) $post['ig_t']) : 0;
    if ($t <= 0) return 'нет метки времени';
    $age = time() - $t;
    if ($age < 4) return 'форма отправлена через ' . $age . ' с после открытия';
    if ($age > 6 * 3600) return 'форма открыта больше 6 часов назад';
    $text = trim(implode("\n", array_map(fn ($k) => (string) ($post[$k] ?? ''), ['your-name', 'your-subject', 'message'])));
    $hasCyrillic = (bool) preg_match('/\p{Cyrillic}/u', $text);
    $hasUrl = (bool) preg_match('~https?://|www\.|\.(ru|com|net|org|xyz|top|site|online|info)(/|\s|$)~iu', $text);
    if (! $hasCyrillic && $hasUrl) return 'текст без кириллицы со ссылкой';
    if (! $hasCyrillic && mb_strlen($text) > 40) return 'текст без кириллицы';
    if (preg_match('/[\x{1F300}-\x{1FAFF}]/u', (string) ($post['your-name'] ?? ''))) return 'эмодзи в имени';
    return null;
}
