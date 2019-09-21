<?php

require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;
use Aws\S3\ListObjects;
use Aws\S3\DeleteObject;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

if (!file_exists("/app/backups/$database-$data_prefix.$today.sql")) {
  $mysql = `mysqldump -u$user -p$pass -h$host $database > /app/backups/$database-$data_prefix.$today.sql.gz`;
  $gzip = `gzip /app/backups/$database-$data_prefix.$today.sql`;
}

// Then get a copy of the files directory
if (!file_exists("/app/backups/$files_prefix.$today.tar.gz")) {
  $files = `cd $files_folder_parent; tar -czf /app/backups/$files_prefix.$today.tar.gz $files_folder_name`;
}

$paths = [
  $_SERVER['MINIO_BUCKET'] => [
    'path' => '/app/backups',
    'glob' => '*.gz',
  ],
];


$html = 'Backup Container ' . $_SERVER['VERSION_NUMBER'] . ' - Michael R. Bagnall - <mbagnall@itcon-inc.com><hr />';

/**
 * Step One: Delete any files older than 14 days.
 */
$html .= '<b>Deletions:</b><hr />';
foreach ($paths as $bucket => $info) {
  try {
    $result = $s3->ListObjects([
      'Bucket' => $bucket,
      'EncodingType' => 'url',
    ]);
  } catch (S3Exception $e) {
    echo $e->getMessage();
  }
  if (!empty($result['Contents'])) {
    $html .= '<ul>';
    foreach ($result['Contents'] as $key => $content) {
      $last_modified = strtotime($content['LastModified']->__toString());
      // Entries expire and are deleted after 14 days.
      $expires = time() - (60*60*24) * $_SERVER['MINIO_FILE_TTL'];
      if ($last_modified < $expires) {
        $delete = $s3->DeleteObject([
          'Bucket' => $bucket,
          'Key' => $content['Key'],
        ]);
        $html .= "<li><i>Deleted Key:</i> $bucket/" . $content['Key'] . '</li>';
      }
    }
    $html .= '</ul>';
  }
  else {
    $html .= '<i>There are no files queued for deletion.</i><br /><br />';
  }
}

/**
 * Step Two: Upload any new files transferred in via rsync
 */
$html .= "<b>Additions:</b><hr />";
foreach ($paths as $bucket => $info) {
  chdir($info['path']);
  $filenames = glob($info['glob']);
  if (!empty($filenames)) {
    $html .= '<ul>';
    foreach (glob($info['glob']) as $filename) {
      $html .= '<li><b>' . $info['path'] . ':</b> ';
      $response = $s3->doesObjectExist($bucket, $filename);
      if ($response != '1') {
        $html .= "<i>Adding:</i> $bucket/$filename</li>";

        // Using stream instead of file path
        $source = fopen($info['path'] .'/' . $filename, 'rb');

        $uploader = new ObjectUploader(
          $s3,
          $bucket,
          $filename,
          $source
        );
        do {
          try {
            $result = $uploader->upload();
          } catch (MultipartUploadException $e) {
            rewind($source);
            $uploader = new MultipartUploader($s3, $source, [
              'state' => $e->getState(),
            ]);
          }
        } while (!isset($result));
      }
      else {
        $html .= $bucket . '/' . $filename . ' <i>Already Exists</i></li>';
      }
      unlink($info['path'] .'/' . $filename);
    }
  }
}

// Instantiation and passing `true` enables exceptions
$mail = new PHPMailer(TRUE);

try {
  //Server settings
  //$mail->SMTPDebug = 2; // Enable verbose debug output
  $mail->isSMTP(); // Set mailer to use SMTP
  $mail->Host = $_SERVER['SMTP_HOSTNAME']; // Specify main and backup SMTP servers
  $mail->SMTPAuth = TRUE; // Enable SMTP authentication
  $mail->Username = $_SERVER['SMTP_USERNAME']; // SMTP username
  $mail->Password = $_SERVER['SMTP_PASSWORD']; // SMTP password
  $mail->SMTPSecure = 'tls'; // Enable TLS encryption, `ssl` also accepted
  $mail->Port = 587; // TCP port to connect to

  //Recipients
  $mail->setFrom($_SERVER['SMTP_FROM']);
  $mail->addAddress($_SERVER['SMTP_TO']);
  $mail->addReplyTo($_SERVER['SMTP_FROM']);

  // Content
  $mail->isHTML(true);
  $mail->Subject = 'Completed Backup Server Run';
  $mail->Body = $html;
  $mail->AltBody = 'You need an HTML email program to read this email. Get with the century';

  $mail->send();
} catch (Exception $e) {
  echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
