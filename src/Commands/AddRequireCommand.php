<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;

class AddRequireCommand extends BaseCommand
{
    public function configure(): void
    {
        $this->setName('modules:require');
        $this->setDescription('Add a package to the composer.json of one or more modules and to the main composer.json');
        $this->addArgument("requirement", InputArgument::REQUIRED, "The requirement to add.");
        $this->addArgument("version", InputArgument::OPTIONAL, "The version of the requirement to add.", "*");
        $this->addArgument("modules", InputArgument::OPTIONAL | InputArgument::IS_ARRAY, "The module name(s) to add the package to.", []);
        $this->addModulesDirOption();
        $this->addOption("no-main-composer", "u", description: "Whether to not add the package to the main composer.json file.");
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

        $requirement = $input->getArgument("requirement");
        $version = $input->getArgument("version");

        if (!$input->getOption("no-main-composer")) {
            $this->getApplication()->doRun(new StringInput("require $requirement $version"), $output);
        }

        foreach ($modules as $module) {
            $module->addRequirement($requirement, $version);
        }

        return 0;
    }
}
