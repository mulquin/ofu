# ofu
One File Uploader -- A simple PHP uploading script

This is a fork of <a href="https://github.com/rouji/single_php_filehost">rouji/single_php_filehost</a>

File validation code adapted from php.net answer by <a href="https://www.php.net/manual/en/features.file-upload.php#114004">CertaiN</a>

HTTP Basic Auth code adapted from Gist by <a href="https://gist.github.com/westonruter/3131129">westonruter</a>

## .htaccess info

Root Directory:

```
Options -FollowSymLinks -MultiViews -Indexes
AddDefaultCharset UTF-8
```


Files Directory

```
Options -ExecCGI -FollowSymLinks -MultiViews -Indexes
php_flag engine off
SetHandler None
AddType text/plain .php .php5 .html .htm .cpp .c .h .sh .cmd .bat
```
