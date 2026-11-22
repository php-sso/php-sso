#!/usr/bin/env python3
"""Load and race test against a running php-sso. Standard library only.

  tests/load/run.py http://localhost:8080 [CONCURRENCY] [SECONDS]

The instance must have the conformance test person and clients (php conformance/setup.php); never run this against a
real instance. It measures throughput and latency of the endpoints apps hit, then checks the properties that must hold
under concurrency: an authorization code and a refresh token can each be used once, however many requests race.
"""
import base64, hashlib, http.cookiejar, json, os, statistics, sys, threading, time, urllib.error, urllib.parse, urllib.request

BASE = sys.argv[1].rstrip('/')
CONC = int(sys.argv[2]) if len(sys.argv) > 2 else 20
SECS = float(sys.argv[3]) if len(sys.argv) > 3 else 10
CB = 'https://localhost.emobix.co.uk:8443/test/a/php-sso/callback'
CLIENT = ('conformance-1', 'conformance-secret-1')
failed = []


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def call(url, data=None, auth=None, headers=None, jar=None):
    handlers = [NoRedirect()] + ([urllib.request.HTTPCookieProcessor(jar)] if jar is not None else [])
    req = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode() if data is not None else None, headers=headers or {})
    if auth:
        req.add_header('Authorization', 'Basic ' + base64.b64encode(':'.join(auth).encode()).decode())
    try:
        r = urllib.request.build_opener(*handlers).open(req, timeout=30)
        return r.status, r.read(), r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read(), e.headers


def csrf(html):
    return html.decode().split('name="_csrf" value="')[1].split('"')[0]


def pkce():
    v = base64.urlsafe_b64encode(os.urandom(48)).rstrip(b'=').decode()
    return v, base64.urlsafe_b64encode(hashlib.sha256(v.encode()).digest()).rstrip(b'=').decode()


def sign_in():
    jar = http.cookiejar.CookieJar()
    c = csrf(call(BASE + '/login', jar=jar)[1])
    call(BASE + '/login', {'_csrf': c, 'username': 'conformance'}, jar=jar)
    c = csrf(call(BASE + '/login/password', jar=jar)[1])
    st, _, h = call(BASE + '/login/password', {'_csrf': c, 'password': 'conformance-pass'}, jar=jar)
    assert st in (302, 303), f'sign-in failed: {st}'
    return jar


def code_for(jar):
    v, ch = pkce()
    q = urllib.parse.urlencode({'client_id': CLIENT[0], 'redirect_uri': CB, 'response_type': 'code', 'scope': 'openid profile email', 'state': 's', 'nonce': 'n', 'code_challenge': ch, 'code_challenge_method': 'S256'})
    loc = call(BASE + '/authorize?' + q, jar=jar)[2].get('Location', '')
    return urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)['code'][0], v


def exchange(code, v):
    return call(BASE + '/token', {'grant_type': 'authorization_code', 'code': code, 'redirect_uri': CB, 'code_verifier': v}, auth=CLIENT)


def check(name, ok, detail=''):
    print(('ok    ' if ok else 'FAIL  ') + name + (f'  ({detail})' if detail else ''))
    if not ok:
        failed.append(name)


def race(n, fn):
    out, gate = [], threading.Barrier(n)

    def work():
        gate.wait()
        out.append(fn())
    ts = [threading.Thread(target=work) for _ in range(n)]
    [t.start() for t in ts]
    [t.join() for t in ts]
    return out


def throughput(name, fn):
    lat, errs, stop = [], [0], time.time() + SECS

    def work():
        while time.time() < stop:
            t = time.time()
            try:
                ok = fn()
            except Exception:
                ok = False
            lat.append(time.time() - t)
            if not ok:
                errs[0] += 1
    ts = [threading.Thread(target=work) for _ in range(CONC)]
    t0 = time.time()
    [t.start() for t in ts]
    [t.join() for t in ts]
    lat.sort()
    p = lambda q: lat[min(len(lat) - 1, int(len(lat) * q))] * 1000
    print(f'{name:<22}{len(lat) / (time.time() - t0):8.0f} req/s   p50 {p(.5):6.0f} ms   p95 {p(.95):6.0f} ms   p99 {p(.99):6.0f} ms   errors {errs[0]}')
    if errs[0]:
        failed.append(f'{name}: {errs[0]} errors')


print(f'== throughput, {CONC} concurrent clients, {SECS:.0f} s each')
jar = sign_in()
code, v = code_for(jar)
tokens = json.loads(exchange(code, v)[1])
bearer = {'Authorization': 'Bearer ' + tokens['access_token']}
throughput('discovery', lambda: call(BASE + '/.well-known/openid-configuration')[0] == 200)
throughput('jwks', lambda: call(BASE + '/jwks')[0] == 200)
throughput('sign-in page', lambda: call(BASE + '/login')[0] == 200)
throughput('userinfo (bearer)', lambda: call(BASE + '/userinfo', headers=bearer)[0] == 200)

print('== races (all requests start together)')
code, v = code_for(jar)
res = [r[0] for r in race(20, lambda: exchange(code, v))]
check('one authorization code, 20 racing exchanges: exactly one succeeds', res.count(200) == 1, f'{res.count(200)} succeeded, rest {sorted(set(res) - {200})}')
t = json.loads(exchange(*code_for(jar))[1])
res = [r[0] for r in race(20, lambda: call(BASE + '/token', {'grant_type': 'refresh_token', 'refresh_token': t['refresh_token']}, auth=CLIENT))]
check('one refresh token, 20 racing refreshes: at most one succeeds', res.count(200) <= 1, f'{res.count(200)} succeeded')
# Last, because it makes the server slow this address down on purpose (429), which would spoil the checks above.
throughput('token, bad secret', lambda: call(BASE + '/token', {'grant_type': 'authorization_code', 'code': 'x'}, auth=('conformance-1', 'wrong'))[0] in (401, 429))
check('the server still answers afterwards', call(BASE + '/healthz')[0] == 200)

print('FAILED: ' + ', '.join(failed) if failed else 'LOAD AND RACE TEST PASSED')
sys.exit(1 if failed else 0)
