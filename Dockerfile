FROM centos:7

# Set our our meta data for this container.
LABEL name="ITCON Backup Container"
LABEL description="A backup system based in Docker designed for Drupal backups to MinIO"
LABEL author="Michael R. Bagnall <mbagnall@itcon-inc.com>"
LABEL vendor="ITCON Services"
LABEL version="0.08"

# Set up our standard binary paths.
ENV PATH /usr/local/src/vendor/bin/:/usr/local/rvm/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# Set TERM env to avoid mysql client error message "TERM environment variable not set" when running from inside the container
ENV TERM xterm

# Fix command line compile issue with bundler.
ENV LC_ALL en_US.utf8

# Version string
ENV VERSION v0.08

# Install and enable repositories
RUN yum -y update && \
  yum -y install epel-release && \
  yum -y install http://rpms.remirepo.net/enterprise/remi-release-7.rpm && \
  rpm -Uvh https://centos7.iuscommunity.org/ius-release.rpm && \
  yum -y update && \
  yum -y install yum-utils

RUN yum -y groupinstall "Development Tools" && \
  yum -y install \
  which \
  cronie.x86_64 \
  mod_ssl.x86_64 \
  gettext \
  mysql

# Install PHP modules
RUN yum-config-manager --enable remi-php72 && \
  yum -y install \
    php \
    php-bcmath \
    php-curl \
    php-imap \
    php-mbstring \
    php-pear \
    php-opcache \
    php-xml && \
  yum -y install php72-php-pecl-mcrypt.x86_64

ADD bash /bash
ADD php /php

# Move our cron file templates into place.
ADD cron/minio /etc/cron.d/minio
RUN chmod 0644 /etc/cron.d/minio

# Our startup script used to install Drupal (if configured) and start Apache.
ADD bash/run-httpd.sh /run-httpd.sh
RUN chmod -v +x /run-httpd.sh

CMD ["/run-httpd.sh"]
