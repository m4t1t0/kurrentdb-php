FROM php:8.4-cli

WORKDIR /repo

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/* \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

CMD ["sleep", "infinity"]
