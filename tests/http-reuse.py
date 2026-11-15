"""Verify real HTTPS connection reuse, request reset and gzip decoding on localhost."""
import gzip
import http.server
import json
import os
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading

ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="cloud-http-reuse-") as directory:
    cert, key = Path(directory)/"cert.pem", Path(directory)/"key.pem"
    subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(key), "-out", str(cert), "-subj", "/CN=localhost", "-addext", "subjectAltName=IP:127.0.0.1", "-days", "1"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    class Handler(http.server.BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"
        def log_message(self, *_):
            pass
        def respond(self):
            body = self.rfile.read(int(self.headers.get("Content-Length", "0"))).decode()
            data = json.dumps(dict(port=self.client_address[1], method=self.command, path=self.path, body=body, headers=dict(self.headers))).encode()
            data = gzip.compress(data)
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Encoding", "gzip")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)
        do_GET = respond
        do_POST = respond

    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    server.daemon_threads = True
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert, key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        code = r'''
require '_core/Cloud.php';
$url=getenv('REUSE_TEST_URL');
$rows=[];
$rows[]=json_decode(CloudDesk\Http::request('GET',$url.'/first',['X-Only'=>'first'],reuse:true)['body'],true,flags:JSON_THROW_ON_ERROR);
$rows[]=json_decode(CloudDesk\Http::request('POST',$url.'/second',['X-Other'=>'second'],'payload',reuse:true)['body'],true,flags:JSON_THROW_ON_ERROR);
$rows[]=json_decode(CloudDesk\Http::request('GET',$url.'/third',reuse:true)['body'],true,flags:JSON_THROW_ON_ERROR);
echo json_encode($rows,JSON_THROW_ON_ERROR);
'''
        result = subprocess.check_output([os.environ.get("PHP_BINARY", "php"), "-d", "curl.cainfo="+str(cert), "-r", code], cwd=ROOT, env={**os.environ, "REUSE_TEST_URL":f"https://127.0.0.1:{server.server_port}"})
        rows = json.loads(result)
        assert len({row["port"] for row in rows}) == 1, "TLS connection was not reused"
        assert [row["method"] for row in rows] == ["GET", "POST", "GET"]
        assert [row["path"] for row in rows] == ["/first", "/second", "/third"]
        assert [row["body"] for row in rows] == ["", "payload", ""]
        assert "X-Only" not in rows[1]["headers"] and "X-Other" not in rows[2]["headers"]
        assert "Content-Type" not in rows[2]["headers"], "POST headers leaked into GET"
        print("PASS: verified HTTPS keep-alive, GET/POST/GET reset, header/body isolation and gzip decoding")
    finally:
        server.shutdown()
        server.server_close()
        thread.join()
