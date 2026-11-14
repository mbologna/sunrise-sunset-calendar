"""Record the golden corpus from the last PHP release.

    uv run python tests/golden/make_corpus.py

Builds the recorder image (generator/: the PHP release image plus
libfaketime), runs every case of cases.py in it, and writes the responses to
corpus/: one xz-compressed tar of response bodies per group. Needs Docker. The corpus is frozen: the PHP version no
longer changes, so this only runs again to add cases.
"""

from __future__ import annotations

import io
import json
import subprocess
import sys
import tarfile
import tempfile
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from cases import CASES, TOKEN

HERE = Path(__file__).parent
IMAGE = "sunrise-sunset-calendar-golden-recorder"


def main() -> None:
    subprocess.run(["docker", "build", "-q", "-t", IMAGE, str(HERE / "generator")], check=True)
    with tempfile.TemporaryDirectory() as tmp:
        work = Path(tmp)
        lines = [
            json.dumps(
                {
                    "id": c.id,
                    "query": c.query,
                    "body": c.body,
                    "host": c.host,
                    "https": c.https,
                    "now": c.now,
                    "env": c.env,
                    "token": TOKEN,
                }
            )
            for c in CASES
        ]
        (work / "cases.jsonl").write_text("\n".join(lines) + "\n")
        container = subprocess.run(
            ["docker", "create", IMAGE], check=True, capture_output=True, text=True
        ).stdout.strip()
        try:
            subprocess.run(["docker", "cp", str(work / "cases.jsonl"), f"{container}:/work/cases.jsonl"], check=True)
            subprocess.run(["docker", "start", "-a", container], check=True)
            subprocess.run(["docker", "cp", f"{container}:/work/out", str(work)], check=True)
        finally:
            subprocess.run(["docker", "rm", "-f", container], check=True, capture_output=True)
        out = work / "out"

        corpus = HERE / "corpus"
        corpus.mkdir(exist_ok=True)
        groups = defaultdict(list)
        for case in CASES:
            groups[case.group].append(case)
        for group, cases in groups.items():
            with tarfile.open(corpus / f"{group}.tar.xz", "w:xz", preset=9) as tar:
                for case in cases:
                    data = (out / case.id).read_bytes()
                    info = tarfile.TarInfo(case.id)
                    info.size = len(data)
                    info.mtime = 0
                    tar.addfile(info, io.BytesIO(data))
        sizes = {p.name: p.stat().st_size for p in sorted(corpus.iterdir())}
        print(json.dumps(sizes, indent=1))


if __name__ == "__main__":
    main()
