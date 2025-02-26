<?php
/**
 * When strauss is installed via Composer, this will help load the aliases file.
 *
 * When `composer install --no-dev` is run, Strauss won't be installed and this file won't exist to load
 * `autoload_aliases.php`. This is good – we don't want to load the aliases file in production or we end up
 * fixing the namespace collision issue for ourselves but preserving it for other packages.
 *
 * This file tries to read the project composer.json file to find the target directory. If it can't find it, it
 * assumes the default "vendor-prefixed".
 *
 * @package brianhenryie/strauss
 */

$autoloadAliasesFilepath = realpath(__DIR__ . '/../../composer/autoload_aliases.php');
if (file_exists($autoloadAliasesFilepath)) {
    $targetDirectory = function () {
        $composerJsonFilepath = realpath(__DIR__ . '/../../../composer.json');
        if (file_exists($composerJsonFilepath)) {
            $composerJson = json_decode(file_get_contents($composerJsonFilepath), true);
            if (isset($composerJson['extra']['strauss']['target_directory'])
                &&
                is_dir(realpath(__DIR__ . '/../../../'.$composerJson['extra']['strauss']['target_directory']))
            ) {
                return $composerJson['extra']['strauss']['target_directory'];
            }
        }
        return null;
    };

    // Check it's not trying to load the vendor/autoload.php file that is currently being loaded.
    $autoloadTargetDirFilepath = $targetDirectory() ?? realpath(__DIR__ . "/../../../vendor-prefixed/autoload.php");
    if ($autoloadTargetDirFilepath !== realpath(__DIR__ . '/../../autoload.php') && file_exists($autoloadTargetDirFilepath)) {
        require_once $autoloadTargetDirFilepath;
    }
    unset($autoloadTargetDirFilepath);

    require_once $autoloadAliasesFilepath;
}
unset($autoloadAliasesFilepath,);
