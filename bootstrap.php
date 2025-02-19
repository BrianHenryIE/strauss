<?php
/**
 * When strauss is installed via Composer, this will help load the aliases file.
 */

$autoloadAliasesFilepath = realpath(__DIR__ . '/../../composer/autoload_aliases.php');
if (file_exists($autoloadAliasesFilepath)) {
    // TODO: This will only work for default configuration; read the composer.json file to determine the target directory.
    // Check it's not trying to load the vendor/autoload.php file that is currently being loaded.
    $autoloadTargetDirFilepath = realpath(__DIR__ . '/../../../vendor-prefixed/autoload.php');
    if ($autoloadTargetDirFilepath !== realpath(__DIR__ . '/../../autoload.php') && file_exists($autoloadTargetDirFilepath)) {
        require_once $autoloadTargetDirFilepath;
    }
    unset($autoloadTargetDirFilepath);

    require_once $autoloadAliasesFilepath;
}
unset($autoloadAliasesFilepath);
