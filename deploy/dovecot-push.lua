-- Dovecot push_notification (драйвер lua): новое письмо во «Входящих» → POST в веб-почту,
-- та шлёт push-уведомления на устройства сотрудника (PushNotifier, /mail/api/push/event).
-- Файл ставит deploy/dovecot-push.sh, подставляя адрес и токен из .env; вручную не править.
-- Письма в другие папки (правила, общие ящики) не трогаем: уведомление — только про «Входящие».

local URL = "__URL__"
local TOKEN = "__TOKEN__"

local http_client = dovecot.http.client { timeout = 5000; max_attempts = 2; debug = false }

-- JSON-строка: кавычки, обратная косая и управляющие символы — через \uXXXX, остальное как есть (UTF-8).
local function esc(s)
  s = tostring(s or "")
  return (s:gsub('[%c"\\]', function(c) return string.format("\\u%04x", c:byte()) end))
end

function dovecot_lua_notify_begin_txn(user)
  return { user = user.username, messages = {} }
end

function dovecot_lua_notify_event_message_new(ctx, event)
  if event.mailbox ~= "INBOX" then return end
  table.insert(ctx.messages, string.format('{"uid":%d,"from":"%s","subject":"%s","snippet":"%s"}',
    tonumber(event.uid) or 0, esc(event.from), esc(event.subject), esc(event.snippet)))
end

function dovecot_lua_notify_end_txn(ctx, success)
  if #ctx.messages == 0 then return end
  local req = http_client:request { url = URL; method = "POST" }
  req:set_payload('{"user":"' .. esc(ctx.user) .. '","messages":[' .. table.concat(ctx.messages, ",") .. ']}')
  req:add_header("Content-Type", "application/json")
  req:add_header("X-Push-Token", TOKEN)
  local resp = req:submit()
  local code = resp:status()
  if code ~= 200 then
    dovecot.i_warning("mailadmin-push: " .. tostring(code) .. " " .. tostring(resp:reason()))
  end
end
