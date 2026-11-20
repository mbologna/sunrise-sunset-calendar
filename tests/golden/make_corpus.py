"""Record the golden corpus from this app.

    uv run python -m tests.golden.make_corpus

Answers every case of cases.py and writes the responses to corpus/: one
xz-compressed tar of response bodies per group. Run it when the output
changes on purpose (new cases, an astronomy or text change, a tz release that
changes a zone's rules), and review the changed lines before committing.
"""

from __future__ import annotations

import io
import json
import tarfile
from collections import defaultdict

from .cases import CASES
from .test_golden import CORPUS, request


def main() -> None:
    groups = defaultdict(list)
    for case in CASES:
        groups[case.group].append(case)
    CORPUS.mkdir(exist_ok=True)
    for group, cases in groups.items():
        with tarfile.open(CORPUS / f"{group}.tar.xz", "w:xz", preset=9) as tar:
            for case in cases:
                _, _, data = request(case)
                info = tarfile.TarInfo(case.id)
                info.size = len(data)
                info.mtime = 0
                tar.addfile(info, io.BytesIO(data))
    sizes = {p.name: p.stat().st_size for p in sorted(CORPUS.iterdir())}
    print(json.dumps(sizes, indent=1))


if __name__ == "__main__":
    main()
