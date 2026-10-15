# BKB Digital Banking derivative of the official Firefly III data-importer.
#
# Base: fireflyiii/data-importer:version-2.3.5, pinned by digest. The base
# image already contains the exact v2.3.5 PHP dependencies, so this layer
# performs no composer update/install.
#
# Upstream runtime facts used here: the image build itself runs on Azure
# with no public Dockerfile, but the official firefly-iii/docker compose
# files and the image's own entrypoint logs confirm working directory
# /var/www/html, runtime user www-data, HTTP on container port 8080, and an
# entrypoint that parses *_FILE secrets and then runs as www-data. This
# derivative keeps the base ENTRYPOINT/CMD untouched so that init runs.

ARG REVISION=unknown

FROM fireflyiii/data-importer:version-2.3.5@sha256:2627644321afdcb52b49b258126bfe98312c2f93a709cc3a07c90327854e6aa2

ARG REVISION=unknown

LABEL org.opencontainers.image.title="data-importer with BKB Digital Banking" \
      org.opencontainers.image.description="Firefly III data-importer v2.3.5 plus the BKB provider and durable upload receipts." \
      org.opencontainers.image.source="https://github.com/Tomblarom/data-importer" \
      org.opencontainers.image.revision="${REVISION}" \
      org.opencontainers.image.base.name="fireflyiii/data-importer:version-2.3.5@sha256:2627644321afdcb52b49b258126bfe98312c2f93a709cc3a07c90327854e6aa2"

USER root

# Overlay only the BKB/receipt trees onto the base equivalents. New classes
# follow the existing App\ PSR-4 autoload ("App\\": "app/") and resolve via
# the base image's standard composer autoloader — no dump needed.
COPY --chown=www-data:www-data app/ /var/www/html/app/
COPY --chown=www-data:www-data config/ /var/www/html/config/
COPY --chown=www-data:www-data routes/ /var/www/html/routes/
COPY --chown=www-data:www-data resources/views/ /var/www/html/resources/views/

RUN set -eu; \
    mkdir -p /var/www/html/storage/import-receipts /var/www/html/storage/import-jobs; \
    chown -R www-data:www-data /var/www/html/storage/import-receipts /var/www/html/storage/import-jobs; \
    # Drop compiled config/route/view caches so the overlay takes effect. \
    # Never touch storage/framework/sessions (runtime user sessions). \
    rm -f /var/www/html/bootstrap/cache/config.php /var/www/html/bootstrap/cache/routes-*.php; \
    rm -f /var/www/html/storage/framework/views/*.php

# Never bake secrets: no .env is copied (see .dockerignore).
USER www-data
