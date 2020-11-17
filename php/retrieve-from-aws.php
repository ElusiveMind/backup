<?php

require '/php/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;
use Aws\S3\ListObjects;
use Aws\S3\DeleteObject;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$aws_endpoint = getenv('AWS_ENDPOINT');
$aws_key = getenv('AWS_KEY');
$aws_secret = getenv('AWS_SECRET');
$aws_bucket = getenv('AWS_BUCKET');
$aws_bucket_subfolder = getenv('AWS_BUCKET_SUBFOLDER');

$site_identifier = getenv('SITE_IDENTIFIER');

$use_postgres = getenv('USE_POSTGRES');
$mysql_host = getenv('MYSQL_HOST');
$user = getenv('MYSQL_USER');
$pass = getenv('MYSQL_PASS');
$rootpass = getenv('MYSQL_ROOTPASS');
$host = getenv('MYSQL_HOST');
$database = getenv('MYSQL_DATABASE');

$data_prefix = getenv('DATA_PREFIX');
$files_prefix = getenv('FILES_PREFIX');

$files_folder_parent = getenv('FILES_FOLDER_PARENT');
$files_folder_name = getenv('FILES_FOLDER_NAME');

$skip_files = getenv('SKIP_FILES');
$skip_database = getenv('SKIP_DATABASE');
$keep_local = getenv('KEEP_LOCAL');

$delete_first = getenv('DELETE_FIRST');

if (!empty($aws_key) && !empty($aws_secret)) {
  $s3 = new S3Client([
    'version' => 'latest',
    'region'  => 'us-east-1',
    'endpoint' => $aws_endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => [
      'key'    => $aws_key,
      'secret' => $aws_secret,
    ],
    'http' => [
      'verify' => FALSE,
    ],
  ]);
}
else {
  print "Unable to connect to aws.";
}

$files = ['cancel'];
try {
  $results = $s3->getPaginator('ListObjects', [
    'Bucket' => $aws_bucket
  ]);
} catch (S3Exception $e) {
  echo $e->getMessage();
}
if (!empty($results)) {
  foreach ($results as $result) {
    foreach ($result['Contents'] as $key => $content) {
      if (!empty($aws_bucket_subfolder)) {
        if (strpos($content['Key'], $aws_bucket_subfolder) !== FALSE) {
          $files[] = $content['Key'];
        }
      }
    }
  }
}

foreach ($files as $key => $option) {
  print "[$key] - $option\n";
}

echo "Select file to retrieve: ";
$input = rtrim(fgets(STDIN));

if (empty($input) || $input == '0') {
  echo "Cancelled.";
  exit();
}

if (!empty($files[$input])) {
  echo "Retrieving: " . $files[$input] . "\n";
  try {
    $result = $s3->getObject([
      'Bucket' => $aws_bucket,
      'Key'    => $files[$input],
    ]);
    $fileparts = explode('/', $files[$input]);
    $filename = $fileparts[1];
    file_put_contents($filename, $result['Body']);
  } catch (S3Exception $e) {
    echo $e->getMessage() . PHP_EOL;
  }
}