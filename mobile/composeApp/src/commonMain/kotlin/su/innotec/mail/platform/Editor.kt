package su.innotec.mail.platform

import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import kotlinx.serialization.builtins.serializer
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.boolean
import kotlinx.serialization.json.float
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlin.random.Random

/**
 * Текст письма с оформлением. Как в почте Gmail и Outlook на телефоне и как в веб-почте (Editor.vue):
 * поле — contenteditable во встроенном браузере (WebView / WKWebView), панель кнопок — своя.
 * Так приходят вставка из Word с оформлением, картинки в тексте и тот же HTML, что пишет веб-почта.
 * На ПК встроенного браузера нет — там простое текстовое поле ([rich] = false).
 */
class RichEditorState(initialHtml: String) {
    /** Текущий HTML поля; обновляется из редактора (с задержкой ~150 мс после ввода). */
    var html by mutableStateOf(initialHtml)
        private set
    var bold by mutableStateOf(false); private set
    var italic by mutableStateOf(false); private set
    var underline by mutableStateOf(false); private set
    var bullets by mutableStateOf(false); private set
    var numbers by mutableStateOf(false); private set
    var quote by mutableStateOf(false); private set
    var focused by mutableStateOf(false); private set
    /** Высота содержимого, dp: поле растёт вместе с текстом, прокручивается весь экран письма. */
    var contentHeight by mutableStateOf(0f); private set
    /** Поддерживает ли платформа оформление (на ПК — нет). */
    var rich by mutableStateOf(true)
    /** Где курсор (dp от верха поля) — экран письма подкручивает его в видимую часть. */
    var onCaret: ((top: Float, bottom: Float) -> Unit)? = null
    var onEdit: (() -> Unit)? = null
    var onNote: ((String) -> Unit)? = null

    /** Платформа: завершить слово, которое клавиатура ещё «набирает» (иначе её вставка перебьёт команду). */
    var finishInput: (() -> Unit)? = null
    private var run: ((String) -> Unit)? = null
    private val pending = mutableListOf<String>()

    private fun js(code: String) { run?.invoke(code) ?: pending.add(code) }

    /** Платформа сообщает, что документ загружен: первым делом кладём в поле текст. */
    fun attach(runner: (String) -> Unit) {
        run = runner
        runner("ed.set(${jsStr(html)})")
        pending.forEach(runner); pending.clear()
    }

    fun detach() { run = null; focused = false }

    fun cmd(name: String, value: String? = null) {
        finishInput?.invoke()
        js("ed.cmd(${jsStr(name)},${value?.let { jsStr(it) } ?: "null"})")
    }
    fun link(url: String) = js("ed.link(${jsStr(url)})")
    fun image(dataUrl: String) = js("ed.image(${jsStr(dataUrl)})")
    fun focus() = js("ed.focus()")
    /** Убрать курсор из поля (и клавиатуру — вместе с [finishInput] платформы). */
    fun blur() = js("ed.blur()")
    fun reportCaret() = js("ed.caret()")

    /** Заменить текст целиком (вставка быстрого ответа, смена шаблона). */
    fun replaceHtml(h: String) { html = h; js("ed.set(${jsStr(h)})") }

    /** На ПК: поле простое, HTML собирается из текста. */
    fun setFromPlain(h: String) { html = h; onEdit?.invoke() }

    /** Сообщение из страницы редактора (JSON). */
    fun onMessage(raw: String) {
        val o: JsonObject = runCatching { Json.parseToJsonElement(raw).jsonObject }.getOrNull() ?: return
        fun b(k: String) = o[k]?.jsonPrimitive?.boolean ?: false
        fun f(k: String) = o[k]?.jsonPrimitive?.float ?: 0f
        when (o["t"]?.jsonPrimitive?.content) {
            "html" -> { val v = o["v"]?.jsonPrimitive?.content ?: return; if (v != html) { html = v; onEdit?.invoke() } }
            "state" -> { bold = b("b"); italic = b("i"); underline = b("u"); bullets = b("ul"); numbers = b("ol"); quote = b("q") }
            "height" -> contentHeight = f("v")
            "focus" -> focused = b("v")
            "caret" -> onCaret?.invoke(f("top"), f("bottom"))
            "note" -> o["v"]?.jsonPrimitive?.content?.let { onNote?.invoke(it) }
        }
    }

    companion object {
        fun jsStr(s: String): String = Json.encodeToString(String.serializer(), s)
            .replace("\u2028", "\\u2028").replace("\u2029", "\\u2029")
    }
}

/** Поле текста письма. [loadResource] — картинки из черновика (/mail/api/…), как у [HtmlView]. */
@Composable
expect fun RichEditor(
    state: RichEditorState,
    dark: Boolean,
    placeholder: String,
    modifier: Modifier,
    loadResource: suspend (path: String) -> Pair<String, ByteArray>?,
)

fun editorNonce(): String = (1..16).map { "abcdefghijklmnopqrstuvwxyz0123456789"[Random.nextInt(36)] }.joinToString("")

/**
 * Страница редактора. [bridge] — строка JS, задающая window.__post(строка) для платформы.
 * Скрипт разрешён только свой (nonce): обработчики вида onerror= в тексте черновика не выполнятся,
 * а сам текст ещё и чистится при загрузке и вставке.
 */
fun editorDocument(dark: Boolean, placeholder: String, bridge: String, nonce: String): String {
    // Цвета гаммы «А» (ui/Theme.kt): текст, подсказка, синяя ссылка (оранжевый — только у действий), линии.
    val text = if (dark) "#EDEBE7" else "#2B3036"
    val faint = if (dark) "#A3A9B2" else "#6B7280"
    val link = if (dark) "#7FA8FF" else "#1D5FD1"
    val line = if (dark) "#4A515A" else "#CFCBC4"
    val ph = placeholder.replace("\\", "\\\\").replace("\"", "\\\"")
    return """<!DOCTYPE html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'nonce-$nonce'; style-src 'unsafe-inline'; img-src data: https: cid:">
<style>
html,body{margin:0;padding:0;background:transparent;color:$text;}
body{font:16px/1.5 -apple-system,Roboto,"Segoe UI",Arial,sans-serif;-webkit-text-size-adjust:100%;}
#ed{position:relative;outline:none;padding:12px 16px 16px;min-height:140px;word-wrap:break-word;overflow-wrap:anywhere;caret-color:$link;}
#ed.blank:before{content:"$ph";color:$faint;position:absolute;left:16px;top:12px;pointer-events:none;}
#ed p{margin:0 0 .6em;}
#ed img{max-width:100%;height:auto;}
#ed blockquote{margin:6px 0;padding-left:10px;border-left:3px solid $line;}
#ed a{color:$link;}
#ed table{max-width:100%;border-collapse:collapse;}
#ed td,#ed th{border:1px solid $line;padding:2px 6px;}
</style></head><body><div id="ed" contenteditable="true" spellcheck="true" autocapitalize="sentences"></div>
<script nonce="$nonce">
$bridge
(function(){
var el=document.getElementById('ed'), saved=null, t=null, lastH=-1, composing=false, queued=[];
// Пока клавиатура «набирает» слово, команды ждут: её вставка пришла бы после них и всё перепутала.
el.addEventListener('compositionstart',function(){composing=true;});
el.addEventListener('compositionend',function(){composing=false; setTimeout(function(){ var q=queued; queued=[]; q.forEach(function(f){f();}); },0);});
function later(f){ if(composing){queued.push(f);} else f(); }
function post(o){try{window.__post(JSON.stringify(o));}catch(e){}}
function isBlank(){ if(el.querySelector('img,blockquote,table,ul,ol'))return false; return el.textContent.replace(/\u00a0/g,' ').trim()===''; }
function height(){ var h=Math.ceil(el.getBoundingClientRect().height); if(h!==lastH){lastH=h;post({t:'height',v:h});} }
function q(n){try{return document.queryCommandState(n);}catch(e){return false;}}
function inQuote(){ var s=getSelection(); if(!s.rangeCount)return false; var n=s.anchorNode; while(n&&n!==el){ if(n.nodeName==='BLOCKQUOTE')return true; n=n.parentNode;} return false; }
function state(){ post({t:'state',b:q('bold'),i:q('italic'),u:q('underline'),ul:q('insertUnorderedList'),ol:q('insertOrderedList'),q:inQuote()}); }
function caret(){
  var s=getSelection(); if(!s.rangeCount||!el.contains(s.anchorNode))return;
  var r=s.getRangeAt(0).cloneRange(), rect=r.getBoundingClientRect();
  if(!rect||(!rect.height&&!rect.top)){ var m=document.createElement('span'); m.textContent='\u200b'; r.insertNode(m); rect=m.getBoundingClientRect(); m.parentNode.removeChild(m); }
  post({t:'caret',top:rect.top+window.scrollY,bottom:rect.bottom+window.scrollY});
}
function sync(){ el.classList.toggle('blank',isBlank()); height(); clearTimeout(t); t=setTimeout(function(){post({t:'html',v:el.innerHTML.split(ZW).join('')});},150); }
// Жирный, курсив, подчёркнутый без выделения: клавиатура Android сбрасывает «стиль набора» Chrome,
// поэтому ставим курсор внутрь самого элемента (как почта Gmail), а повторное нажатие выводит из него.
var ZW=String.fromCharCode(8203);   // невидимый пробел: держит курсор внутри пустого элемента
var TAGS={bold:['B','STRONG'],italic:['I','EM'],underline:['U']};
function inlineToggle(n){
  var s=getSelection(); if(!s.rangeCount)return false; var r=s.getRangeAt(0);
  if(!r.collapsed||!TAGS[n])return false;
  var node=r.startContainer, inside=null;
  while(node&&node!==el){ if(node.nodeType===1&&TAGS[n].indexOf(node.nodeName)>=0){inside=node;break;} node=node.parentNode; }
  var z=document.createTextNode(ZW), nr=document.createRange();
  if(inside){ inside.parentNode.insertBefore(z,inside.nextSibling); }
  else { var w=document.createElement(TAGS[n][0]); w.appendChild(z); r.insertNode(w); }
  nr.setStart(z,1); nr.collapse(true); s.removeAllRanges(); s.addRange(nr); saved=nr.cloneRange();
  return true;
}
function save(){ var s=getSelection(); if(s.rangeCount&&el.contains(s.anchorNode))saved=s.getRangeAt(0).cloneRange(); }
// Выделение возвращаем, только если поле теряло фокус (окно ссылки, выбор картинки): selectionchange
// приходит с задержкой, и «сохранённое» место могло отстать от курсора.
function restore(){ var s=getSelection(); if(document.activeElement===el&&s.rangeCount&&el.contains(s.anchorNode))return; el.focus(); if(saved){ s.removeAllRanges(); s.addRange(saved);} }
// Черновик мог прийти из чужого письма («Изменить как новое»): убираем активное содержимое.
function safe(html){
  var d=new DOMParser().parseFromString('<div>'+html+'</div>','text/html'), root=d.body.firstChild;
  root.querySelectorAll('script,iframe,object,embed,link,meta,style,form,input,button,textarea,select').forEach(function(n){n.remove();});
  root.querySelectorAll('*').forEach(function(n){ [].slice.call(n.attributes).forEach(function(a){ var k=a.name.toLowerCase(); if(k.indexOf('on')===0||((k==='href'||k==='src')&&/^\s*javascript:/i.test(a.value)))n.removeAttribute(a.name); }); });
  return root.innerHTML;
}
// Вставка из Word, Excel и с сайтов — как в веб-почте: структура остаётся, чужие шрифты и цвета — нет.
var KEEP={A:1,B:1,STRONG:1,I:1,EM:1,U:1,S:1,STRIKE:1,BR:1,P:1,DIV:1,SPAN:1,UL:1,OL:1,LI:1,BLOCKQUOTE:1,TABLE:1,THEAD:1,TBODY:1,TR:1,TD:1,TH:1,H1:1,H2:1,H3:1,H4:1,H5:1,H6:1,PRE:1,CODE:1,HR:1};
function clean(raw){
  var doc=new DOMParser().parseFromString(raw,'text/html');
  function walk(node){
    [].slice.call(node.children).forEach(walk);
    var tag=node.tagName;
    if(!KEEP[tag]){ node.replaceWith.apply(node,[].slice.call(node.childNodes)); return; }
    [].slice.call(node.attributes).forEach(function(a){ var n=a.name.toLowerCase();
      var ok=(tag==='A'&&n==='href'&&/^(https?:|mailto:|tel:)/i.test(a.value))||((tag==='TD'||tag==='TH')&&(n==='colspan'||n==='rowspan'));
      if(!ok)node.removeAttribute(a.name); });
    if(tag==='SPAN'&&!node.attributes.length)node.replaceWith.apply(node,[].slice.call(node.childNodes));
  }
  [].slice.call(doc.body.children).forEach(walk);
  return doc.body.innerHTML.replace(/<!--[\s\S]*?-->/g,'').replace(/\u00a0/g,' ');
}
var focusSent=false;
function focusOn(v){ if(v!==focusSent){focusSent=v;post({t:'focus',v:v});} }
el.addEventListener('input',function(){focusOn(true);sync();state();caret();});
el.addEventListener('focus',function(){focusOn(true);setTimeout(caret,300);});
el.addEventListener('blur',function(){save();focusOn(false);});
el.addEventListener('keyup',function(){state();caret();});
document.addEventListener('selectionchange',function(){ if(document.activeElement===el){save();state();} });
el.addEventListener('paste',function(e){
  var cd=e.clipboardData; if(!cd)return;
  var img=[].slice.call(cd.files||[]).filter(function(f){return f.type.indexOf('image/')===0;})[0];
  if(img){ e.preventDefault(); if(img.size>400*1024){post({t:'note',v:'Картинка больше 400 КБ — приложите её файлом'});return;}
    var fr=new FileReader(); fr.onload=function(){document.execCommand('insertHTML',false,'<img src="'+fr.result+'" alt="" style="max-width:100%;height:auto">');sync();}; fr.readAsDataURL(img); return; }
  var rich=cd.getData('text/html');
  if(rich&&rich.trim()){ e.preventDefault(); document.execCommand('insertHTML',false,clean(rich)); sync(); return; }
  var text=cd.getData('text/plain');
  if(text){ e.preventDefault(); document.execCommand('insertText',false,text); sync(); }
});
if(window.ResizeObserver)new ResizeObserver(height).observe(el);
window.ed={
  set:function(h){ el.innerHTML=safe(h||''); el.classList.toggle('blank',isBlank()); height(); },
  cmd:function(n,v){ later(function(){ runCmd(n,v); }); },
  link:function(url){ later(function(){ runLink(url); }); },
  image:function(src){ later(function(){ restore(); document.execCommand('insertHTML',false,'<img src="'+src+'" alt="" style="max-width:100%;height:auto">'); sync(); }); },
  focus:function(){ restore(); caret(); },
  blur:function(){ save(); el.blur(); },
  caret:caret
};
function runCmd(n,v){ restore();
    if(inlineToggle(n)){ sync(); var o={t:'state',b:q('bold'),i:q('italic'),u:q('underline'),ul:q('insertUnorderedList'),ol:q('insertOrderedList'),q:inQuote()}; post(o); return; }
    if(n==='formatBlock'&&v==='blockquote'&&inQuote())document.execCommand('outdent',false,null); else document.execCommand(n,false,v); sync(); state(); }
function runLink(url){ restore(); var s=getSelection();
    if(!s.rangeCount||s.isCollapsed){ var e=url.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); document.execCommand('insertHTML',false,'<a href="'+e+'">'+e+'</a>&nbsp;'); }
    else document.execCommand('createLink',false,url);
    sync(); }
})();
</script></body></html>"""
}
