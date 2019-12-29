#!/bin/bash

if [[ -n "${MINIO_ENDPOINT}" ]]; then
  export AWS_ENDPOINT=${MINIO_ENDPOINT}
fi

if [[ -n "${MINIO_KEY}" ]]; then
  export AWS_KEY=${MINIO_KEY}
fi

if [[ -n "${MINIO_SECRET}" ]]; then
  export AWS_SECRET=${MINIO_SECRET}
fi

if [[ -n "${MINIO_BUCKET}" ]]; then
  export AWS_BUCKET=${MINIO_BUCKET}
fi

if [[ -n "${MINIO_FILE_TTL}" ]]; then
  export AWS_FILE_TTL=${MINIO_FILE_TTL}
fi

# Replace our cron interval with what has been suggested
envsubst < /etc/cron.d/aws > /etc/cron.d/aws-cron
envsubst < /environment.txt > /etc/environment

# Set up the cron in our set of crontabs
crontab /etc/cron.d/aws-cron
rm /etc/cron.d/aws

# Restart crond in the foreground
exec cron -f