FROM php:7.2-cli

# Set our our meta data for this container.
LABEL name="ITCON Backup Container"
LABEL description="A backup system based in Docker designed for Drupal backups to MinIO"
LABEL author="Michael R. Bagnall <mbagnall@itcon-inc.com>"
LABEL vendor="ITCON Services"
LABEL version="0.12"

# Version string
ENV VERSION_NUMBER v0.12

RUN apt update
RUN apt-get -y install mysql-common
RUN apt-get -y install default-mysql-client
RUN apt-get -y install cron
#RUN apt-get -y install gettext procps nano

COPY cron/minio /etc/cron.d/minio
RUN chmod 0644 /etc/cron.d/minio

ADD php /php

ADD bash/run-cron.sh /run-cron.sh
RUN chmod -v +x /run-cron.sh

CMD ["run-cron.sh"]

