#!/bin/bash
# Ночная проверка почтового сервера: что было за сутки и как это соотносится с днём раньше.
# Запуск: scp deploy/nightcheck.sh admin1@сервер:/tmp/ && ssh admin1@сервер bash /tmp/nightcheck.sh
# Читает журналы через sudo; ничего не меняет.
D1=$(date -d '1 day ago' +%Y-%m-%d); D0=$(date +%Y-%m-%d); D2=$(date -d '2 days ago' +%Y-%m-%d)
echo "### $(hostname) $(date '+%F %T %z'); uptime: $(uptime | sed 's/.*up //')"
echo "диск: $(df -h / | awk 'NR==2{print $3" из "$2" ("$5")"}'); память: $(free -m | awk 'NR==2{print $3" МБ из "$2}'); vmail: $(df -h /var/vmail 2>/dev/null | awk 'NR==2{print $5}')"
echo
echo "### службы"
for s in nginx dovecot postfix clamav-daemon mariadb mysql redis-server; do systemctl is-active $s >/dev/null 2>&1 && echo -n "$s: ok  " ; done; echo
for s in $(systemctl list-units --type=service --no-legend --no-pager | awk '{print $1}' | grep -iE "octane|roadrunner|mailadmin|horizon|queue"); do
  echo "$s: $(systemctl is-active $s), запущен $(systemctl show $s -p ActiveEnterTimestamp --value | cut -c1-25), перезапусков $(systemctl show $s -p NRestarts --value)"
done
echo
echo "### laravel.log: записи за $D1 и $D0 (уровень, сколько, текст)"
for f in /opt/mailadmin/storage/logs/laravel-$D1.log /opt/mailadmin/storage/logs/laravel-$D0.log /opt/mailadmin/storage/logs/laravel.log; do
  [ -f "$f" ] || continue
  echo "--- $(basename $f) ($(sudo wc -l < "$f") строк)"
  sudo grep -aE "^\[($D1|$D0) " "$f" | sed -E 's/^\[[^]]+\] [a-z]+\.//' | sed -E 's/^([A-Z]+): (.{0,110}).*/\1 | \2/' | sed -E 's/[0-9a-f]{16,}/…/g; s/uid[ =:]+[0-9]+/uid N/g; s/[0-9]{2,}/N/g' | sort | uniq -c | sort -rn | head -25
done
echo "--- для сравнения, $D2:"
[ -f /opt/mailadmin/storage/logs/laravel-$D2.log ] && sudo grep -aE "^\[$D2 " /opt/mailadmin/storage/logs/laravel-$D2.log | sed -E 's/^\[[^]]+\] [a-z]+\.//' | sed -E 's/^([A-Z]+): (.{0,90}).*/\1 | \2/' | sed -E 's/[0-9]{2,}/N/g' | sort | uniq -c | sort -rn | head -8
echo
echo "### nginx: ответы за сутки по кодам (вчера / позавчера)"
for d in $D1 $D2; do
  dd=$(LC_ALL=C date -d "$d" +%d/%b/%Y)
  echo -n "$d: "; sudo zcat -f /var/log/nginx/mailweb.access.log /var/log/nginx/mailweb.access.log.1 /var/log/nginx/mailweb.access.log /var/log/nginx/mailweb.access.log.1 /var/log/nginx/access.log /var/log/nginx/access.log.1 2>/dev/null | grep -a "\[$dd" | awk '{c[$9]++} END{for(k in c) printf "%s=%d ", k, c[k]; print ""}'
done
echo "--- 5xx и 499 за $D1 по адресам:"
sudo zcat -f /var/log/nginx/mailweb.access.log /var/log/nginx/mailweb.access.log.1 /var/log/nginx/access.log /var/log/nginx/access.log.1 2>/dev/null | grep -a "\[$(LC_ALL=C date -d "$D1" +%d/%b/%Y)" | awk '$9>=500 || $9==499 {print $9, $6, $7}' | sed -E 's/\/[0-9]+/\/N/g; s/\?.*//' | sort | uniq -c | sort -rn | head -12
echo "--- nginx error.log за $D1/$D0:"
sudo grep -ahE "^$(date -d "$D1" +%Y/%m/%d)|^$(date +%Y/%m/%d)" /var/log/nginx/error.log 2>/dev/null | sed -E 's/^[^ ]+ [^ ]+ \[([a-z]+)\] [0-9#]+: \*[0-9]+ //' | sed -E 's/, client: .*//; s/[0-9]{2,}/N/g' | sort | uniq -c | sort -rn | head -10
echo "--- уникальных ящиков в вебе (по /mail/api за $D1):"
sudo zcat -f /var/log/nginx/mailweb.access.log /var/log/nginx/mailweb.access.log.1 /var/log/nginx/access.log /var/log/nginx/access.log.1 2>/dev/null | grep -a "\[$(LC_ALL=C date -d "$D1" +%d/%b/%Y)" | grep -a "/mail/api/" | awk '{print $1}' | sort -u | wc -l
echo
echo "### dovecot за $D1 (ошибки/предупреждения) и входы"
LOG=$(ls /var/log/dovecot/dovecot.log /var/log/dovecot.log /var/log/mail.log 2>/dev/null | head -1)
sudo grep -ah "^$D1" $LOG 2>/dev/null | grep -aiE "error|warning|panic|fatal" | sed -E 's/^[^ ]+ [^ ]+ //; s/<[^>]+>//g; s/[0-9]{2,}/N/g; s/[a-z0-9._-]+@[a-z0-9.-]+/USER/g' | cut -c1-140 | sort | uniq -c | sort -rn | head -12
echo -n "входов imap: "; sudo grep -ah "^$D1" $LOG | grep -ac "imap-login: Login"
echo -n "неудачных входов: "; sudo grep -ah "^$D1" $LOG | grep -aciE "auth failed|Disconnected \(auth failed"
echo -n "по подключениям с ошибкой: "; sudo grep -ah "^$D1" $LOG | grep -ac "Connection closed.*(bytes"
echo
echo "### postfix за $D1"
ML=/var/log/mail.log
echo -n "принято (status=sent): "; sudo grep -ah "^$D1" $ML 2>/dev/null | grep -ac "status=sent"
echo -n "отложено (deferred): "; sudo grep -ah "^$D1" $ML | grep -ac "status=deferred"
echo -n "отказано (bounced): "; sudo grep -ah "^$D1" $ML | grep -ac "status=bounced"
echo -n "reject: "; sudo grep -ah "^$D1" $ML | grep -ac "NOQUEUE: reject"
echo "очередь: $(mailq 2>/dev/null | tail -1)"
sudo grep -ah "^$D1" $ML | grep -aE "status=(deferred|bounced)" | sed -E 's/.*to=<([^>]+)>.*status=([a-z]+) \((.{0,90}).*/\2 \1: \3/' | sed -E 's/[0-9]{3,}/N/g' | sort | uniq -c | sort -rn | head -8
echo
echo "### хранилище файлов и ночные задания"
cd /opt/mailadmin && sudo -u www-data env HOME=/tmp php artisan tinker --execute='
$t=DB::table("webmail_files"); echo "файлов всего: ".$t->count().", за сутки: ".$t->where("created_at",">=",now()->subDay())->count().", проверено сегодня: ".DB::table("webmail_files")->where("checked_at",">=",now()->startOfDay())->count().", не проверялись ни разу: ".DB::table("webmail_files")->whereNull("checked_at")->count()."\n";
' 2>/dev/null | tail -1
sudo grep -ahE "files:(check|purge)|ФайлыПроверка|files check" /opt/mailadmin/storage/logs/laravel-$D0.log /opt/mailadmin/storage/logs/laravel.log 2>/dev/null | tail -3 | cut -c1-160
sudo journalctl --since "$D0 04:00" --until "$D0 05:00" --no-pager 2>/dev/null | grep -iE "artisan|schedule" | tail -3 | cut -c1-160
echo
echo "### журнал действий (webmail_activity) за сутки"
sudo -u www-data env HOME=/tmp php artisan tinker --execute='
$q=DB::table("webmail_activity")->where("at",">=",now()->subDay())->where("master",0); echo "сотрудников ".$q->clone()->distinct()->count("user").", действий ".$q->clone()->count().", ошибок 5xx ".$q->clone()->where("status",">=",500)->count().", отправлено ".$q->clone()->where("action","send")->count()."
";
' 2>/dev/null | tail -1
echo
echo "### обращения сотрудников"
sudo -u www-data env HOME=/tmp php artisan tinker --execute='
foreach (DB::table("feedback_tickets")->where("updated_at",">=",now()->subDay())->orderBy("id")->get() as $t) echo "№".$t->id." [".$t->status."/".($t->resolution??"-")."] ".$t->user." — ".$t->subject." (".$t->updated_at.")\n";
echo "открытых всего: ".DB::table("feedback_tickets")->whereNotIn("status",["closed"])->count()."\n";
' 2>/dev/null | grep -v "^$" | tail -12
