<?php

const PRINT_DEBUG = false; // Show debug info on the index page

define('MAX_FILESIZE', intval(ini_get('upload_max_filesize'))); // Filesize in MiB
const MAX_FILE_AGE = 365; // Max days in age
const MIN_FILE_AGE = 7; // Min days in age

const GRAPH_COLS = 80; // Width of graph (not including borders / legend)
const GRAPH_ROWS = 30;

const DECAY_EXPONENT = 3; // Exponent to modify retention algo. Larger numbers punish large files

const RANDOM_FILENAME_LENGTH = 4; // Length of random filename

const STORAGE_FOLDER = 'files' . DIRECTORY_SEPARATOR;
const STORAGE_PATH = __DIR__ . DIRECTORY_SEPARATOR . STORAGE_FOLDER;

define('LOG_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR);
define('UPLOAD_LOG_PATH', LOG_DIR . 'ofu-upload.' . date('Y-m-d') . '.log');
define('ERROR_LOG_PATH', LOG_DIR . 'ofu-error.' . date('Y-m-d') . '.log');
define('PURGE_LOG_PATH', LOG_DIR . 'ofu-purge.' . date('Y-m-d') . '.log');

const FILETYPE_BLOCKLIST = [
    'application/x-dosexec', 'application/x-executable', 'application/x-hdf5', 
    'application/java-archive', 'application/java-vm', 
    'application/vnd.android.package-archive'
];

const ADMIN_EMAIL = 'admin@email.com';

define('SITE_URL', site_url());

const AUTH_USER = 'username'; // Both user and pass must not be null for basic authentication
const AUTH_PW = null; 

function site_url(): string
{
    if (isset($_SERVER['HTTP_HOST'])) {
        $protocol = 'http';
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            $protocol .= 's';
        return $protocol . '://' . $_SERVER['HTTP_HOST'] . '/';
    }
}

function mkdir_if_no_dir($path, $permissions = 0750): bool
{
    if (!is_dir($path)) {
        if (!mkdir($path, $permissions)) {
            return false;
        }
    }
    return true;
}

function is_valid_environment(): bool
{
    if (!mkdir_if_no_dir(STORAGE_PATH)) {
        serve_http_code(500, 'Could not create "' . STORAGE_PATH . '"');
        return false;
    }

    if (!is_writable(STORAGE_PATH)) {
        serve_http_code(500, '"' . STORAGE_PATH . '" not writable');
        return false;
    }

    if (!is_file(STORAGE_PATH . 'index.html')) {
        if (!touch(STORAGE_PATH . 'index.html')) {
            serve_http_code(500, 'Could not create "' . STORAGE_PATH . 'index.html"');
            return false;
        }
    }

    if (UPLOAD_LOG_PATH !== null) {
        if (!mkdir_if_no_dir(dirname(UPLOAD_LOG_PATH))) {
            serve_http_code(500, 'Could not create "' . UPLOAD_LOG_PATH . '"');
            return false;
        }
    }

    if (ERROR_LOG_PATH !== null) {
        if (!mkdir_if_no_dir(dirname(ERROR_LOG_PATH))) {
            serve_http_code(500, 'Could not create "' . ERROR_LOG_PATH . '"');
            return false;
        }
    }

    if (PURGE_LOG_PATH !== null) {
        if (!mkdir_if_no_dir(dirname(PURGE_LOG_PATH))) {
            serve_http_code(500, 'Could not create "' . PURGE_LOG_PATH . '"');
            return false;
        }
    }
    
    return true;
}

function serve_http_code($code, $message = ''): void
{
    $default_code_message = [
        400 => 'Bad Request',
        401 => 'Authorization Required',
        413 => 'Payload Too Large',
        500 => 'Internal Server Error',
        520 => 'Unknown Error'
    ];

    if (!in_array($code, array_keys($default_code_message)))
        $code = 500;

    if ($message === '')
        $message = $default_code_message[$code];

    if (ERROR_LOG_PATH !== null) {
        file_put_contents(
            ERROR_LOG_PATH,
            implode("\t", [
                date('c'),
                $_SERVER['REMOTE_ADDR'],
                $code,
                $message
            ]) . PHP_EOL,
            FILE_APPEND
        );
    }

    if ($code === 500 || $code === 520)
        $message = $default_code_message[$code];

    header('HTTP/1.1 ' . $code . ' ' . $default_code_message[$code]);

    if ($code === 401)
        header('WWW-Authenticate: Basic realm="Access denied"');

    echo 'Error ' . $code . ': ' . $message . PHP_EOL;
}

function is_uploading_file(): bool
{
    if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) // For files larger than ini
        return true;

    if (!empty($_FILES))
        return true;
    
    return false;
}

function has_uploaded_valid_file($file = []): bool
{
    if (empty($file) && $_SERVER['CONTENT_LENGTH'] > 0) {
        serve_http_code(413, 'Max file size (' . MAX_FILESIZE . ' MiB) exceeded');
        return false;
    }

    if (empty($file)) {
        serve_http_code(400, 'No file uploaded');
        return false;       
    }

    if (!isset($file['error'])) {
        serve_http_code(500, 'Missing $file["error"] element');
        return false;
    }

    if (is_array($file['error'])) {
        serve_http_code(500, 'Strange $file["error"] element: ' . json_encode($file));
        return false;        
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
            serve_http_code(413, 'Max file size (' . MAX_FILESIZE . ' MiB) exceeded');
            return false;
            break;
        case UPLOAD_ERR_FORM_SIZE:
            serve_http_code(413, 'File is larger than form value MAX_FILE_SIZE (' . $_POST['MAX_FILE_SIZE'] . ')');
            return false;
            break;
        case UPLOAD_ERR_PARTIAL:
            serve_http_code(400, 'File was only partially uploaded');
            return false;
            break;
        case UPLOAD_ERR_NO_FILE:
            serve_http_code(400, 'No file uploaded');
            return false;
            break;
        case UPLOAD_ERR_NO_TMP_DIR:
            serve_http_code(500, 'Missing temporary folder');
            return false;
            break;
        case UPLOAD_ERR_CANT_WRITE:
            serve_http_code(500, 'Failed to write to disk');
            return false;
            break;
        case UPLOAD_ERR_EXTENSION:
            serve_http_code(500, 'A PHP extension stopped the file upload');
            return false;
            break;
        default:
            serve_http_code(520, 'Unknown $file["error"]: ' . json_encode($file));
            return false;
            break;
    }

    $filesize = filesize($file['tmp_name']);

    if ($filesize === 0) {
        serve_http_code(400, 'Uploaded file is empty');
        return false;
    }

    if ($filesize !== $file['size']) {
        serve_http_code(400, 'Error while uploading file');
        return false;
    }

    if ($filesize > MAX_FILESIZE * 1024 * 1024) {
        serve_http_code(413, 'Max file size (' . MAX_FILESIZE . ' MiB) exceeded');
        return false;
    }

    $file_type = get_file_type($file['tmp_name']);
    $is_valid_file_type = is_valid_file_type($file_type);

    if ($is_valid_file_type !== true) {
        serve_http_code(400, 'Invalid file type (' . $file_type . ')');
        return false;
    }
    
    return true;
}

function get_file_type($file): string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return $finfo->file($file);
}

function is_valid_file_type($file_type): bool
{
    $search = array_search($file_type, FILETYPE_BLOCKLIST, true);
    if ($search === false)
        return true;
    return false;
}

function get_file_extension($filename): string
{
    $extension = pathinfo($filename, PATHINFO_EXTENSION);

    if ($extension === '')
        return '';

    $extension = $extension;
    $extension2 = pathinfo(substr($filename,0,-(strlen($extension)+1)), PATHINFO_EXTENSION);

    if ($extension2 === 'tar')
        $extension = $extension2 . '.' . $extension;

    if (strlen($extension) > 10)
        $extension = substr($extension, 0, 10);

    return '.' . $extension;
}

function save_file($file): void
{   
    $extension = get_file_extension($file['name']);

    $additional_length = 0;
    $tries_per_length = 3;
    $tries = 0;

    do {
        $filename = random_string(RANDOM_FILENAME_LENGTH + $additional_length) . $extension;
        $target_path = STORAGE_PATH . $filename;

        ++$tries;
        if ($tries === $tries_per_length) {
            $tries = 0;
            ++$additional_length;
        }
    } while (file_exists($target_path));

    $moved_file = move_uploaded_file($file['tmp_name'], $target_path);

    if (!$moved_file) {
        serve_http_code(520, 'Could not move file: ' . json_encode($file));
        return;
    }

    // TODO: External Anti-virus

    if (UPLOAD_LOG_PATH !== null) {
        file_put_contents(
            UPLOAD_LOG_PATH,
            implode("\t", [
                date('c'),
                $_SERVER['REMOTE_ADDR'],
                filesize($file['tmp_name']),
                escapeshellarg($file['name']),
                $filename
            ]) . PHP_EOL,
            FILE_APPEND
        );
    }

    $url = SITE_URL . STORAGE_FOLDER . $filename;

    echo $url . PHP_EOL;
}

function random_string($length): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $size = strlen($chars) - 1;
    $string = '';
    while ($length--) {
        $string .= $chars[mt_rand(0,$size)];
    }
    return $string;
}

function create_retention_graph(): array
{
    $symbols = [
        'vertical' => '│',
        'horizontal' => '─',
        'top-left' => '┌',
        'top-right' => '┐',
        'bottom-left' => '└',
        'bottom-right' => '┘',
        'point' => '×' // alternatives: ·  ■
    ];

    $max_y = MAX_FILE_AGE;
    $min_y = MIN_FILE_AGE;
    $max_x = MAX_FILESIZE;
    $min_x = 0;
    
    $rows = GRAPH_ROWS;
    $cols = GRAPH_COLS;

    $increment_x = ($max_x-$min_x) / $cols;
    $increment_y = ($max_y-$min_y) / $rows;

    $x_offset = (strlen($max_y) > 4) ? strlen($max_y) + 1 : 5;

    /*
    $graph_settings = [
        'max_y' => $max_y,
        'min_y' => $min_y,
        'max_x' => $max_x,
        'min_x' => $min_x,
        'rows' => $rows,
        'cols' => $cols,
        'increment_x' => $increment_x,
        'increment_y' => $increment_y,
        'x_offset' => $x_offset
    ]);
    */

    $points = [];
    for ($i = 0; $i < $cols; ++$i) {
        $filesize = $i * $increment_x;
        $retention_age = calculate_retention_age($filesize);
        $retention_percent = ($retention_age - $min_y) / ($max_y - $min_y);
        $filesize_percent = ($filesize - $min_x) / ($max_x - $min_x);

        $y_val = $rows - round($rows * $retention_percent); // reverse for y-axis as lower values are at the end
        $x_val = round($cols * $filesize_percent);

        if ($y_val >= $rows) // do not clip lower y-axis boundary
            $y_val = $rows - 1; 
        else if ($y_val < 2) // do not clip upper y-axis boundary
            $y_val = 2;
        
        /*
        $points_info[] = [
            'i' => $i,
            'x_val' => $x_val,
            'y_val' => $y_val,
            'retention_age' => $retention_age,
            'retention_percent' => $retention_percent,
            'filesize' => $filesize,
            'filesize_percent' => $filesize_percent
        ];
        */

        // x values need to be shunted over to accomodate y-axis legend
        $points[($x_val + $x_offset) . ',' . $y_val] = ''; 
    }

    $max_x_length = strlen($max_x);
    $half_x_length = strlen(round($max_x / 2));
    $space_between = round(($cols - 1 - $max_x_length - $half_x_length) / 2) - 1;

    $space_left = $space_between;
    $is_between = false;

    $graph = [];

    for ($y = 0; $y < $rows + 3; ++$y) {
        $graph[$y] = '';
        for ($x = 0; $x < $cols + $x_offset+2; ++$x) {
            if ($y == 0) { // y-axis legend
                if ($x == 0)
                    $graph[$y] = str_pad('Days', $x_offset+1, ' ', STR_PAD_LEFT);
            } else if ($y > $rows) { // x-axis legend
                if ($y == $rows+2)
                    $graph[$y] = str_repeat(' ', $x_offset) . str_pad('Mebibytes (MiB)', $cols-$x_offset, ' ', STR_PAD_BOTH);
                if ($x <= $x_offset) 
                    $graph[$y] .= ' ';
                else if ($x == $x_offset+1) 
                    $graph[$y] .= '0';
                else if ($x == round(($x_offset+$cols) / 2)) {
                    $graph[$y] .= round($max_x / 2);
                    $space_left = $space_between;
                }
                else if ($x == $cols-2 && $max_x_length % 2 == 0) 
                    $graph[$y] .= $max_x;
                else if ($x == $cols-3 && $max_x_length % 2 !== 0) // because of rounding, odd numbered max filesizes need to be shunted left
                    $graph[$y] .= $max_x;
                else {
                    if (--$space_left > 1 && $x < $cols-1)
                        $graph[$y] .= ' ';
                }
            } else if ($x > $x_offset && $x < $cols) {
                if ($y == 1 && $x == $x_offset+1)
                    $graph[$y] .= $symbols['top-left'];
                else if ($y == 1 && $x == $cols-1)
                    $graph[$y] .= $symbols['top-right'];
                else if ($y == $rows && $x == $x_offset+1)
                    $graph[$y] .= $symbols['bottom-left'];
                else if ($y == $rows && $x == $cols-1)
                    $graph[$y] .= $symbols['bottom-right'];
                else if ($y == 1 || $y == $rows)
                    $graph[$y] .= $symbols['horizontal'];
                else if ($y > 1 && ($x == $x_offset+1 || $x == $cols-1))
                    $graph[$y] .= $symbols['vertical'];
                else if (isset($points[$x . ',' . $y])) // the graph itself
                    $graph[$y] .= $symbols['point'];
                else if ($y == round($rows / 2))
                    $graph[$y] .= '-';
                else 
                    $graph[$y] .= ' ';
            } else { // y-axis legend
                if ($y == 1 && $x == 0)
                    $graph[$y] .= str_pad($max_y, $x_offset+1, ' ', STR_PAD_LEFT);
                else if ($y == $rows && $x == 0)
                    $graph[$y] .= str_pad($min_y, $x_offset+1, ' ', STR_PAD_LEFT);
                else if ($y == round($rows / 2) && $x == 0)
                    $graph[$y] .= str_pad(round(($max_y+$min_y) / 2), $x_offset+1, ' ', STR_PAD_LEFT);
                else if ($y !== 1 && $y !== $rows && $y != round($rows / 2) && $x < $cols)
                    $graph[$y] .= ' ';
            }
        }
    }

    return $graph;
}

function purge_files(): void
{
    $files_deleted = [];
    foreach (scandir(STORAGE_PATH) as $filename) {
        if ($filename === '.' || $filename === '..' || $filename === 'index.html')
            continue;
        
        $file = STORAGE_PATH . $filename;
        
        $file_age = round((time() - filemtime($file)) / (60 * 60 * 24), 1);
        if ($file_age < MIN_FILE_AGE)
            continue;

        $filesize = round(filesize($file) / (1024 * 1024),2);
        $retention_age = calculate_retention_age($filesize);

        if ($file_age > $retention_age) {
            unlink($file);
            $files_deleted[] = [
                'filename' => $filename,
                'filesize' => $filesize,
                'file_age' => $file_age
            ];
            echo 'Deleted "' . $filename . '", ' . $filesize . ' MiB, ' . $file_age . ' days old' . PHP_EOL;
        }
    }

    if (!empty($files_deleted) && PURGE_LOG_PATH !== null) {
        $purge_log = '';
        foreach ($files_deleted as $file) {
            $purge_log .= implode("\t", [
                            date('c'),
                            $file['filename'],
                            $file['filesize'],
                            $file['file_age']
                        ]) . PHP_EOL;
        }
        file_put_contents(PURGE_LOG_PATH, $purge_log, FILE_APPEND);
    }    
}

function calculate_retention_age($filesize): float
{
    return MIN_FILE_AGE +
            (MAX_FILE_AGE - MIN_FILE_AGE) *
            pow(1 - ($filesize / MAX_FILESIZE), DECAY_EXPONENT);
}

function print_debug_info(): string
{
    if (PRINT_DEBUG === false)
        return '';
    
    $has_root_htaccess = (file_exists('.htaccess') && filesize('.htaccess') > 0) ? 'true' : 'false';
    $has_files_htaccess = (file_exists(STORAGE_PATH . '.htaccess') && filesize(STORAGE_PATH . '.htaccess') > 0) ? 'true' : 'false';
    $ini_file = php_ini_loaded_file();
    $upload_max_filesize = ini_get('upload_max_filesize');
    $post_max_size = ini_get('post_max_size');
    $max_input_time = ini_get('max_input_time');
    $max_execution_time = ini_get('max_execution_time');
    $user_constants = print_r(get_defined_constants(true)['user'], true);
    return <<<DEBUG
    
<span>===============
Debug Info
===============
Has root .htaccess: {$has_root_htaccess}
Has files .htaccess: {$has_files_htaccess}
php.ini: {$ini_file}
upload_max_filesize: {$upload_max_filesize}
post_max_size: {$post_max_size}
max_input_time: {$max_input_time}
max_execution_time: {$max_execution_time}

Constants:
{$user_constants}</span>
DEBUG;    
}

function print_index(): void
{
    $site_url = SITE_URL;
    $min_age = MIN_FILE_AGE;
    $max_age = MAX_FILE_AGE;
    $max_filesize = MAX_FILESIZE;
    $max_filesize_in_bytes = MAX_FILESIZE * 1024 * 1024;
    $upload_timeout = ini_get('max_input_time');
    $admin_email = ADMIN_EMAIL;
    $decay_exponent = DECAY_EXPONENT;
    
    $filetype_blocklist = wordwrap(implode(', ', FILETYPE_BLOCKLIST), 80, PHP_EOL);

    $debug_info = print_debug_info();

    $retention_graph = implode(PHP_EOL, create_retention_graph());
    $retention_example_filesize = (float) (rand(1, MAX_FILESIZE) . '.' . rand(0, 9));
    $retention_example = round(calculate_retention_age($retention_example_filesize),1);

    if (AUTH_USER !== null && AUTH_PW !== null)
        $curl_cmd = 'curl -u ' . AUTH_USER . ':' . AUTH_PW . ' -F';
    else
        $curl_cmd = 'curl -F';

echo <<<INDEX
<!doctype html>
<html lang="en">
<head>
<title>one file uploader</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta charset="utf-8" />
<style>
body { background-color: #fefefe; color: #111; }
@media (prefers-color-scheme: dark) { body { background-color: #111; color: white; }}
a { color: red; }
a:hover { color: tomato; }
@keyframes flash-red { 50% { color: red; } }
strong > span {
    animation: flash-red 3.5s infinite;
    animation-timing-function: step-end;
}
strong > span:nth-child(2) { animation-delay: 1.166s; }
strong > span:nth-child(3) { animation-delay: 2.323s; }
pre > span { color: red; }
</style>
</head>
<body>
<pre><strong>         __       
   ___  / _|_   _ 
  / _ \| |_| | | |
 | (_) |  _| |_| |
  \___/|_|  \__,_|
        <span>one</span> <span>file</span> <span>uploader</span></strong>

Files are stored for {$min_age} days up to {$max_age} days depending on filesize
The maximum filesize is {$max_filesize} MiB
Uploads will timeout at {$upload_timeout} seconds

Blocked filetypes:
{$filetype_blocklist}
{$debug_info}
===============
How to Upload
===============
You can upload files via simple HTTP POST requests, e.g. using curl:
  {$curl_cmd} "file=@yourfile.png" {$site_url}

Or you can use the form below:
</pre>
<form id="ofu" method="post" enctype="multipart/form-data">
<input type="hidden" name="MAX_FILE_SIZE" value="{$max_filesize_in_bytes}" />
<input type="file" name="file" id="file" />
<input type="submit" value="Upload" />
</form>
<pre>
===============
File Retention
===============
The length of time a file is stored on the server depends on filesize.
Smaller files are retained for a longer time than larger files.

The formula for determing the maximum age of a file:

retention = min_age + (max_age - min_age) * (1 - (filesize_in_mebibytes / max_filesize)) ^ decay_exponent

{$retention_graph}

For example, a {$retention_example_filesize} MiB file:

retention = {$min_age} + ({$max_age} - {$min_age}) * (1 - ({$retention_example_filesize} / {$max_filesize})) ^ {$decay_exponent}
          ≈ {$retention_example} days

===============
Service Info
===============
For early deletion or to report abuse, please send email to {$admin_email}

The code for this script can be found on Github: <a href="https://github.com/mul91n/ofu">mul91n/ofu</a>
</pre>
</body>
</html>
INDEX;
}

function basic_auth(): void
{
    if (AUTH_USER === null || AUTH_PW === null)
        return;
    
    header('Cache-Control: no-cache, must-revalidate, max-age=0');

    $has_credentials = !(empty($_SERVER['PHP_AUTH_USER']) && empty($_SERVER['PHP_AUTH_PW']));

    $is_authenticated = ($has_credentials && $_SERVER['PHP_AUTH_USER'] === AUTH_USER && $_SERVER['PHP_AUTH_PW'] === AUTH_PW);

    if (!$is_authenticated) {
        serve_http_code(401);
        exit;
    }   
}

if (!is_valid_environment())
    exit;

if (!is_uploading_file()) {
    if (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === 'purge') {
        purge_files();
    } else {
        basic_auth();
        print_index();
    }
} else {
    $uploading_file = [];
    if (isset($_FILES['file']))
        $uploading_file = $_FILES['file'];

    if (!has_uploaded_valid_file($uploading_file))
        exit;

    save_file($uploading_file);
}
