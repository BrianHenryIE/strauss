<?php
/**
 * The configuration needed by the step which copies the discovered files into the target directory.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Config;

/**
 * Implemented by {@see \BrianHenryIE\Strauss\Composer\Extra\StraussConfig} and consumed by
 * {@see \BrianHenryIE\Strauss\Pipeline\Copier}, which only needs the absolute target directory, creating it if absent.
 */
interface CopierConfigInterface
{

    public function getAbsoluteTargetDirectory(): string;
}
