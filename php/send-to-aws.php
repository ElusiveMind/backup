<?php

/**
 * send-to-aws.php
 * A script to send backup files to a AWS/S3 mount.
 * This is primarily made for USDA eWAPS in mind.
 * by Michael R. Bagnall <mbagnall@itcon-inc.com> - September 23, 2019
 */

ini_set('memory_limit', -1);
send_message('Today: ' . date('m-d-Y H:i:s'));
send_message('Include all of the classes we need to autoload');
require '/php/vendor/autoload.php';

send_message('Load in our classes.');
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;
use Aws\S3\ListObjects;
use Aws\S3\DeleteObject;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

send_message('Define our variables from environment variables.');
$site_identifier = getenv('SITE_IDENTIFIER');
$aws_endpoint = getenv('AWS_ENDPOINT');
$mysql_host = getenv('MYSQL_HOST');
$aws_key = getenv('AWS_KEY');
$aws_secret = getenv('AWS_SECRET');
$user = getenv('MYSQL_USER');
$pass = getenv('MYSQL_PASS');
$rootpass = getenv('MYSQL_ROOTPASS');
$host = getenv('MYSQL_HOST');
$database = getenv('MYSQL_DATABASE');
$data_prefix = getenv('DATA_PREFIX');
$files_prefix = getenv('FILES_PREFIX');
$files_folder_parent = getenv('FILES_FOLDER_PARENT');
$files_folder_name = getenv('FILES_FOLDER_NAME');
$aws_bucket = getenv('AWS_BUCKET');
$skip_files = getenv('SKIP_FILES');
$skip_database = getenv('SKIP_DATABASE');
$delete_first = getenv('DELETE_FIRST');
$aws_bucket_subfolder = getenv('AWS_BUCKET_SUBFOLDER');

send_message('Pre-systems check');
$sitename = (!empty($site_identifier)) ? $site_identifier : "Identifier Not Provided.";
// If we are not properly configured, then we cannot continue.
if (empty($aws_endpoint) || empty($mysql_host)) {
  send_message('Unable to run AWS backup script.');
  $html = "Unable to run AWS Backup script on " . $sitename . ". Check your environment variables for this container.";
  send_html_email($html);
  exit();
}

/** Add the slash to the subfolder if we are configured. */
if (!empty($aws_bucket_subfolder)) {
  $aws_bucket_subfolder .= '/';
}

$paths = [
  $aws_bucket => [
    'path' => '/app/backups',
    'glob' => '*.gz',
  ],
];

$today = date('Y-m-d');

if (!is_dir('/app/backups')) {
  mkdir('/app/backups');
}

send_message('Extract and encrypt the MySQL Database');
/** Get a current snapshot of the MySQL database provided a gzipped copy does not exist. */
if (empty($skip_database)) {
  if (!file_exists("/app/backups/$database-$data_prefix.$today.sql.gz")) {
    $mysql_query = "mysqldump -uroot --password='" . $rootpass . "' -h" . $host . " " . $database . "> /app/backups/" . $database . "-" . $data_prefix . "." . $today . ".sql";
    $mysql_backup = exec($mysql_query);
    $gzip = `gzip -f /app/backups/$database-$data_prefix.$today.sql`;
    $db_size_query = "mysql -uroot --password='" . $rootpass . "' -h" . $host . " information_schema -e 'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) as data_size FROM information_schema.tables WHERE table_schema=\"$database\"' -N -s";
    $db_size = exec($db_size_query);
  }
}

send_message('Pack up the files directory');
/** Then get a copy of the files directory if the gzip does not already exist. */
if (empty($skip_files)) {
  if (!file_exists("/app/backups/$files_prefix.$today.tar.gz")) {
    $files = `cd $files_folder_parent; tar -czf /app/backups/$files_prefix.$today.tar.gz $files_folder_name`;
  }
}

/** Begin our HTML Email output. */
$html = '<html><head><title>' . $site_identifier . '</title>';
$html .= '<style>body { color: #FFFFFF: background-color: #000000; font-family: Tahoma, Arial; font-size: 14pt; }</style>';
$html .= '</head><body><table border="0" width="960" align="center"><tr><td>';
$html .= '<b>Backup Report For:</b> ' . $site_identifier . "<br />";
$html .= 'Backup Container ' . getenv('VERSION_NUMBER') . ' - Michael R. Bagnall - mbagnall@itcon-inc.com<br />';
$html .= 'Data Prefix: ' . getenv('DATA_PREFIX') . ' / Files Prefix: ' . getenv('FILES_PREFIX'). '<br />';
$html .= 'Database Size of ' . $database . ' dump: ' . $db_size . ' Megabytes.<hr />';

/** Open up our connection to S3 */
send_message('Open our S3 clent.');
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

/** Some hosts need to delete files first for space concerns. Allow */
/** this to be configurable. */
if (!empty($delete_first)) {
  delete_files($html, $s3, $paths, $aws_bucket_subfolder);
  upload_files($html, $s3, $paths, $aws_bucket_subfolder);
}
else {
  upload_files($html, $s3, $paths, $aws_bucket_subfolder);
  delete_files($html, $s3, $paths, $aws_bucket_subfolder);
}

// Close out our HTML.
$html .= '</td></tr></table></body></html>';

send_html_email($html);

/************************************************************************/

function delete_files(&$html, $s3, $paths, $aws_bucket_subfolder) {
  send_message('Do Our Deletions');
  /** Step One: Delete any files older than the interval number of days (TTL) days. */
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
        // Entries expire and are deleted after the configured days.
        $expires = time() - (60*60*24) * getenv('AWS_FILE_TTL');
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
}

function upload_files(&$html, $s3, $paths, $aws_bucket_subfolder) {
  send_message("Do our upload");
  /** Step Two: Upload any new files. */
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
          send_message("<i>Adding:</i> $bucket/$filename</li>");
          $html .= "<i>Adding:</i> $bucket/$filename</li>";
          $filepath = $info['path'] .'/' . $filename;
          $result = $s3->createMultipartUpload([
            'Bucket'       => $bucket,
            'Key'          => $filename,
            'StorageClass' => 'REDUCED_REDUNDANCY',
            'ACL'          => 'public-read',
          ]);
          $uploadId = $result['UploadId'];

          // Upload the file in parts.
          try {
            $file = fopen($filepath, 'r');
            $partNumber = 1;
            while (!feof($file)) {
              $body = fread($file, 500 * 1024 * 1024);
              $result = $s3->uploadPart([
                'Bucket'     => $bucket,
                'Key'        => $filename,
                'UploadId'   => $uploadId,
                'PartNumber' => $partNumber,
                'Body'       => $body,
              ]);
              $parts['Parts'][$partNumber] = [
                'PartNumber' => $partNumber,
                'ETag' => $result['ETag'],
              ];
              $partNumber++;
              echo "Uploading part {$partNumber} of {$filename}." . PHP_EOL;
              unset($result);
            }
            fclose($file);
          } catch (S3Exception $e) {
            $result = $s3->abortMultipartUpload([
              'Bucket'   => $bucket,
              'Key'      => $filename,
              'UploadId' => $uploadId
            ]);

            echo "Upload of {$filename} failed." . PHP_EOL;
          }

          // Complete the multipart upload.
          $result = $s3->completeMultipartUpload([
            'Bucket'   => $bucket,
            'Key'      => $filename,
            'UploadId' => $uploadId,
            'MultipartUpload'    => $parts,
          ]);
          $url = $result['Location'];
          unset($result);
        }
        else {
          $html .= $bucket . '/' . $filename . ' <i>Already Exists</i></li>';
          send_message($bucket . '/' . $filename . ' <i>Already Exists</i></li>');
        }
        unlink($info['path'] .'/' . $filename);
      }
      $html .= '</ul>';
    }
  }
}

/**
 * Send the email contained in the argument to the recipients specified in the
 * environment variables. See the README for more information on the environment
 * variables being used.
 * 
 * @param string $html
 *  The HTML of the email we wish to send
 */
function send_html_email(&$html) {

  // If we do not have an SMTP hostname then we really cannot do anything.
  if (empty(getenv('SMTP_HOSTNAME'))) {
    return FALSE;
  }

  // Instantiation and passing `true` enables exceptions
  $mail = new PHPMailer(TRUE);

  try {
    $mail->isSMTP();
    $mail->SMTPDebug = getenv('SMTP_DEBUG');
    $mail->Host = getenv('SMTP_HOSTNAME');

    $mail->SMTPAuth = getenv('SMTP_AUTH');
    if (getenv('SMTP_AUTH') != 0) {
      $mail->Username = getenv('SMTP_USERNAME');
      $mail->Password = getenv('SMTP_PASSWORD');
    }

    $smtp_port = getenv('SMTP_PORT');
    if ($smtp_port != 25) {
      $mail->SMTPSecure = 'tls';
      $smtp_port = 587;
    }
    else {
      $mail->SMTPSecure = '';
      $mail->SMTPAutoTLS = FALSE;
    }

    $mail->Port = $smtp_port;

    // Set Recipients.
    $mail->setFrom(getenv('SMTP_FROM'));
    $mail->addAddress(getenv('SMTP_TO'));
    $mail->addReplyTo(getenv('SMTP_FROM'));

    // Content
    $mail->isHTML(true);
    $mail->Subject = $site_identifier . ' Server Backup Report';
    $mail->Body = $html;
    $mail->AltBody = 'You need an HTML email program to read this email.';

    $mail->send();
  }
  catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
  }
}

/**
 * Create a debug log if we are in debug mode.
 * 
 * @param string $text
 *  The text to be appended to the end of the log file.
 */
function send_message($text) {
  $debug = getenv('DEBUG');
  if (!empty($debug)) {
    $fh = fopen('/app/backups/message.txt', 'a');
    fwrite($fh, $text . "\n");
    fclose($fh);
  }

}
