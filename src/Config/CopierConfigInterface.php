<?php

namespace BrianHenryIE\Strauss\Config;

interface CopierConfigInterface
{

    public function getProjectDirectory(): string;
    public function getTargetDirectory(): string;
}
