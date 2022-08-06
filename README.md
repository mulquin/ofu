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

RewriteEngine On
RewriteCond "%{ENV:REDIRECT_STATUS}" "^$"
RewriteRule "^/?$" "index.php" [L,END]
RewriteRule "^(.+)$" "files/$1" [L,END]
```


Files Directory

```
Options -ExecCGI -FollowSymLinks -MultiViews -Indexes
php_flag engine off
SetHandler None
AddType text/plain .php .php5 .phtml .html .htm .cpp .c .h .sh .cmd .bat .jar
```

## Secure file upload resources

* https://owasp.org/www-community/vulnerabilities/Unrestricted_File_Upload
* https://paragonie.com/blog/2015/10/how-securely-allow-users-upload-files