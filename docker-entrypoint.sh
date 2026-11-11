#!/bin/sh
# Render gives the port; secret files (mail.php, paymongo_keys.php, fcm.php) are pasted in its dashboard.
sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT:-80}>/" /etc/apache2/sites-available/000-default.conf
for f in /etc/secrets/*.php; do [ -f "$f" ] && cp "$f" /var/www/html/config/; done
exec apache2-foreground
