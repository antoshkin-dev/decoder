#!/bin/sh
chmod 777 /var/www/logs 

update-ca-certificates
exec php-fpm

