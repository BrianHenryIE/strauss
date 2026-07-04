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

cd build;

# Ala `.gitattributes` see #254.

rm -rf vendor/elazar/flystream/tests
rm -rf vendor/elazar/flystream/docker
rm -rf vendor/elazar/flystream/.github
rm "vendor/elazar/flystream/.*"
rm vendor/elazar/flystream/*.xml
rm vendor/elazar/flystream/*.yml

# @see https://github.com/JsonMapper/JsonMapper/pull/208
rm -rf vendor/json-mapper/json-mapper/tests
rm -rf vendor/json-mapper/json-mapper/.github
rm "vendor/json-mapper/json-mapper/.*"
rm vendor/json-mapper/json-mapper/*.dist
rm vendor/json-mapper/json-mapper/*.xml

../bin/strauss --info;

# TODO: This doesn't seem to be generated at all
# and it's definitely not being loaded right now, so not a pressing issue
# but maybe it's good if it's the last autoloader loaded
# rm vendor/composer/autoload_aliases.php

# TODO: add the number of files that are about to be checked.
echo "Running php -l syntax check on files. Some packages, e.g. polyfills, conditionally load files with newer PHP syntax and will error."

# TODO: skip known "errors"
#Error in ./vendor/symfony/polyfill-intl-normalizer/bootstrap80.php:
#Error in ./vendor/symfony/service-contracts/Attribute/SubscribedService.php:
#Error in ./vendor/symfony/console/Attribute/AsCommand.php:
#Error in ./vendor/symfony/polyfill-mbstring/bootstrap80.php:
#Error in ./vendor/symfony/polyfill-intl-grapheme/bootstrap80.php:
#Error in ./vendor/symfony/polyfill-intl-grapheme/bootstrap85.php:

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
