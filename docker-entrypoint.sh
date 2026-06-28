#!/bin/bash
PORT="${PORT:-80}"
echo "Starting Apache on port $PORT"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf
exec apache2ctl -D FOREGROUND
