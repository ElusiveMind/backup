FROM php:7.2-cli

# Set our our meta data for this container.
LABEL name="ITCON Backup Container"
LABEL description="A backup system based in Docker designed for Drupal backups to MinIO"
LABEL author="Michael R. Bagnall <mbagnall@itcon-inc.com>"
LABEL vendor="ITCON Services"
LABEL version="0.09"

# Version string
ENV VERSION_NUMBER v0.09

ADD bash /bash
ADD php /php

CMD ["php". "/php/send-to-minio.php"]
