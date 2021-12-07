<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class NormalizeCommand extends BaseCommand
{
    public function configure(): void
    {
        $this->setName('modules:normalize');
        $this->setDescription('Normalize the composer.json of one or more modules and the main composer.json');
        $this->addArgument("modules", InputArgument::OPTIONAL | InputArgument::IS_ARRAY, "The module name(s) to normalize.", []);
        $this->addModulesDirOption();
        $this->addOption("no-main-composer", description: "Whether not to normalize the main composer.json file.");
        $this->addOption("dry-run", description: "Show the results of normalizing, but do not modify any files.");
        $this->addOption("diff", description: "Show the results of normalizing.");
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

        $args = "";
        if ($input->getOption("dry-run")) {
            $args .= " --dry-run";
        }

        if ($input->getOption("diff")) {
            $args .= " --diff";
        }

        if (!$input->getOption("no-main-composer")) {
            $output->writeln("Normalizing main composer.json...");

            $this->getApplication()->doRun(new StringInput("normalize$args"), $output);
        }

        foreach ($modules as $module) {
            $output->writeln("Normalizing $module->name...");
            $path = $module->composerJsonPath;

            // ergebnis/composer-normalize doesn't support raw windows paths, so we need to escape them
            if (PHP_OS_FAMILY === "Windows") {
                $path = str_replace("\\", "\\\\", $path);
            }

            $this->getApplication()->doRun(new StringInput("normalize$args -- $path"), $output);
        }

        return 0;
    }
}
