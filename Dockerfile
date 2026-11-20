# Build stage: a pinned uv installs exactly what uv.lock pins into
# /opt/venv. Same base image and digest as the runtime stage, so compiled
# wheels match the Python they run on.
FROM python:3.15.2-slim@sha256:65a65fc7b30b06cb6df4930fd4650697cb23f4c2698ab81fc87e4b031bc36928 AS build

# uv from PyPI, hash-checked (requirements-uv.txt says why).
COPY requirements-uv.txt /tmp/
RUN pip install --no-cache-dir --root-user-action=ignore --require-hashes -r /tmp/requirements-uv.txt
# Install exactly what uv.lock pins, checking its hashes, rather than
# re-resolving pyproject.toml at build time; --locked fails the build when
# uv.lock is out of date. Dependencies first, so their layer survives a change
# to the code or the docs; then the app itself, as a regular (non-editable)
# package. Bytecode is compiled here because the runtime image sets
# PYTHONDONTWRITEBYTECODE and runs read-only.
ENV UV_PROJECT_ENVIRONMENT=/opt/venv UV_PYTHON=/usr/local/bin/python3 UV_PYTHON_DOWNLOADS=never \
    UV_COMPILE_BYTECODE=1 UV_LINK_MODE=copy UV_NO_CACHE=1
WORKDIR /tmp/build
COPY pyproject.toml uv.lock ./
RUN uv sync --locked --no-dev --no-editable --no-install-project
COPY README.md LICENSE ./
COPY src/ src/
RUN uv sync --locked --no-dev --no-editable

FROM python:3.15.2-slim@sha256:65a65fc7b30b06cb6df4930fd4650697cb23f4c2698ab81fc87e4b031bc36928
# The CI pipeline adds org.opencontainers.image.source and .revision; the
# release tags are a changelog and build nothing, so no .version
# (pyproject.toml's is a placeholder).
LABEL org.opencontainers.image.title="sunrise-sunset-calendar" \
      org.opencontainers.image.description="iCalendar feed of sunrise, sunset, twilight and moon phases for any location, computed from the JPL DE442 ephemeris" \
      org.opencontainers.image.licenses="MIT"
COPY --from=build /opt/venv /opt/venv
# Run-time writes go to /tmp (HOME too), so the root filesystem can be
# read-only.
ENV PATH=/opt/venv/bin:$PATH PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1 HOME=/tmp
# PORT: where the server listens. Cloud Run and most container runtimes set
# it; 8080 otherwise.
ENV PORT=8080
# One malloc arena: each waitress thread (THREADS) otherwise keeps its own,
# holding the memory freed after skyfield's searches: about 40 MB more with
# the default two threads, against a 128 MiB instance. The GIL serialises the
# work anyway.
ENV MALLOC_ARENA_MAX=1
# The base image's pip (with the packages it vendors) and ensurepip's
# bundled pip wheel are never used at run time; removing them keeps their
# CVEs out of the image.
RUN /usr/local/bin/python3 -m pip uninstall -y --root-user-action=ignore pip \
    && rm -rf /usr/local/lib/python3*/ensurepip
# A named account for the UID, for software that looks it up in
# /etc/passwd, with a fixed GID so volumes see the same numbers on every
# build.
RUN groupadd -g 1000 appuser && useradd -r -u 1000 -g 1000 -d /nonexistent -s /usr/sbin/nologin appuser
# The distribution's security fixes published since the base image was
# built: Docker's official images are rebuilt only every week or few.
# Each push to main that rebuilds the image takes the fixes released since.
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get upgrade -y \
    && rm -rf /var/lib/apt/lists/*
USER 1000:1000
EXPOSE 8080
ENTRYPOINT ["sunrise-sunset-calendar"]
