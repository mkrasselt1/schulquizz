#!/usr/bin/env bash
# End-to-End-Smoke-Test des echten Standalone-Images gegen eine echte MariaDB.
# Fängt genau die Bug-Klasse ab, die SQLite-/Fake-Broadcast-Unit-Tests NICHT sehen:
#  - Migrations-Reihenfolge / Fremdschlüssel auf MySQL
#  - Reverb-Erreichbarkeit (Join-Broadcast) + Nachrichtengröße (Bild-Antworten)
#  - Container-Boot / Entrypoint / HTTP-Auslieferung
#
# Nutzung:  bash scripts/smoke-test.sh
# Voraussetzung: docker. Exit-Code 0 = alles grün.
set -euo pipefail

IMAGE="${IMAGE:-schulquizz:smoke}"
NET=sqsmoke
DB=sqsmoke-db
APP=sqsmoke-app
PORT="${PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"

pass() { echo "  ✅ $1"; }
fail() { echo "  ❌ $1"; cleanup; exit 1; }

cleanup() {
    docker rm -f "$APP" "$DB" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "== 1. Standalone-Image bauen =="
docker build --target standalone -t "$IMAGE" . >/dev/null

echo "== 2. MariaDB starten =="
docker network create "$NET" >/dev/null 2>&1 || true
docker rm -f "$DB" "$APP" >/dev/null 2>&1 || true
docker run -d --name "$DB" --network "$NET" \
    -e MARIADB_DATABASE=sq -e MARIADB_USER=sq -e MARIADB_PASSWORD=sq \
    -e MARIADB_ROOT_PASSWORD=root mariadb:11 >/dev/null
# auf DB warten
for i in $(seq 1 30); do
    docker exec "$DB" mariadb-admin ping -h127.0.0.1 -uroot -proot >/dev/null 2>&1 && break
    sleep 2
done

echo "== 3. App gegen MariaDB starten =="
docker run -d --name "$APP" --network "$NET" -p "${PORT}:80" \
    -e DB_CONNECTION=mysql -e DB_HOST="$DB" -e DB_PORT=3306 \
    -e DB_DATABASE=sq -e DB_USERNAME=sq -e DB_PASSWORD=sq "$IMAGE" >/dev/null
for i in $(seq 1 30); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/login" || true)" = "200" ] && break
    sleep 2
done

echo "== 4. Migrationen: alle DONE, kein FAIL/SQLSTATE? =="
LOGS="$(docker logs "$APP" 2>&1)"
echo "$LOGS" | grep -q "Init abgeschlossen" && pass "Init abgeschlossen" || fail "Init nicht abgeschlossen"
echo "$LOGS" | grep -qE "FAIL|SQLSTATE" && fail "Migration FAIL/SQLSTATE im Log" || pass "keine Migrations-Fehler"

echo "== 5. HTTP-Endpunkte =="
for path in / /login /register /join; do
    code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE$path")"
    [ "$code" = "200" ] && pass "$path -> 200" || fail "$path -> $code"
done

echo "== 6. Spielablauf: Session + Beitritt + große Bild-Antwort =="
docker exec "$APP" php artisan tinker --execute='
$u=App\Models\User::create(["name"=>"S","email"=>"s@s.de","password"=>bcrypt("x")]);
$g=new App\Models\Game(); $g->user_id=$u->id; $g->title="S"; $g->save();
$q=App\Support\QuestionDefaults::create($g->refresh(),"draw",0);
$s=app(App\Services\SessionService::class)->start($g,["join_method"=>"qr"]);
app(App\Services\SessionService::class)->showQuestion($s,$q);
file_put_contents("/tmp/tok",$g->join_token); file_put_contents("/tmp/qid",(string)$q->id);
' >/dev/null 2>&1 || fail "Testdaten-Anlage fehlgeschlagen"

TOKEN="$(docker exec "$APP" cat /tmp/tok)"
QID="$(docker exec "$APP" cat /tmp/qid)"
CJ="$(mktemp)"
curl -s -c "$CJ" "$BASE/j/$TOKEN" -o /dev/null
XSRF="$(grep XSRF-TOKEN "$CJ" | awk '{print $7}' | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')"
# Beitreten (Broadcast "joined" an Reverb -> prüft Reverb-Erreichbarkeit)
jcode="$(curl -s -b "$CJ" -c "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/join" \
    -H "X-XSRF-TOKEN: $XSRF" -H "X-Requested-With: XMLHttpRequest" \
    --data-urlencode "join_token=$TOKEN" --data-urlencode "display_type=emoji")"
[ "$jcode" = "302" ] || fail "Beitritt -> $jcode (erwartet 302; Reverb erreichbar?)"
pass "Beitritt 302 (Reverb-Broadcast ok)"

# Große Bild-Antwort (~60 KB) -> prüft REVERB_MAX_REQUEST_SIZE
BIG="data:image/png;base64,$(head -c 60000 /dev/zero | base64 | tr -d '\n')"
acode="$(curl -s -b "$CJ" -o /dev/null -w '%{http_code}' -X POST "$BASE/play/answer" \
    -H "X-XSRF-TOKEN: $XSRF" -H "X-Requested-With: XMLHttpRequest" -H "Accept: application/json" \
    --data-urlencode "question_id=$QID" --data-urlencode "answer=$BIG" --data-urlencode "response_ms=1000")"
[ "$acode" = "200" ] || fail "Bild-Antwort -> $acode (erwartet 200; Reverb-Nachrichtengröße?)"
pass "Bild-Antwort 200 (Reverb-Größe ok)"

echo "== 7. Keine Server-Fehler im Log =="
errs="$(docker exec "$APP" sh -c 'grep -c "production.ERROR" storage/logs/laravel.log 2>/dev/null || echo 0')"
[ "$errs" = "0" ] && pass "0 Fehler im Laravel-Log" || fail "$errs Fehler im Laravel-Log"

echo
echo "🎉 SMOKE-TEST BESTANDEN"
