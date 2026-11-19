# AGENTS.md — sunrise-sunset-calendar

iCalendar feed of sunrise, sunset, twilight and moon phases for any location, computed with the NREL Solar Position Algorithm. User-facing docs: `README.md`.

## Layout

- `src/sunrise_sunset_calendar/`: the package. `app.main()` is the console
  script `sunrise-sunset-calendar`, the image's entry point: a web service
  that serves the page and the feed with waitress on `$PORT` (8080).
- `tests/`: pytest. `tests/golden/` replays a corpus of responses recorded
  from the PHP release and compares the bytes (README, "Development").
- `Dockerfile`: two stages, `uv sync --locked` into `/opt/venv`
  (dependencies first, then the app), then a runtime without pip.
- CI builds the image, pushes it to ghcr.io on every main build, and
  deploys it to Cloud Run by digest; the `auto-release` job tags the weekly
  changelog release.

## Conventions

- Conventional Commits, header at most 72 characters. The commit subjects
  that reach main are the changelog: `feat`/`fix`/`perf` release, `refactor`,
  `test`, `docs`, `ci`, `chore` don't (a dependency update to runtime files
  goes into the weekly maintenance release). CI lints every commit with
  [commitlint-config-mbologna](https://github.com/mbologna/commitlint-config)
  and rejects `fixup!` commits; pull requests merge with a merge commit.
- Every push to main that changes the image publishes it as `latest` and
  `sha-<commit>` and deploys it, by digest, to the Cloud Run service. Images
  carry no version.
- Releases are a changelog: auto-release runs every Monday at 06:00 UTC and
  tags `vX.Y.Z` with a GitHub Release of the commit subjects when a
  release-worthy commit landed, or cuts a maintenance release for
  dependency updates. The tags build nothing. `pyproject.toml`'s version
  stays `0.0.0`.
- Coverage may not drop below `fail_under` in `pyproject.toml`; raise it
  when tests are added.
- Dependencies: `uv add <pkg>`; uv.lock is committed and the image installs
  it with `--locked`. `exclude-newer` keeps releases younger than 7 days out.
- The image: non-root (uid 1000, `appuser`), no pip, and the app writes
  nothing to disk.

## Template

The skeleton comes from https://github.com/mbologna/python-app-template
(`.copier-answers.yml`). Renovate's copier manager opens a pull request
for each template release; resolve conflicts in favour of this project's
code. By hand: `uvx copier update`, then `uv lock` if dependencies changed.
