#!/usr/bin/env bash
# Upgrade test: installs a released version, creates people, a session and tokens with it, then runs THIS checkout on
# the same data and checks that nothing was lost. Needs Docker and network access (for composer).
#
#   tests/upgrade/run.sh [TAG]       default TAG: v0.1.0
set -euo pipefail

TAG=${1:-v0.1.0}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
W=$(mktemp -d)
PORT=9100
B=http://localhost:$PORT
cleanup() { docker rm -f upg-old upg-new >/dev/null 2>&1 || true; rm -rf "$W"; }
trap cleanup EXIT
fail() { echo "FAILED: $*" >&2; exit 1; }
tok() { grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
serve() { # dir name
    docker rm -f "$2" >/dev/null 2>&1 || true
    docker run -d --name "$2" -p $PORT:$PORT --user "$(id -u):$(id -g)" -v "$1":/work -v "$W/var":/work/var -w /work \
        -e SSO_PUBLIC_URL=$B -e ADMIN_PASSWORD=upgrade-admin php:8.4-cli php -S 0.0.0.0:$PORT -t public >/dev/null
    for _ in $(seq 1 30); do curl -s -o /dev/null $B/.well-known/openid-configuration && return; sleep 1; done
    fail "$2 did not start"
}

mkdir -p "$W/old" "$W/var"; chmod 777 "$W/var"
git -C "$ROOT" archive "$TAG" | tar -x -C "$W/old"
docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp -v "$W/old":/w -w /w composer:2 install -q --no-dev --no-interaction --ignore-platform-reqs >/dev/null

echo "== $TAG: create data"
serve "$W/old" upg-old
docker exec upg-old php conformance/setup.php >/dev/null
VER=$(head -c 48 /dev/urandom | base64 | tr '+/' '-_' | tr -d '=\n')
CH=$(printf %s "$VER" | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=\n')
CB=https://localhost.emobix.co.uk:8443/test/a/php-sso/callback
AUTH="$B/authorize?client_id=conformance-1&redirect_uri=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1],safe=''))" "$CB")&response_type=code&scope=openid%20profile%20email&state=s&nonce=n&code_challenge=$CH&code_challenge_method=S256"
C=$(curl -s -c "$W/jar" $B/login | tok)
curl -s -b "$W/jar" -c "$W/jar" -o /dev/null -d "_csrf=$C&username=conformance&password=conformance-pass" $B/login
CODE=$(curl -s -b "$W/jar" -c "$W/jar" -o /dev/null -w '%{redirect_url}' "$AUTH" | grep -o 'code=[^&]*' | cut -d= -f2)
[ -n "$CODE" ] || fail "no authorization code from $TAG"
curl -s -u conformance-1:conformance-secret-1 -d "grant_type=authorization_code&code=$CODE&redirect_uri=$CB&code_verifier=$VER" $B/token > "$W/tokens.json"
docker rm -f upg-old >/dev/null
cp "$W/var/sso.sqlite" "$W/before.sqlite"

echo "== this checkout: run on the same data"
serve "$ROOT" upg-new
python3 -I - "$W" "$B" <<'PY'
import base64, json, sqlite3, sys, urllib.error, urllib.parse, urllib.request
W, B = sys.argv[1], sys.argv[2]
d = json.load(open(f'{W}/tokens.json'))
bad = []
def req(url, data=None, auth=None, hdr=None):
    r = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode() if data else None, headers=hdr or {})
    if auth: r.add_header('Authorization', 'Basic ' + base64.b64encode(auth.encode()).decode())
    try:
        with urllib.request.urlopen(r) as f: return f.status, json.load(f)
    except urllib.error.HTTPError as e: return e.code, None
def check(name, ok):
    print(('ok   ' if ok else 'FAIL ') + name)
    if not ok: bad.append(name)
st, info = req(B + '/userinfo', hdr={'Authorization': 'Bearer ' + d['access_token']})
check('an access token from the old version still works', st == 200 and info.get('name') == 'Conformance Tester')
jw = req(req(B + '/.well-known/openid-configuration')[1]['jwks_uri'])[1]
kid = json.loads(base64.urlsafe_b64decode(d['id_token'].split('.')[0] + '=='))['kid']
check('the old signing key is still published', kid in [k['kid'] for k in jw['keys']])
st, _ = req(B + '/token', {'grant_type': 'refresh_token', 'refresh_token': d['refresh_token']}, auth='conformance-1:conformance-secret-1')
check('a refresh token from the old version still works', st == 200)
a, b = sqlite3.connect(f'{W}/before.sqlite'), sqlite3.connect(f'{W}/var/sso.sqlite')
for t in ('users', 'clients', 'login_sessions'):
    check(f'{t}: same number of rows', a.execute(f'select count(*) from {t}').fetchone() == b.execute(f'select count(*) from {t}').fetchone())
q = 'select sub, username, password_hash, email from users order by sub'
check('people and their password hashes are unchanged', a.execute(q).fetchall() == b.execute(q).fetchall())
check('database integrity', b.execute('pragma integrity_check').fetchone()[0] == 'ok' and not b.execute('pragma foreign_key_check').fetchall())
sys.exit(1 if bad else 0)
PY
C=$(curl -s -c "$W/n" $B/login | tok)
curl -s -b "$W/n" -c "$W/n" -o /dev/null -d "_csrf=$C&username=conformance" $B/login
C=$(curl -s -b "$W/n" -c "$W/n" $B/login/password | tok)
curl -s -b "$W/n" -c "$W/n" -o /dev/null -d "_csrf=$C&password=conformance-pass" $B/login/password
[ "$(curl -s -b "$W/n" -o /dev/null -w '%{http_code}' $B/account)" = 200 ] || fail "a person from $TAG cannot sign in"
echo "ok   a person from $TAG signs in with the new sign-in steps"
echo "UPGRADE FROM $TAG PASSED"
