FROM alpine:3.15

# Installs latest Chromium (92) package.
RUN apk add --no-cache \
      curl \
      chromium \
      nss \
      freetype \
      harfbuzz \
      ca-certificates \
      ttf-freefont \
      nodejs \
      yarn \
      php7 \
      php7-curl \
      php7-openssl \
      php7-iconv \
      php7-json \
      php7-mbstring \
      php7-phar \
      php7-dom --repository https://dl-cdn.alpinelinux.org/alpine/v3.15/main

#Installs composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer       

# Tell Puppeteer to skip installing Chrome. We'll be using the installed package.
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser

# Puppeteer v10.0.0 works with Chromium 92.
RUN yarn add puppeteer@10.0.0
RUN yarn add purgecss
RUN yarn global add purgecss

# Add user so we don't need --no-sandbox.
RUN addgroup -S pptruser && adduser -S -g pptruser pptruser \
    && mkdir -p /home/pptruser/Downloads /app \
    && chown -R pptruser:pptruser /home/pptruser \
    && chown -R pptruser:pptruser /app

USER root

COPY crontab /tmp/crontab
COPY page-local.js /home/pptruser/page-local.js
COPY optimise_css.php /home/pptruser/optimise_css.php
COPY composer.json /home/pptruser/composer.json
COPY forcecss.css /home/pptruser/forcecss.css
COPY lockfile.lock /home/pptruser/lockfile.lock
COPY .env /home/pptruser/.env

COPY run-crond.sh /run-crond.sh
RUN chmod -v +x /run-crond.sh

RUN mkdir -p /var/log/cron && touch /var/log/cron/cron.log

RUN dos2unix /run-crond.sh
RUN dos2unix /tmp/crontab
RUN dos2unix /home/pptruser/page-local.js
RUN dos2unix /home/pptruser/optimise_css.php
RUN dos2unix /home/pptruser/composer.json
RUN dos2unix /home/pptruser/forcecss.css
RUN dos2unix /home/pptruser/lockfile.lock
RUN dos2unix /home/pptruser/.env

RUN php /usr/bin/composer install --working-dir=/home/pptruser/