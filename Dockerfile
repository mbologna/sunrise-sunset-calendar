FROM python:3.15.2-slim@sha256:65a65fc7b30b06cb6df4930fd4650697cb23f4c2698ab81fc87e4b031bc36928 AS build

# uv from PyPI, hash-checked (requirements-uv.txt says why). Used in this
# stage only: the runtime stage gets the finished virtualenv.
COPY requirements-uv.txt /tmp/
RUN pip install --no-cache-dir --root-user-action=ignore --require-hashes -r /tmp/requirements-uv.txt
# Install exactly what uv.lock pins, checking its hashes; --locked fails the
# build if uv.lock no longer matches pyproject.toml. Dependencies first, so
# their layer survives a code change; then the app itself, as a regular
# (non-editable) package. Bytecode is compiled here because the runtime image
# sets PYTHONDONTWRITEBYTECODE.
ENV UV_PROJECT_ENVIRONMENT=/opt/venv UV_PYTHON=/usr/local/bin/python3 UV_PYTHON_DOWNLOADS=never \
    UV_COMPILE_BYTECODE=1 UV_LINK_MODE=copy UV_NO_CACHE=1
WORKDIR /tmp/build
COPY pyproject.toml uv.lock README.md LICENSE ./
RUN uv sync --locked --no-dev --no-editable --no-install-project
COPY src/ ./src/
RUN uv sync --locked --no-dev --no-editable

FROM python:3.15.2-slim@sha256:65a65fc7b30b06cb6df4930fd4650697cb23f4c2698ab81fc87e4b031bc36928
COPY --from=build /opt/venv /opt/venv
# PORT is where the server listens: Cloud Run (or any container runtime)
# injects it; 8080 otherwise. AUTH_TOKEN and the other settings come from the
# environment too (README, "Configuration").
ENV PATH=/opt/venv/bin:$PATH PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1 PORT=8080
# The base image's pip (with the packages it vendors) and ensurepip's
# bundled pip wheel are never used at run time; removing them keeps their
# CVEs out of the image.
RUN /usr/local/bin/python3 -m pip uninstall -y --root-user-action=ignore pip \
    && rm -rf /usr/local/lib/python3*/ensurepip
# A fixed UID and GID (useradd -r alone picks a system GID), so runtimes see
# the same numbers on every build. Everything the app runs is root-owned and
# read-only for it; it writes nothing.
RUN groupadd -g 1000 appuser && useradd -r -u 1000 -g 1000 -d /nonexistent -s /usr/sbin/nologin appuser
USER 1000:1000
EXPOSE 8080
CMD ["sunrise-sunset-calendar"]
