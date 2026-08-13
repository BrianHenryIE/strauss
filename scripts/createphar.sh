#!/bin/bash

# chmod +x scripts/createphar.sh
# ./scripts/createphar.sh

echo "Delete previous artifacts";
rm -rf build
rm strauss.phar

echo "composer install";
composer install --no-dev

echo "Download phar-composer";
# TODO: in GitHub Actions, ideally this will be downloaded by setup-php action, but will be saved in a different directory so check it is in $PATH.
wget -O phar-composer.phar https://github.com/clue/phar-composer/releases/download/v1.4.0/phar-composer-1.4.0.phar

echo "Copy files to build directory";
mkdir build
cp -R vendor build/vendor
cp -R src build/src
cp -R bin build/bin
cp composer.json build
cp composer.lock build
cp bootstrap.php build
cp CHANGELOG.md build

cd build;

echo "Delete known unwanted files";
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

rm -rf vendor/pimple/pimple/src/Pimple/Tests
rm -rf vendor/inmarelibero/gitignore-checker/tests

echo "Run strauss --debug";
php -d memory_limit=2G ../bin/strauss --debug

# TODO: This doesn't seem to be generated at all
# and it's definitely not being loaded right now, so not a pressing issue
# but maybe it's good if it's the last autoloader loaded
# rm vendor/composer/autoload_aliases.php

# TODO: add the number of files that are about to be checked.
echo "Running php -l syntax check on files. Some packages, e.g. polyfills, conditionally load files with newer PHP syntax and will error."

# Allow-list of files known to fail `php -l` on this PHP version; anything else failing is a new error.
php_lint_allowed_errors=(
    "./vendor/symfony/polyfill-intl-normalizer/bootstrap80.php"
    "./vendor/symfony/polyfill-php84/bootstrap82.php"
    "./vendor/symfony/polyfill-php84/Resources/RoundingMode.php"
    "./vendor/symfony/polyfill-php84/Resources/Deprecated.php"
    "./vendor/symfony/service-contracts/Attribute/SubscribedService.php"
    "./vendor/symfony/console/Attribute/AsCommand.php"
    "./vendor/symfony/polyfill-mbstring/bootstrap80.php"
    "./vendor/symfony/polyfill-intl-grapheme/bootstrap80.php"
    "./vendor/symfony/polyfill-intl-grapheme/bootstrap85.php"
)

php_lint_new_errors=()
# `< <(find ...)` rather than `find | while` so php_lint_new_errors persists after the loop (no subshell).
while IFS= read -r file; do
    if php -l "$file" >/dev/null 2>&1; then
        printf "."
        continue
    fi
    if [[ " ${php_lint_allowed_errors[*]} " == *" $file "* ]]; then
        # Known error; print "S" for skipped.
        printf "S"
        continue
    fi
    php_lint_new_errors+=("$file")
    echo
    echo "Error in $file:"
    php -l "$file"
done < <(find . -type f -name "*.php" -print | sed '/^$/d')
# Print a blank line after.
echo

if [ ${#php_lint_new_errors[@]} -gt 0 ]; then
    echo "php -l failed on ${#php_lint_new_errors[@]} file(s) not in the allow-list:"
    printf '%s\n' "${php_lint_new_errors[@]}"
    exit 1
fi


# Required for the autoloader to build correctly. TODO: should be done in PHP @see DumpAutoload.php.
# Removes changes to `vendor/composer/autoload_real.php` etc.
composer dump-autoload --classmap-authoritative;

echo "Run strauss prefix-vendor-autoload";
../bin/strauss prefix-vendor-autoload;

cd ..;

echo "Run phar-composer.phar build";
php -d phar.readonly=off phar-composer.phar build ./build/

# TODO: don't bother if we're running in GitHub Actions.
rm phar-composer.phar
rm -rf build

echo "Run composer install";
composer install

echo "Smoke test strauss.phar (print version)";
php strauss.phar --version
