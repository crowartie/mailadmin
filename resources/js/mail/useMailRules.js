// Правила разбора почты и автоответ.
//
// Самая «правиловая» часть настроек: здесь живут ограничения сервера, продублированные
// на клиенте, и проверки, выведенные из живых жалоб — пустое условие совпадает со всеми
// письмами, правило без условий уводит всю входящую почту, автоответ с датами «с 20.09
// по 01.09» не срабатывает никогда и молчит об этом. В общем файле настроек эти правила
// терялись среди выбора темы, подписи и списка папок.
import { ref } from 'vue';
import { api } from './api';

/**
 * @param {object} ctx
 * @param {import('vue').Ref} ctx.rules      список правил
 * @param {import('vue').Ref} ctx.autoreply  настройки автоответа
 * @param {import('vue').Ref} ctx.editing    правило, открытое на правку
 * @param {import('vue').Ref} ctx.folders    папки — для описаний действий
 * @param {import('vue').Ref} ctx.labels     метки — для описаний действий
 * @param {import('vue').Ref} ctx.busy
 * @param {(t: string, err?: boolean) => void} ctx.say
 * @param {(title: string, text?: string, label?: string, danger?: boolean) => Promise<boolean>} ctx.ask
 */
export function useMailRules(ctx) {
    const { rules, autoreply, editing, folders, labels, busy, say, ask } = ctx;

    const applying = ref(false);
    async function applyRules() {
        if (applying.value) return;
        if (!await ask('Разложить письма во «Входящих»?', 'Правила пройдут по письмам, которые уже лежат во «Входящих», и разложат их по папкам. Отменить это одним действием нельзя.', 'Разложить')) return;
        applying.value = true;
        try {
            const r = await api.applyRules();
            const done = r.results.filter((x) => !x.skipped);
            const total = done.reduce((s, x) => s + x.count, 0);
            const skipped = r.results.filter((x) => x.skipped && x.skipped !== 'выключено');
            // Перечисляем все пропущенные правила с причинами: раньше показывалась только первая,
            // и человек не знал, что ещё не отработало.
            const why = skipped.map((x) => `${x.name || 'правило'} — ${x.skipped}`).join('; ');
            say(`Обработано писем: ${total}` + (done.length ? ' (' + done.map((x) => `${x.name || 'правило'}: ${x.count}`).join(', ') + ')' : '') + (skipped.length ? `. Пропущено правил: ${skipped.length} — ${why}` : ''), false);
        } catch (e) { say(e.message, true); } finally { applying.value = false; }
    }
    const FIELDS = { from: 'Отправитель', to: 'Получатель', recipient: 'Кому или копия', subject: 'Тема', body: 'Текст письма', header: 'Заголовок', size: 'Размер, КБ' };
    const OPS = { contains: 'содержит', not_contains: 'не содержит', is: 'равно', starts: 'начинается с', ends: 'заканчивается на', over: 'больше', under: 'меньше' };
    // Для размера подходят только «больше» и «меньше», для текста — остальные. Раньше при смене
    // типа условия оператор оставался от прежнего, и селектор показывал «больше» у темы письма.
    const TEXT_OPS = ['contains', 'not_contains', 'is', 'starts', 'ends'];
    const SIZE_OPS = ['over', 'under'];
    function opsFor(field) { return field === 'size' ? SIZE_OPS : TEXT_OPS; }
    function onFieldChange(c) {
        const allowed = opsFor(c.field);
        if (!allowed.includes(c.op)) c.op = allowed[0];
        if (c.field === 'size' && !/^\d*$/.test(String(c.value ?? ''))) c.value = '';
    }
    const ACTIONS = { move: 'Переместить в папку', copy: 'Копию в папку', move_by_sender: 'В папку по адресу отправителя', move_by_domain: 'В папку по домену отправителя', label: 'Поставить метку', flag: 'Флажок', seen: 'Пометить прочитанным', forward: 'Переслать на адрес', forward_copy: 'Переслать копию на адрес', discard: 'Уничтожить письмо (без «Корзины»)', reply: 'Ответить текстом', stop: 'Остановить обработку' };

    function newRule() {
        editing.value = { id: Date.now(), name: '', enabled: true, match: 'all', stop: false, conditions: [{ field: 'from', op: 'contains', value: '' }], actions: [{ type: 'move', value: '' }] };
        if (rules.value.length >= MAX_RULES) { editing.value = null; say(`Правил не больше ${MAX_RULES} — удалите ненужные`, true); }
    }
    function describeCond(c) {
        if (c.field === 'size') return `Размер ${OPS[c.op] || ''} ${c.value} КБ`;
        return `${FIELDS[c.field] || c.field}${c.field === 'header' ? ' ' + (c.header || '') : ''} ${OPS[c.op] || ''} «${c.value}»`;
    }
    function describeAct(a) {
        const folderName = (p) => folders.value.find((f) => f.path === p)?.name || p;
        const labelName = (id) => labels.value.find((l) => String(l.id) === String(id))?.name || id;
        switch (a.type) {
            case 'move': return `в папку «${folderName(a.value)}»`;
            case 'copy': return `копия в «${folderName(a.value)}»`;
            case 'move_by_sender': return a.value ? `в папку отправителя внутри «${folderName(a.value)}»` : 'в папку отправителя';
            case 'move_by_domain': return a.value ? `в папку домена отправителя внутри «${folderName(a.value)}»` : 'в папку домена отправителя';
            case 'label': return `метка «${labelName(a.value)}»`;
            case 'forward': case 'forward_copy': return `${ACTIONS[a.type].toLowerCase()} ${a.value}`;
            case 'reply': return 'автоответ';
            default: return ACTIONS[a.type]?.toLowerCase() || a.type;
        }
    }
    function ruleName(r) {
        return r.name || (r.conditions?.length ? describeCond(r.conditions[0]) : 'Все письма');
    }
    // Ограничения сервера (RulesController): держим их и на клиенте, чтобы человек не узнавал
    // о них только при сохранении, да ещё сообщением от проверяющего механизма.
    const MAX_RULES = 50;
    const MAX_CONDITIONS = 10;
    const MAX_ACTIONS = 6;
    const NEEDS_VALUE = ['move', 'copy', 'label', 'forward', 'forward_copy', 'reply'];

    async function saveRule() {
        const r = editing.value;
        if (!r.actions.length) { say('Добавьте хотя бы одно действие', true); return; }
        // Правило без условий совпадает с каждым письмом. Вместе с «Переместить» или
        // «Уничтожить письмо» это разом уводит всю входящую почту — спрашиваем прямо.
        if (!r.conditions.length) {
            const harsh = r.actions.some((a) => ['move', 'move_by_sender', 'move_by_domain', 'discard', 'forward'].includes(a.type));
            const q = harsh
                ? 'Оно сработает на каждое входящее письмо, включая нужные.'
                : 'Оно будет срабатывать на каждое письмо.';
            if (!await ask('Правило без условий', q, 'Сохранить', harsh)) return;
        }
        // Пустое условие («Отправитель содержит ») тоже совпадает со всеми письмами,
        // но выглядит как настроенное — такое не сохраняем.
        const empty = r.conditions.find((c) => String(c.value ?? '').trim() === '');
        if (empty) { say(`Заполните значение условия «${FIELDS[empty.field] || empty.field}» — пустое совпадает со всеми письмами`, true); return; }
        const blank = r.actions.find((a) => NEEDS_VALUE.includes(a.type) && String(a.value ?? '').trim() === '');
        if (blank) { say(`Выберите, что подставить в действие «${ACTIONS[blank.type]}» — иначе правило ничего не сделает`, true); return; }
        if (r.conditions.length > MAX_CONDITIONS) { say(`Условий в одном правиле не больше ${MAX_CONDITIONS}`, true); return; }
        if (r.actions.length > MAX_ACTIONS) { say(`Действий в одном правиле не больше ${MAX_ACTIONS}`, true); return; }
        const i = rules.value.findIndex((x) => x.id === r.id);
        if (i < 0 && rules.value.length >= MAX_RULES) { say(`Правил не больше ${MAX_RULES} — удалите ненужные`, true); return; }
        if (i >= 0) rules.value[i] = r; else rules.value.push(r);
        editing.value = null;
        pushRules();
    }
    async function removeRule(id) {
        const r = rules.value.find((x) => x.id === id);
        const name = r?.name ? `«${r.name}»` : 'правило';
        if (!await ask(`Удалить ${name}?`, 'Восстановить правило будет нельзя — его придётся создать заново.', 'Удалить', true)) return;
        rules.value = rules.value.filter((x) => x.id !== id);
        pushRules();
    }
    function moveRule(i, d) {
        const j = i + d;
        if (j < 0 || j >= rules.value.length) return;
        const arr = [...rules.value]; [arr[i], arr[j]] = [arr[j], arr[i]]; rules.value = arr;
        pushRules();
    }
    /**
     * Сохранить правила и автоответ. Текст сообщения зависит от того, что человек менял:
     * раньше и при сохранении автоответа, и при перестановке правил появлялось одно и то же
     * «Правила применены на сервере», которое читается как «правила уже прогнаны по почте».
     */
    async function pushRules(what = 'rules') {
        busy.value = true;
        try {
            await api.saveRules(rules.value, autoreply.value);
            say(what === 'autoreply' ? 'Автоответ сохранён' : 'Правила сохранены — сервер будет применять их к новым письмам');
        } catch (e) { say(e.message, true); } finally { busy.value = false; }
    }

    /**
     * Сохранить автоответ. Проверяем то, что сервер не проверяет: порядок дат и пустой текст
     * у включённого автоответа — иначе люди получали бы пустые письма, а сам автоответ
     * с датами «с 20.09 по 01.09» молча не срабатывал бы никогда.
     */
    async function saveAutoreply() {
        const a = autoreply.value;
        if (a.from && a.to && a.from > a.to) {
            say('Дата «По» раньше даты «С» — автоответ так никогда не сработает', true);
            return;
        }
        if (a.enabled && !String(a.body || '').trim()) {
            say('Напишите текст автоответа — иначе отправителям уйдёт пустое письмо', true);
            return;
        }
        if (a.enabled && a.to && a.to < new Date().toISOString().slice(0, 10)) {
            if (!await ask('Дата «По» уже прошла', 'Автоответ включён, но отвечать не будет, пока дата не в будущем. Всё равно сохранить?', 'Сохранить')) return;
        }
        pushRules('autoreply');
    }
    
    return {
        applying, FIELDS, OPS, ACTIONS, MAX_RULES, MAX_CONDITIONS, MAX_ACTIONS,
        applyRules, opsFor, onFieldChange, newRule, describeCond, describeAct, ruleName,
        saveRule, removeRule, moveRule, pushRules, saveAutoreply,
    };
}
