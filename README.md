# Docker Project Backup Container

The DPBC (Docker Project Backup Container) is a stand-alone container designed to conduct and offload backed up events. This script is initially centered around Drupal web site (for both Drupal 7 and 8) where a files directory and a database are backed up on a standard interval. 

The default interval is once per day.

To configure the container, environment variables must be set up. Because we are offloading our items into a MinIO instance, we need to configure our MinIO criteria, our MySQL connection criteria and the location of our Drupal files. These can all be done with the environment variables listed below.

Keep in mind that if you are going to use this information inside of a docker-compose.yml file - be ABSOLUTELY SURE that this file is not committed to your git repo or you are asking to have a bad day.... possibly more than one.

## Mounts  

The first thing you will want to do is mount the relevant directories into the proper places in your container. The primary mount should be the directory of your Drupal files. This is so that the backup container can see them.

For example, if your Drupal files are located (on the host system) at `/mnt/public-contents` then you should set up a mount as follows in the volumes section of your docker-compose.yml file.

```yaml
    - /app/nas/website/public-contents:/app/files
    - /app/nas/website/backups:/app/backups
```

You would then need to configure your `FILES_FOLDER_PARENT` and `FILES_FOLDER_NAME` environment variables accordingly.

## Environment Variables  

Here are the configuration environmental variables for all of the settings that the backup server will need to offload to your MinIO instrance.

**DEBUG**  
Enable debug logging. Logging is logged to `messages.txt` in `/app/backups`  

**DELETE_FIRST**  
Delete the old files on the remote server first before uploading new files. For hosts with limited space.  

**INTERVAL**  
This is the interval at which to run the cron scripts. Must be in cron format (ex. 0 0 * * *)  

**MINIO_ENDPOINT**  
This is the full canonical URL (http or https) to the MinIO server that the backups will be sent to.  

**MINIO_KEY**  
This is the secret key used to authenticate with MinIO. KEEP THIS OUT OF YOUR GIT REPOSITORY. You have been warned. Don't make the same mistakes I have :)  

**MINIO_SECRET**  
This is the secret phrase used to authenticate with MinIO. Again, KEEP THIS OUT OF YOUR GIT REPOSITORY. You have been warned. Don't make the same mistakes I have :)  

**MINIO_FILE_TTL**  
This is the number of days to keep of backups. Each backup will be kept for this interval. Once reached, the file will be removed on the next synchronization.  

**MYSQL_HOST**  
The hostname of the MySQL container.  

**MYSQL_USER**  
The username to access the MySQL container.  

**MYSQL_PASS**  
The password for the user by which the MySQL container will be accessed.  

**MYSQL_DATABASE**  
The database on the MySQL container to be backed up.  

**FILES_FOLDER_PARENT**  
Folder that contains the files folder. For example, if this was `sites/default/files` the parent would be `sites/default`.  

**FILES_FOLDER_NAME**  
The folder name that was omitted in the parent configuration. In that same example `files`.  

**FILES_PREFIX**  
The prefix to place as part of the file name. Can be used to identify backups given a project name or some other unique identifier. All files are date-tagged in the file name.  

**DATA_PREFIX**  
The same as a files prefix, but applies to the database export. It is worthy of noting that the databases will already be prefixed with the `MYSQL_DATABASE` setting as a matter of course.  

**SMTP_AUTH**  
Boolean. Sets whether the SMTP server requires authentication or not.  

**SITE_IDENTIFIER**  
A string describing the site being backed up.  

**SKIP_DATABASE**  
A boolean that tells the backup process to skip backing up the database. 

**SKIP_FILES**  
A boolean that tells the backup process to skip the files directory.  

**SMTP_DEBUG**  
Numeric value for the level of debugging in SMTP. Refer to PHPMailer for more information on this setting. Defaults to zero.  

**SMTP_HOSTNAME**  
The host name for your mail server. Required.  

**SMTP_USERNAME**  
The username for your SMTP mail server.  

**SMTP_PASSWORD**  
Fairly self-explanatory. The password for your SMTP_USERNAME.  

**SMTP_FROM**  
The from address for the email generated by the script.  

**SMTP_TO**  
The address for whom you should send the report to.  

**SMTP_PORT**  
The port to use. Use to set to 25 where needed or 465. Defaults to tls and 587.  

## TODO  

1. The ability to handle RSYNC of data as well as transfer to AWS/MinIO
2. Re-labeling variables to AWS instead of MinIO
