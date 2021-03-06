FROM php:7.4-cli

# Set our our meta data for this container.
LABEL name="ITCON Backup Container"
LABEL description="A backup system based in Docker designed for Drupal backups to AWS/MinIO"
LABEL author="Michael R. Bagnall <mbagnall@itcon-inc.com>"
LABEL vendor="ITCON Services, LLC."
LABEL version="0.37"

# Version string
ENV VERSION_NUMBER 0.37
ENV BUILD_DATE "December 6, 2020"

RUN apt-get update
RUN apt-get -y install mysql-common postgresql-client-common
RUN apt-get -y install default-mysql-client
RUN apt-get -y install cron
RUN apt-get -y install gettext procps nano vim

COPY cron/aws /etc/cron.d/aws
RUN chmod 0644 /etc/cron.d/aws

ADD php /php

ADD bash/run-cron.sh /run-cron.sh
ADD bash/environment.txt /environment.txt
ADD bash/startup.sh /root/.bashrc

RUN chmod -v +x /run-cron.sh

WORKDIR /php

CMD ["/run-cron.sh"]
