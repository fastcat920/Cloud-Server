"""Check that updates invalidate the entry point and every imported module."""
import os, socket, subprocess, time, urllib.request
from pathlib import Path
root = Path(__file__).resolve().parents[1]
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
server = subprocess.Popen([os.environ.get('PHP_BINARY', 'php'), '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
base = f'http://127.0.0.1:{port}/index.php'
def get(query=''):
    return urllib.request.urlopen(base + query, timeout=5)
try:
    for _ in range(50):
        try:
            response = get()
            break
        except OSError:
            time.sleep(.1)
    html = response.read().decode()
    assert response.headers['Cache-Control'] == 'no-store'
    assert 'index.php?asset=app.js&amp;v=' in html
    app = get('?asset=app.js').read().decode()
    assert '"./index.php?asset=config-form.js&v=' in app
    assert '"./index.php?asset=ui.js&v=' in app
    assert '"./index.php?asset=dns.js&v=' in app
    assert 'export function dnsFullName' in get('?asset=dns.js').read().decode()
    assert '"./index.php?asset=notifications.js&v=' in app
    assert 'export function createNotifications' in get('?asset=notifications.js').read().decode()
    form = get('?asset=config-form.js').read().decode()
    assert '"./index.php?asset=ui.js&v=' in form
    assert '"./ui.js"' not in form
    assert 'text/javascript' in get('?asset=app.js').headers['Content-Type']
    try:
        get('?asset=../_core/data/key.php')
        raise AssertionError('Unexpected file exposed')
    except urllib.error.HTTPError as error:
        assert error.code == 404
    print('PASS: versioned entry point, recursive module URLs and asset allowlist')
finally:
    server.terminate()
    server.wait()
