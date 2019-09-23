FROM php:7.2-cli

# Set our our meta data for this container.
LABEL name="ITCON Backup Container"
LABEL description="A backup system based in Docker designed for Drupal backups to MinIO"
LABEL author="Michael R. Bagnall <mbagnall@itcon-inc.com>"
LABEL vendor="ITCON Services"
LABEL version="0.09"

# Version string
ENV VERSION_NUMBER v0.09

RUN apt update
RUN apt-get -y install mysql-common
RUN apt-get -y install default-mysql-client
RUN apt-get -y install cron
RUN apt-get -y install gettext
RUN service cron stop

ADD cron/minio /etc/cron.d/minio
RUN chmod 0644 /etc/cron.d/minio

ADD bash /bash
ADD php /php

ADD bash/run-httpd.sh /run-httpd.sh
RUN chmod -v +x /run-httpd.sh

CMD ["/run-httpd.sh"]