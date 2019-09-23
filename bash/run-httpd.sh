#!/bin/bash

# Replace our cron interval with what has been suggested
envsubst < /etc/cron.d/minio > /etc/cron.d/minio-cron

# Set up the cron in our set of crontabs
crontab /etc/cron.d/minio-cron
rm /etc/cron.d/minio

# Stop crond
pkill crond

# Restart crond in the foreground
exec cron -f