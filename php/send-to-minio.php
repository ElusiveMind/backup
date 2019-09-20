<?php

require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;
use Aws\S3\ListObjects;
use Aws\S3\DeleteObject;

$s3 = new S3Client([
  'version' => 'latest',
  'region'  => 'us-east-1',
  'endpoint' => $_SERVER['MINIO_ENDPOINT'],
  'use_path_style_endpoint' => true,
  'credentials' => [
    'key'    => $_SERVER['MINIO_KEY'],
    'secret' => $_SERVER['MINIO_SECRET'],
  ],
]);

$user = $_SERVER['MYSQL_USER'];
$pass = $_SERVER['MYSQL_PASS'];
$host = $_SERVER['MYSQL_HOST'];
$database = $_SERVER['MYSQL_DATABASE'];
$data_prefix = $_SERVER['DATA_PREFIX'];
$files_prefix = $_SERVER['FILES_PREFIX'];
$files_folder_parent = $_SERVER['FILES_FOLDER_PARENT'];
$files_folder_name = $_SERVER['FILES_FOLDER_NAME'];

// The first thing we have to do is get the mysqldump.
$today = date('Y-m-d');

if (!is_dir('/app/backups')) {
  mkdir('/app/backups');
}

$mysql = `mysqldump -u$user -p$pass -h$host $database > /app/backups/$database-$data_prefix.$today.sql`;
$gzip = `gzip /app/backups/$database-$data_prefix.$today.sql`;

// Then get a copy of the files directory
$files = `cd $files_folder_parent; tar -czf /app/backups/$files_prefix.$today.tar.gz $files_folder_name`;

$paths = [
  $_SERVER['MINIO_BUCKET'] => [
    'path' => '/app/backups',
    'glob' => '*.gz',
  ],
];

/**
 * Step One: Delete any files older than 14 days.
 */
echo "Deletions:\n";
foreach ($paths as $bucket => $info) {
  try {
    $result = $s3->ListObjects([
      'Bucket' => $bucket,
      'EncodingType' => 'url',
    ]);
  } catch (S3Exception $e) {
    echo $e->getMessage();
  }
  if (is_array($result['Contents'])) {
    foreach ($result['Contents'] as $key => $content) {
      $last_modified = strtotime($content['LastModified']->__toString());
      // Entries expire and are deleted after 14 days.
      $expires = time() - (60*60*24) * $_SERVER['MINIO_FILE_TTL'];
      if ($last_modified < $expires) {
        $delete = $s3->DeleteObject([
          'Bucket' => $bucket,
          'Key' => $content['Key'],
        ]);
        echo " - Deleted Key: $bucket/" . $content['Key'] . "\n";
      }
    }
  }
}

/**
 * Step Two: Upload any new files transferred in via rsync
 */
echo "Additions:\n";
foreach ($paths as $bucket => $info) {
  chdir($info['path']);

  foreach (glob($info['glob']) as $filename) {
    $response = $s3->doesObjectExist($bucket, $filename);

    if ($response != '1') {
      print " - Added:  $bucket/$filename\n";
      // Send a PutObject request and get the result object.
      $result = $s3->putObject([
        'Bucket' => $bucket,
        'Key'    => $filename,
        'SourceFile' => $info['path'] .'/' . $filename
      ]);
    }
    unlink($info['path'] .'/' . $filename);
  }
}
