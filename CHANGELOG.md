# Change Log

## 0.23.0 July 2025

* Fix: use `COMPOSER=custom.json` environmental variable
* Fix: namespaced function aliasing
* Require `simple-php-code-parser` `^0.15.3`

## 0.22.6 June 2025

* Fix: Use monolog (to avoid implementing `LoggerInterface`)
* Fix: prefixing of constants

## 0.22.5 June 2025

* Fix: Reliable prefixing of global functions
* Fix: FQDN namespaces not correctly prefxied
* Fix: Namespaces with no classes not in the direct namespace not working with psr-4
* Fix vendor autoloader dev entries when target is vendor

## 0.22.4 June 2025

* Require `simple-php-code-parser` `^0.15.1`

## 0.22.3 June 2025

* Filter 'implements' nodes on FullyQualified + add issue test
* Exclude directories from license copy step
* Add spelling to main workflow
* Use `"elazar/flystream": "^0.5.0|^1"`
* Fix spelling
* Filter `performReplacementsInProjectFiles()` to only PHP files
* Add `file_exists()` check in edited `vendor/autoload.php`
* Fix Double slashes when replacing namespace in use keywords inside classes
* Fix Fatal error: Uncaught Error: Failed opening required 'vendor_prefixed'
* Fix Command "include-autoloader" is not defined
* Fix/close Mockery
* Add `extends Composer\Autoload\AutoloadGenerator`
* Don't use dir as file

## 0.22.2 April 2025

* Fix: `psr-0` autoloaders were no longer autoloaded because the directory structure did not match
* Fix: `files` autoloaders failed when not unique (the whole point of this tool)
* Fix: spelling

## 0.22.1 April 2025

* Fix: jsonmapper latest version caused problems with PhpDoc

## 0.22.0 April 2025

* Add: `--info`, `--debug` and `--silent` verbosity levels
* Add: `--dry-run` which runs with `--debug` output but does not write files
* Add: `autoload_aliases.php` file for dev dependencies to load modified classes using their original fqdn
* Fix: relative namespaces
* Fix: allow vendor and target directories to be in parent directory of `composer.json`
* Fix: incorrectly updating call sites
* Dev: major refactor to use `thephpleague/Flysystem` and `elazar/flystream` for file operations
* Dev: print diff code coverage report on PRs
* Dev: skip / speed-up some tests
* Dev: improvements to tests' names and coverage reporting specificity 
* Docs: improve installation instructions in `README.md` 
* CI: Set up problem matcher for PHPUnit

## 0.21.1 January 2025

* Fix: global functions prefixed too liberally when defined as strings
* Add: include changelog in phar

## 0.21.0 January 2025

* Add: prefix global functions

## 0.20.1 December 2024

* Fix: `vendor-prefixed` subdirectories' permissions being copied as 0700 instead of 0755

## 0.20.0 November 2024

* Fix: `Generic<\namespaced\class-type>` not prefixed
* Add `strauss replace` command (e.g. if you fork a project and want to change its namespace)

## 0.19.5 October 2024

* Fix: `use GlobalClass as Alias;` not prefixed
* Add: `.gitattributes` file to exclude dev files from distribution
* CI: Fail releases if `bin/strauss` version number is out of sync
* Tests: Add first tests for `DiscoveredFiles.php`
* Improve `README.md`
* Fix: typos in code

## 0.19.4 October 2024

* Fix: out of sync version number in `bin/strauss`

## 0.19.3 October 2024

* Fix: handle `@` symbol for error suppression
* Fix: handle `preg_replace...` returning `null` in `Licenser`
* Fix: only search for symbols in PHP files

## 0.19.2 June 2024

* Fix: available CLI arguments were overwriting extra.strauss config
* Fix: updating `league/flysystem` changed the default directory permissions

## 0.19.1 April 2024

* Fix: was incorrectly deleting autoload keys from installed.json

## 0.19.0 April 2024

* Fix: check for array before loop
* Fix: filepaths on Windows (still work to do for Windows)
* Update: tidy `bin/strauss`
* Run tests with project classes + with built phar
* Allow `symfony/console` & `symfony/finder` `^7` for Laravel 11 compatibility
* Add: `scripts/createphar.sh`
* Lint: most PhpStan level 7

## 0.18.0 April 2024

* Add: GitHub Action to update bin version number from CHANGELOG.md
* Fix: casting a namespaced class to a string
* Fix: composer dump-autoload error after delete-vendor-files/delete-vendor-packages
* Fix: add missing built-in PHP interfaces to exclude rules
* Fix: Undefined offset when seeing namespace
* Refactoring for clarity and pending issues

## 0.14.0 07-March-2023

* Merge `in-situ` branch (bugs expected)
* Add: `delete_vendor_packages` option (`delete_vendor_files` is maybe deprecated now)
* Add: GPG .phar signing for Phive
* Breaking: Stop excluding `psr/*` from `file_patterns` prefixing
