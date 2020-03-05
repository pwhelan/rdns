FROM php:5.6-cli AS build
MAINTAINER Phillip Whelan

RUN \
	php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
	php -r "if (hash_file('sha384', 'composer-setup.php') === 'e0012edf3e80b6978849f5eff0d4b4e4c79ff1609dd1e613307e16318854d24ae64f26d17af3ef0bf7cfb710ca74755a') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;" && \
	php composer-setup.php --install-dir=/bin --filename=composer && \
	php -r "unlink('composer-setup.php');"

RUN apt-get update && apt-get install -y git unzip
RUN composer config --global --auth github-oauth.github.com \
	f2051a027b5d2a706dbe83eabfc01bd1fca5b006
#RUN composer global require humbug/box

COPY php.ini /usr/local/etc/php/php.ini

RUN mkdir -p /src
COPY main.php /src
COPY composer.lock /src
COPY composer.json /src
RUN cd src && \
	composer install 
# && \
# /root/.composer/vendor/bin/box build

FROM php:5.6-cli

COPY php.ini /usr/local/etc/php
COPY init /init
COPY --from=build /src/main.php /srv/
COPY --from=build /src/vendor /srv/vendor/


EXPOSE 53/udp
ENTRYPOINT ["/init"]
