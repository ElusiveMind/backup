FROM php:7.2-cli

# Set our our meta data for this container.
LABEL name="ITCON Backup Container"
LABEL description="A backup system based in Docker designed for Drupal backups to AWS/MinIO"
LABEL author="Michael R. Bagnall <mbagnall@itcon-inc.com>"
LABEL vendor="ITCON Services, LLC."
LABEL version="0.25"

# Version string
ENV VERSION_NUMBER v0.25

RUN apt update
RUN apt-get -y install mysql-common
RUN apt-get -y install default-mysql-client
RUN apt-get -y install cron
RUN apt-get -y install gettext procps nano

COPY cron/aws /etc/cron.d/aws
RUN chmod 0644 /etc/cron.d/aws

ADD php /php

ADD bash/run-cron.sh /run-cron.sh
ADD bash/environment.txt /environment.txt
RUN chmod -v +x /run-cron.sh

CMD ["/run-cron.sh"]
