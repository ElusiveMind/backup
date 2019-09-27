<?php

/**
 * send-to-minio.php
 * A script to send backup files to a MinIO/S3 mount.
 * This is primarily made for USDA eWAPS in mind.
 * by Michael R. Bagnall <mbagnall@itcon-inc.com> - September 23, 2019
 */

require '/php/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;
use Aws\S3\ListObjects;
use Aws\S3\DeleteObject;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$sitename = (!empty($_SERVER['SITE_IDENTIFIER'])) ? $_SERVER['SITE_IDENTIFIER'] : "Identifier Not Provided.";
// If we are not properly configured, then we cannot continue.
if (empty($_SERVER['MINIO_ENDPOINT']) || empty($_SERVER['MYSQL_HOST'])) {
  $html = "Unable to run MinIO Backup script on " . $sitename . ". Check your environment variables for this container.";
  send_html_email($html);
  exit();
}

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

// Get a current snapshot of the MySQL database provided a gzipped copy does not exist.
if (!file_exists("/app/backups/$database-$data_prefix.$today.sql.gz")) {
  $mysql = `mysqldump -u$user -p'$pass' -h$host $database > /app/backups/$database-$data_prefix.$today.sql`;
  $gzip = `gzip -f /app/backups/$database-$data_prefix.$today.sql`;
}

// Then get a copy of the files directory if the gzip does not already exist.
if (!file_exists("/app/backups/$files_prefix.$today.tar.gz")) {
  $files = `cd $files_folder_parent; tar -czf /app/backups/$files_prefix.$today.tar.gz $files_folder_name`;
}

$paths = [
  $_SERVER['MINIO_BUCKET'] => [
    'path' => '/app/backups',
    'glob' => '*.gz',
  ],
];

// Begin our HTML output.
$html = '<html><head><title>' . $_SERVER['SITE_IDENTIFIER'] . '</title>';
$html .= '<style>body { color: #FFFFFF: background-color: #000000; font-family: Tahoma, Arial; font-size: 14pt; }</style>';
$html .= '</head><body><table border="0" width="960" align="center"><tr><td>';
$html .= '<b>Backup Report For:</b> ' . $_SERVER['SITE_IDENTIFIER'] . "<br />";
$html .= 'Backup Container ' . $_SERVER['VERSION_NUMBER'] . ' - Michael R. Bagnall - mbagnall@itcon-inc.com<br />';
$html .= 'Data Prefix: ' . $_SERVER['DATA_PREFIX'] . ' / Files Prefix: ' . $_SERVER['FILES_PREFIX']. '<hr />';

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
  if (isset($result['Contents']) && count($result['Contents'] > 0)) {
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
    $html .= '<b>There are no files queued for deletion.</b><br /><br />';
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

// Close out our HTML.
$html .= '</td></tr></table></body></html>';

// Send the email.
send_html_email($html);

/**
 * send_html_email($html)
 * Send the email contained in the argument to the recipients specified in the
 * environment variables. See the README for more information on the environment
 * variables being used.
 */
function send_html_email($html) {

  // If we do not have an SMTP hostname then we really cannot do anything.
  if (empty($_SERVER['SMTP_HOSTNAME'])) {
    return FALSE;
  }

  // Instantiation and passing `true` enables exceptions
  $mail = new PHPMailer(TRUE);

  try {
    $mail->isSMTP();
    $mail->SMTPDebug = $_SERVER['SMTP_DEBUG'];
    $mail->Host = $_SERVER['SMTP_HOSTNAME'];

    $mail->SMTPAuth = $_SERVER['SMTP_AUTH'];
    if ($_SERVER['SMTP_AUTH'] != 0) {
      $mail->Username = $_SERVER['SMTP_USERNAME'];
      $mail->Password = $_SERVER['SMTP_PASSWORD'];
    }

    if ($_SERVER['SMTP_PORT'] != 25) {
      $mail->SMTPSecure = 'tls';
      $_SERVER['SMTP_PORT'] = 587;
    }
    else {
      $mail->SMTPSecure = '';
      $mail->SMTPAutoTLS = FALSE;
    }

    $mail->Port = $_SERVER['SMTP_PORT'];

    // Set Recipients.
    $mail->setFrom($_SERVER['SMTP_FROM']);
    $mail->addAddress($_SERVER['SMTP_TO']);
    $mail->addReplyTo($_SERVER['SMTP_FROM']);

    // Content
    $mail->isHTML(true);
    $mail->Subject = $_SERVER['SITE_IDENTIFIER'] . ' Server Backup Report';
    $mail->Body = $html;
    $mail->AltBody = 'You need an HTML email program to read this email.';

    $mail->send();
  }
  catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
  }
}