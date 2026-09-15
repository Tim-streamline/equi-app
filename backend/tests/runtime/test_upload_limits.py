"""Run inside Sail: python3 tests/runtime/test_upload_limits.py.

Exercise multipart parsing in the same FrankenPHP binary as Octane. Laravel's
UploadedFile::fake() bypasses PHP's upload_max_filesize and post_max_size checks.
"""

import http.client
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest


class UploadLimitsTest(unittest.TestCase):
    def test_image_above_two_megabytes_reaches_the_application(self):
        self.check_upload(2471346)

    def test_documented_thumbnail_limit_reaches_the_application(self):
        self.check_upload(10 * 1024 * 1024)

    def check_upload(self, size):
        binary = Path(__file__).resolve().parents[2] / "frankenphp"
        with tempfile.TemporaryDirectory(prefix="equi-upload-test-") as directory:
            Path(directory, "index.php").write_text(
                '<?php header("Content-Type: application/json"); '
                'echo json_encode(["error" => $_FILES["file"]["error"] ?? null, '
                '"size" => $_FILES["file"]["size"] ?? null, '
                '"upload_limit" => ini_parse_quantity(ini_get("upload_max_filesize")), '
                '"post_limit" => ini_parse_quantity(ini_get("post_max_size"))]);'
            )
            with socket.socket() as reservation:
                reservation.bind(("127.0.0.1", 0))
                port = reservation.getsockname()[1]
            caddyfile = Path(directory, 'Caddyfile')
            caddyfile.write_text(
                '{\n admin off\n auto_https off\n}\n'
                f'http://127.0.0.1:{port} {{\n root * {directory}\n php_server\n}}\n'
            )
            with tempfile.TemporaryFile() as output:
                server = subprocess.Popen(
                    [str(binary), "run", "--config", str(caddyfile), "--adapter", "caddyfile"],
                    stdout=output, stderr=output,
                    env={**os.environ, "XDG_CONFIG_HOME": f"{directory}/config", "XDG_DATA_HOME": f"{directory}/data"},
                )
                try:
                    for _ in range(100):
                        if server.poll() is not None:
                            output.seek(0)
                            self.fail(output.read().decode())
                        try:
                            with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                                break
                        except OSError:
                            time.sleep(0.05)
                    else:
                        self.fail("FrankenPHP did not start")

                    boundary = "equi-upload-regression"
                    body = (
                        f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="thumbnail.png"\r\n'
                        'Content-Type: image/png\r\n\r\n'
                    ).encode() + b"x" * size + f"\r\n--{boundary}--\r\n".encode()
                    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
                    try:
                        connection.request("POST", "/", body, {
                            "Content-Type": f"multipart/form-data; boundary={boundary}",
                        })
                        response = connection.getresponse()
                        self.assertEqual(response.status, 200)
                        payload = response.read()
                        # PHP may prepend a startup warning when post_max_size
                        # rejects the body before the receiver script runs.
                        start = payload.find(b'{"error":')
                        self.assertGreaterEqual(start, 0, payload.decode())
                        result = json.loads(payload[start:])
                    finally:
                        connection.close()

                    self.assertEqual(result["error"], 0, result)
                    self.assertEqual(result["size"], size)
                    # The direct media endpoint also allows videos up to 150 MB.
                    self.assertGreaterEqual(result["upload_limit"], 150 * 1024 * 1024)
                    self.assertGreater(result["post_limit"], result["upload_limit"])
                finally:
                    server.terminate()
                    server.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
