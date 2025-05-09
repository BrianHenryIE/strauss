#!/bin/bash

# chmod +x scripts/createphar.sh
# ./scripts/createphar.sh

rm -rf build
composer install --no-dev
wget -O phar-composer.phar https://github.com/clue/phar-composer/releases/download/v1.4.0/phar-composer-1.4.0.phar
mkdir build
cp -R vendor build/vendor
cp -R src build/src
cp -R bin build/bin
cp composer.json build
cp bootstrap.php build
cp CHANGELOG.md build
php -d phar.readonly=off phar-composer.phar build ./build/

rm phar-composer.phar
rm -rf build
composer install

php strauss.phar --version