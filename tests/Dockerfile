FROM php:8.4-cli

WORKDIR /repo

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/* \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

CMD ["sleep", "infinity"]
# NOTE (supply-chain, review-noted): this image installs composer via an
# unpinned remote installer and the harness runs a floating kurrentdb image.
# Acceptable for a local-only side-project harness today; when CI is added,
# pin both (composer installer checksum + image digest) before automation.
