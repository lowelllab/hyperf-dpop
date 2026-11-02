FROM php:8.5.12-cli-bookworm@sha256:104890ada56869090c9cd0ec709fe205834d3e383b46d9995dc16cd6388a9302
RUN apt-get update && apt-get install -y --no-install-recommends \
    $PHPIZE_DEPS libssl-dev libonig-dev libxml2-dev unzip ca-certificates curl \
    && docker-php-ext-install -j2 pcntl sockets pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
RUN curl -fsSL https://pecl.php.net/get/swoole-6.2.4.tgz -o /tmp/swoole.tgz \
    && echo 'cb41348708cb3a360672cd4ffe84b9a6b4efee61773be7d76a11c39949b95334  /tmp/swoole.tgz' | sha256sum -c - \
    && mkdir /tmp/swoole && tar -xzf /tmp/swoole.tgz -C /tmp/swoole --strip-components=1 \
    && cd /tmp/swoole && phpize && ./configure --enable-sockets --with-openssl-dir=/usr \
    && make -j2 && make install && docker-php-ext-enable swoole \
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
