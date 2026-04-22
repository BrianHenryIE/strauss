#!/bin/bash

# chmod +x scripts/createphar.sh
# ./scripts/createphar.sh

rm -rf build
rm strauss.phar

composer install --no-dev
wget -O phar-composer.phar https://github.com/clue/phar-composer/releases/download/v1.4.0/phar-composer-1.4.0.phar

mkdir build
cp -R vendor build/vendor
cp -R src build/src
cp -R bin build/bin
cp composer.json build
cp composer.lock build
cp bootstrap.php build
cp CHANGELOG.md build

cd build;

rm -rf vendor/elazar/flystream/tests
rm -rf vendor/elazar/flystream/docker
rm -rf vendor/elazar/flystream/.github
rm vendor/elazar/flystream/.*
rm vendor/elazar/flystream/*.xml
rm vendor/elazar/flystream/*.yml

# @see https://github.com/JsonMapper/JsonMapper/pull/208
rm -rf vendor/json-mapper/json-mapper/tests
rm -rf vendor/json-mapper/json-mapper/.github
rm vendor/json-mapper/json-mapper/.*
rm vendor/json-mapper/json-mapper/*.dist
rm vendor/json-mapper/json-mapper/*.xml

../bin/strauss --info;

echo "Running php -l syntax check on files. Some packages, e.g. polyfills, conditionally load files with newer PHP syntax and will error."

find . -type f -name "*.php" -print | sed '/^$/d' | \
while IFS= read -r file; do
    if php -l "$file" >/dev/null 2>&1; then
        printf "."
    else
        echo
        echo "Error in $file:"
        php -l "$file"
    fi
done
# Print a blank line after.
echo

# Required for the autoloader to build correctly. TODO: should be done in PHP @see DumpAutoload.php.
# Removes changes to `vendor/composer/autoload_real.php` etc.
composer dump-autoload --classmap-authoritative;

../bin/strauss prefix-vendor-autoload;

cd ..;

php -d phar.readonly=off phar-composer.phar build ./build/

rm phar-composer.phar
rm -rf build
composer install

php strauss.phar --version