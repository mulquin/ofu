# ofu
One File Uploader -- A simple PHP uploading script

In a similar vein to 0x0.st and 0x.at, this script can be used via curl.

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
