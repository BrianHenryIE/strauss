<?php
/**
 * When strauss is installed via Composer, this will help load the aliases file.
 */

$autoloadAliasesFilepath = __DIR__ . '/../../composer/autoload_aliases.php';
if(file_exists($autoloadAliasesFilepath)) {
    require_once $autoloadAliasesFilepath;
}
unset($autoloadAliasesFilepath);