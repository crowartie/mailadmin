// Раздел «Безопасность» в настройках почты: двухфакторная защита, пароли для почтовых
// программ и список активных сеансов.
//
// Отдельно потому, что это единственная часть настроек, где ошибка стоит дорого: выключенная
// не вовремя защита, отозванный чужой пароль, завершённый чужой сеанс. Держать такие вещи
// вперемешку с выбором темы и подписью — значит каждый раз перечитывать их заодно.
import { ref } from 'vue';
import { api } from './api';

/**
 * @param {object} ctx
 * @param {import('vue').Ref} ctx.busy                идёт запрос
 * @param {(t: string, err?: boolean) => void} ctx.say
 * @param {(title: string, text?: string, label?: string, danger?: boolean) => Promise<boolean>} ctx.ask
 * @param {boolean} ctx.force2fa  защиту требуют включить: после включения уводим в почту
 */
export function useSecuritySettings(ctx) {
    const sec = ref(null);
    const secError = ref('');
    const twofa = ref(null);
    const twofaCode = ref('');
    const twofaPassword = ref('');
    const newAppPassword = ref(null);
    const createdPassword = ref(null);

    /** Запрос с общей обёрткой: занятость и показ ошибки одинаковы во всём разделе. */
    async function run(fn) {
        ctx.busy.value = true;
        try {
            return await fn();
        } catch (e) {
            ctx.say(e.message, true);

            return null;
        } finally {
            ctx.busy.value = false;
        }
    }

    // 205: до ответа сервера раздел рисовал пустые карточки и выглядел сломанным —
    // теперь видно, что данные грузятся, и видно, если они не пришли.
    async function loadSecurity() {
        secError.value = '';
        try {
            sec.value = await api.security();
        } catch (e) {
            secError.value = e.message;
            ctx.say(e.message, true);
        }
    }

    /**
     * 13: пароль висел на экране открытым текстом до перезагрузки страницы, а скопировать
     * его можно было только выделением мышью.
     */
    async function copyPassword() {
        const text = createdPassword.value?.plain || '';
        try {
            await navigator.clipboard.writeText(text);
            ctx.say('Пароль скопирован — вставьте его в почтовую программу');
        } catch {
            ctx.say('Браузер не дал скопировать — выделите пароль и нажмите Ctrl+C', true);
        }
    }

    async function startTwofa() {
        await run(async () => { twofa.value = await api.twofaSetup(); twofaCode.value = ''; });
    }

    async function enableTwofa() {
        await run(async () => {
            await api.twofaEnable(twofaCode.value);
            twofa.value = null;
            await loadSecurity();
            ctx.say('Двухфакторная защита включена');
            if (ctx.force2fa) window.location.href = '/mail';
        });
    }

    async function disableTwofa() {
        await run(async () => {
            await api.twofaDisable(twofaPassword.value);
            twofaPassword.value = '';
            await loadSecurity();
            ctx.say('Защита выключена');
        });
    }

    async function createAppPassword() {
        await run(async () => {
            createdPassword.value = await api.createAppPassword(newAppPassword.value.name, newAppPassword.value.password);
            newAppPassword.value = null;
            await loadSecurity();
        });
    }

    async function revokeAppPassword(p) {
        const ok = await ctx.ask(
            `Отозвать пароль «${p.name}»?`,
            'Устройство с этим паролем перестанет получать почту. Основной пароль менять не придётся.',
            'Отозвать', true,
        );
        if (!ok) return;
        try {
            await api.deleteAppPassword(p.id);
            await loadSecurity();
        } catch (e) {
            ctx.say(e.message, true);
        }
    }

    async function kickSession(s) {
        try {
            const r = await api.kickSession(s.id);
            sec.value.sessions = r.sessions;
        } catch (e) {
            ctx.say(e.message, true);
        }
    }

    async function kickOthers() {
        try {
            const r = await api.kickOthers();
            sec.value.sessions = r.sessions;
            ctx.say('Остальные сеансы завершены');
        } catch (e) {
            ctx.say(e.message, true);
        }
    }

    return {
        sec, secError, twofa, twofaCode, twofaPassword, newAppPassword, createdPassword,
        loadSecurity, copyPassword, startTwofa, enableTwofa, disableTwofa,
        createAppPassword, revokeAppPassword, kickSession, kickOthers,
    };
}
