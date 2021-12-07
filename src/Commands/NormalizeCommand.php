<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;

class NormalizeCommand extends BaseCommand
{
    public function configure(): void
    {
        $this->setName('modules:normalize');
        $this->setDescription('Normalize the composer.json of one or more modules and the main composer.json');
        $this->addArgument("modules", InputArgument::OPTIONAL | InputArgument::IS_ARRAY, "The module name(s) to normalize.", []);
        $this->addModulesDirOption();
        $this->addOption("no-main-composer", "u", description: "Whether not to normalize the main composer.json file.");
    }

    /**
     * @throws \Seld\JsonLint\ParsingException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modules = $this->getModules($input, $output);
        $selectedModules = $input->getArgument("modules");
        if (!empty($selectedModules)) {
            $modules = array_filter($modules, static fn($module) => in_array($module->name, $selectedModules, true));
        }

        if (!$input->getOption("no-main-composer")) {
            $this->getApplication()->doRun(new StringInput("normalize"), $output);
        }

        foreach ($modules as $module) {
            $this->getApplication()->doRun(new StringInput("normalize $module->composerJsonPath"), $output);
        }

        return 0;
    }
}
