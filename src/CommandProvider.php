<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync;

use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;

class CommandProvider implements ComposerCommandProvider
{
    public function getCommands(): array
    {
        return [
            new Commands\CheckCommand(),
            new Commands\AddRequireCommand(),
        ];
    }
}
