"""Re-record golden cases whose correct output changed with a tz release.

Usage: uv run python -m tests.golden.record_overrides <case id>...

Writes the app's current output for each case into corpus/tz-overrides.tar.xz,
keeping the entries already there. Only for zones a tz release changed (check
each changed line against the release's NEWS entry first); everything else must
keep matching the PHP recording.

Recorded so far:
- tzdata 2026.6 (tz 2026f): Cuba stays on -04 instead of falling back on
  2026-11-01: full-havana, moment-2026-10-25-havana, edge-2026-11-01-03-havana,
  edge-2026-11-01-15-havana. The America/Havana rows of
  tests/php_reference/primitives-*.json dated 2026-10-31 to 2027-03-15 were
  re-derived the same way (every change is the hour Cuba no longer falls back).
"""

from __future__ import annotations

import io
import sys
import tarfile

from .cases import CASES
from .test_golden import CORPUS, overrides, request


def main(ids: list[str]) -> None:
    by_id = {c.id: c for c in CASES}
    entries = dict(overrides())
    for case_id in ids:
        _, _, body = request(by_id[case_id])
        entries[case_id] = body
    with tarfile.open(CORPUS / "tz-overrides.tar.xz", "w:xz", preset=9) as tar:
        for name in sorted(entries):
            info = tarfile.TarInfo(name)
            info.size = len(entries[name])
            info.mtime = 0
            tar.addfile(info, io.BytesIO(entries[name]))
    print(f"{len(entries)} override(s) in {CORPUS / 'tz-overrides.tar.xz'}")


if __name__ == "__main__":
    main(sys.argv[1:])
