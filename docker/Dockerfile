ARG PHP_IMAGE=php:8.5.12-cli-bookworm@sha256:104890ada56869090c9cd0ec709fe205834d3e383b46d9995dc16cd6388a9302
FROM ${PHP_IMAGE}
ARG BUILD_JOBS=4
RUN apt-get update && apt-get install -y --no-install-recommends \
    $PHPIZE_DEPS libssl-dev libonig-dev libxml2-dev unzip ca-certificates curl git \
    && docker-php-ext-install -j"$BUILD_JOBS" pcntl sockets pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
RUN curl -fsSL --retry 3 https://pecl.php.net/get/swoole-6.2.5.tgz -o /tmp/swoole.tgz \
    && echo 'edafabbfe5eb125a2be4c83e437667a83b7d7aabc801ba410ff92724ba83e88c  /tmp/swoole.tgz' | sha256sum -c - \
    && mkdir /tmp/swoole && tar -xzf /tmp/swoole.tgz -C /tmp/swoole --strip-components=1 \
    && cd /tmp/swoole && phpize && ./configure --enable-sockets --with-openssl-dir=/usr \
    && make -j"$BUILD_JOBS" && make install && docker-php-ext-enable swoole \
    && rm -rf /tmp/swoole /tmp/swoole.tgz /var/lib/apt/lists/*
RUN curl -fsSL https://pecl.php.net/get/redis-6.3.0.tgz -o /tmp/redis.tgz \
    && echo '0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5  /tmp/redis.tgz' | sha256sum -c - \
    && pecl install /tmp/redis.tgz && docker-php-ext-enable redis && rm /tmp/redis.tgz
RUN curl -fsSL https://getcomposer.org/download/2.11.1/composer.phar -o /usr/local/bin/composer \
    && curl -fsSL https://getcomposer.org/download/2.11.1/composer.phar.sha256sum -o /tmp/composer.sha \
    && test "$(sha256sum /usr/local/bin/composer | cut -d ' ' -f 1)" = "$(cut -d ' ' -f 1 /tmp/composer.sha)" \
    && chmod +x /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /workspace
ARG PHPUNIT_VERSION=13.4.5
ARG PHPUNIT_SHA256=b0cec6bc4a0d62b5cde873ce0a5d0e93a4cb833c87e638269d234e5ecee0c78f
RUN curl -fsSL --retry 3 "https://phar.phpunit.de/phpunit-$PHPUNIT_VERSION.phar" -o /usr/local/bin/phpunit \
    && printf '%s  %s\n' "$PHPUNIT_SHA256" /usr/local/bin/phpunit | sha256sum -c - \
    && chmod +x /usr/local/bin/phpunit \
    && phpunit --version
