<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync;

use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;
use Ergebnis\Composer\Normalize\NormalizePlugin;

class CommandProvider implements ComposerCommandProvider
{
    public function getCommands(): array
    {
        $commands = [
            new Commands\CheckCommand(),
            new Commands\RequireCommand(),
            new Commands\ReleaseCommand(),
        ];

        if (class_exists(NormalizePlugin::class)) {
            $commands[] = new Commands\NormalizeCommand();
        }

        return $commands;
    }
}
