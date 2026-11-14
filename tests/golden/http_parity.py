"""Compare the PHP release and a Python image over real HTTP: status, headers and bodies.

    docker build -t sunrise-sunset-calendar:local .
    uv run python tests/golden/http_parity.py sunrise-sunset-calendar:local

The golden corpus pins the bodies; this checks what it can't: status codes,
headers and the server around the app (Apache and mod_php against waitress).
Both containers run side by side, so "today" is the same for both. Needs
Docker. Prints each difference and exits non-zero if there is one that is
not expected.
"""

from __future__ import annotations

import re
import subprocess
import sys
import time
import urllib.error
import urllib.request

PHP_IMAGE = (
    "ghcr.io/mbologna/sunrise-sunset-calendar:sha-b47c648"
    "@sha256:6c3c3619480c4e96dbb1da0ef64845f2a71e10fee2c2987978c3c33930006c8b"
)
TOKEN = "parity-token"
FORM = "application/x-www-form-urlencoded"
HEADERS = ("Content-Type", "Cache-Control", "X-Content-Type-Options", "X-Frame-Options", "Referrer-Policy")

REQUESTS = [
    ("GET", "/", None),
    ("GET", "/sunrise-sunset-calendar.php", None),
    ("HEAD", "/", None),
    ("GET", "/?health", None),
    ("GET", f"/sunrise-sunset-calendar.php?feed=1&token={TOKEN}&lat=45.46&lon=9.19&zone=Europe/Milan&daynight=1", None),
    (
        "GET",
        f"/?feed=1&token={TOKEN}&lat=-33.87&lon=151.21&zone=Australia/Sydney&civil=1&astro=1&location=Sydney",
        None,
    ),
    ("GET", "/?feed=1&token=wrong", None),
    ("GET", "/?feed=1", None),
    ("POST", "/", f"generate_url=1&password={TOKEN}&lat=1&lon=2&zone=UTC&civil=on"),
    ("POST", "/sunrise-sunset-calendar.php", "generate_url=1&password=nope"),
    ("POST", f"/?feed=1&token={TOKEN}&daynight=1", "ignored=1"),
    ("GET", "/assets/styles.css", None),
    ("GET", "/assets/script.js", None),
    ("GET", "/sunrise-sunset-calendar.php/extra/path", None),
]

# Where the two servers legitimately differ
EXPECTED = {
    # The health check names its runtime ("php" became "python")
    "/?health": {"body"},
}


def run(image: str, port: int, env: dict[str, str]) -> str:
    args = ["docker", "run", "-d", "--rm", "-p", f"127.0.0.1:{port}:8080"]
    for key, value in env.items():
        args += ["-e", f"{key}={value}"]
    return subprocess.run([*args, image], check=True, capture_output=True, text=True).stdout.strip()


def fetch(port: int, method: str, path: str, body: str | None):
    data = body.encode() if body is not None else None
    request = urllib.request.Request(f"http://127.0.0.1:{port}{path}", data=data, method=method)
    request.add_header("Host", "calendar.example")
    if data is not None:
        request.add_header("Content-Type", FORM)
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return response.status, response.headers, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read()


def wait(port: int) -> None:
    for _ in range(60):
        try:
            fetch(port, "GET", "/?health", None)
            return
        except OSError:
            time.sleep(0.5)
    raise SystemExit(f"nothing listening on {port}")


def normalise(body: bytes) -> bytes:
    return re.sub(rb"DTSTAMP:\d{8}T\d{6}Z", b"DTSTAMP", body)


def main() -> int:
    python_image = sys.argv[1]
    env = {"AUTH_TOKEN": TOKEN, "CALENDAR_WINDOW_DAYS": "10", "CALENDAR_PAST_DAYS": "2"}
    containers = [run(PHP_IMAGE, 18080, env), run(python_image, 18081, env)]
    unexpected = 0
    try:
        wait(18080)
        wait(18081)
        for method, path, body in REQUESTS:
            php = fetch(18080, method, path, body)
            py = fetch(18081, method, path, body)
            differences = set()
            if php[0] != py[0]:
                differences.add("status")
            for header in HEADERS:
                if php[1].get(header) != py[1].get(header):
                    differences.add(header)
            if normalise(php[2]) != normalise(py[2]):
                differences.add("body")
            allowed = EXPECTED.get(path, set())
            for difference in sorted(differences):
                tag = "expected" if difference in allowed else "UNEXPECTED"
                unexpected += tag == "UNEXPECTED"
                detail = {"status": (php[0], py[0])}.get(difference) or (
                    (php[1].get(difference), py[1].get(difference)) if difference in HEADERS else ""
                )
                print(f"{tag:10} {method} {path}: {difference} {detail}")
            print(f"{'ok' if not differences else 'differs':10} {method} {path} -> {py[0]}")

        # Without a token, both refuse every request
        for container in containers:
            subprocess.run(["docker", "rm", "-f", container], check=True, capture_output=True)
        containers = [run(PHP_IMAGE, 18080, {}), run(python_image, 18081, {})]
        time.sleep(3)
        php, py = fetch(18080, "GET", "/?health", None), fetch(18081, "GET", "/?health", None)
        same = php[0] == py[0] and php[2] == py[2]
        unexpected += not same
        print(f"{'ok' if same else 'UNEXPECTED':10} missing AUTH_TOKEN -> {php[0]} {php[2]!r} / {py[0]} {py[2]!r}")
    finally:
        for container in containers:
            subprocess.run(["docker", "rm", "-f", container], capture_output=True)
    return 1 if unexpected else 0


if __name__ == "__main__":
    sys.exit(main())
