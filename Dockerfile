FROM php:5.6-cli
MAINTAINER Phillip Whelan
ADD rdns.phar /bin/rdns
EXPOSE 53/udp
ENTRYPOINT ["/bin/rdns"]
