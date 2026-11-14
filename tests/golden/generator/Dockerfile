# The last PHP release (b47c648a, "one timeline per event"), plus libfaketime
# so each recorded request can happen at a chosen moment. Used only to record
# the golden corpus: see ../make_corpus.py.
FROM ghcr.io/mbologna/sunrise-sunset-calendar:sha-b47c648@sha256:6c3c3619480c4e96dbb1da0ef64845f2a71e10fee2c2987978c3c33930006c8b
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends faketime \
    && rm -rf /var/lib/apt/lists/* \
    && ln -s "$(dirname "$(find /usr/lib -name libfaketime.so.1 | head -n 1)")" /usr/lib/faketime-dir
COPY request.php record.php /recorder/
RUN mkdir -p /work/out && chown -R 33:33 /work
USER 33:33
ENTRYPOINT ["php", "/recorder/record.php"]
