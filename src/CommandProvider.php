<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync;

use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;

class CommandProvider implements ComposerCommandProvider
{
    public function getCommands(): array
    {
        $baseCommands = [
            new Commands\CheckCommand(),
            new Commands\AddRequireCommand(),
        ];

        $softDependCommands = [];
        if (class_exists('Ergebnis\Composer\Normalize\NormalizePlugin')) {
            $softDependCommands[] = new Commands\NormalizeCommand();
        }

        return $baseCommands + $softDependCommands;
    }
}
