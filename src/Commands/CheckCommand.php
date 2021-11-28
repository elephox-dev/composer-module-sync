<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync\Commands;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('check');
        $this->setDescription('Check if all requirements are in sync.');
        $this->addOption("module-dir", "m", InputOption::VALUE_REQUIRED, "Relative path to module directory.", "modules");
    }

    /**
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = $this->getComposer()?->getPackage();
        if (!$package) {
            $output->writeln('<error>No package found.</error>');

            return 1;
        }

        $output->writeln('<info>Checking requirements...</info>', OutputInterface::VERBOSITY_VERBOSE);

        $rootRequirements = array_map(static fn($require) => $require->getTarget(), $package->getRequires());
        $rootReplacements = array_map(static fn($replace) => $replace->getTarget(), $package->getReplaces());

        $visitedRootRequirements = [];

        $rootDirectory = $this->getApplication()->getInitialWorkingDirectory();
        $modulesDirectory = $rootDirectory . DIRECTORY_SEPARATOR . $input->getOption('module-path');

        $output->writeln("<info>Modules directory: $modulesDirectory</info>", OutputInterface::VERBOSITY_VERY_VERBOSE);
        if (!is_dir($modulesDirectory)) {
            $output->writeln("<error>Modules directory does not exist.</error>");

            return 1;
        }

        $errors = false;
        $modules = array_diff(scandir($modulesDirectory), ['.', '..']);
        foreach ($modules as $module) {
            $moduleDirectory = $modulesDirectory . DIRECTORY_SEPARATOR . $module;
            if (!is_dir($moduleDirectory)) {
                continue;
            }

            $moduleComposerFile = $moduleDirectory . DIRECTORY_SEPARATOR . 'composer.json';
            if (!is_file($moduleComposerFile)) {
                continue;
            }

            $moduleComposer = json_decode(file_get_contents($moduleComposerFile), true, flags: JSON_THROW_ON_ERROR);
            if (!$moduleComposer) {
                continue;
            }

            $moduleName = $moduleComposer['name'];

            $output->writeln("<info>Checking module $moduleName...</info>", OutputInterface::VERBOSITY_VERY_VERBOSE);

            $moduleRequirements = $moduleComposer['require'] ?? [];

            foreach ($moduleRequirements as $moduleRequirement => $moduleRequirementVersion) {
                if (array_key_exists($moduleRequirement, $rootRequirements)) {
                    if ($rootRequirements[$moduleRequirement] !== $moduleRequirementVersion) {
                        $output->writeln("<error>Module $moduleName requires $moduleRequirement@$moduleRequirementVersion, but the version is not the same as in the composer.json file.</error>");

                        $errors = true;
                    } else {
                        $visitedRootRequirements[$moduleRequirement] = true;
                    }
                } else if (!array_key_exists($moduleRequirement, $rootReplacements)) {
                    $output->writeln("<error>Module $moduleName requires $moduleRequirement@$moduleRequirementVersion, but it is not in the composer.json file.</error>");

                    $errors = true;
                }
            }
        }

        if ($errors) {
            $output->writeln('<error>Some requirements are not in sync.</error>');

            return 1;
        }

        $unvisitedRequirements = array_filter($visitedRootRequirements, static fn ($visited) => !$visited);
        if (empty($unvisitedRequirements)) {
            $output->writeln('<info>All requirements are in sync.</info>');
        } else {

            $output->writeln('<info>Following root requirements were not found in any module and can be removed:</info>');

            foreach (array_keys($unvisitedRequirements) as $requirement) {
                $output->writeln("<info>  - $requirement</info>");
            }
        }

        return 0;
    }
}
