<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync\Commands;

use Composer\Command\BaseCommand;
use JsonException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('modules:check');
        $this->setDescription('Check if all requirements are in sync.');
        $this->addOption("module-dir", "m", InputOption::VALUE_REQUIRED, "Relative path to module directory.", "modules");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = $this->getComposer()?->getPackage();
        if (!$package) {
            $output->writeln('<error>No package found.</error>');

            return 1;
        }

        $output->writeln('Checking requirements...', OutputInterface::VERBOSITY_VERBOSE);

        $rootDirectory = $this->getApplication()->getInitialWorkingDirectory();
        $rootRequirementTargets = array_map(static fn($require) => $require->getTarget(), $package->getRequires());
        $rootRequirementVersions = array_map(static fn($require) => $require->getConstraint()->getPrettyString(), $package->getRequires());
        $rootRequirements = array_combine($rootRequirementTargets, $rootRequirementVersions);

        $rootReplacementsTargets = array_map(static fn($replace) => $replace->getTarget(), $package->getReplaces());
        $rootReplacementsVersions = array_map(static fn($replace) => $replace->getConstraint()->getPrettyString(), $package->getReplaces());
        $rootReplacements = array_combine($rootReplacementsTargets, $rootReplacementsVersions);

        $output->writeln("Root requirements:", OutputInterface::VERBOSITY_DEBUG);
        foreach ($rootRequirements as $requirement => $version) {
            $output->writeln("\t$requirement: $version", OutputInterface::VERBOSITY_DEBUG);
        }

        $output->writeln("Root replacements:", OutputInterface::VERBOSITY_DEBUG);
        foreach ($rootReplacements as $replacement => $version) {
            $output->writeln("\t$replacement: $version", OutputInterface::VERBOSITY_DEBUG);
        }

        $visitedRootRequirements = array_fill_keys(array_keys($rootRequirements), false);

        $modulesDirectory = $rootDirectory . DIRECTORY_SEPARATOR . $input->getOption('module-dir');
        $output->writeln("Modules directory: $modulesDirectory", OutputInterface::VERBOSITY_VERBOSE);
        if (!is_dir($modulesDirectory)) {
            $output->writeln("<error>Modules directory does not exist: $modulesDirectory</error>");

            return 1;
        }

        $errors = false;
        $modules = array_diff(scandir($modulesDirectory), ['.', '..']);
        foreach ($modules as $module) {
            $moduleDirectory = $modulesDirectory . DIRECTORY_SEPARATOR . $module;
            if (!is_dir($moduleDirectory)) {
                $output->writeln("Skipping module $module because it is not a directory.", OutputInterface::VERBOSITY_DEBUG);

                continue;
            }

            $moduleComposerFile = $moduleDirectory . DIRECTORY_SEPARATOR . 'composer.json';
            if (!is_file($moduleComposerFile)) {
                $output->writeln("Skipping module $module because it doesn't contain a composer.json.", OutputInterface::VERBOSITY_DEBUG);

                continue;
            }

            try {
                $moduleComposer = json_decode(file_get_contents($moduleComposerFile), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $moduleComposer = null;
            }

            if (!$moduleComposer) {
                $output->writeln("Skipping module $module because the composer.json couldn't be parsed.", OutputInterface::VERBOSITY_VERBOSE);

                continue;
            }

            $moduleName = $moduleComposer['name'];
            $moduleRequirements = $moduleComposer['require'] ?? [];

            $output->writeln("<info>Checking module $moduleName...</info>", OutputInterface::VERBOSITY_VERY_VERBOSE);

            foreach ($moduleRequirements as $moduleRequirement => $moduleRequirementVersion) {
                $output->writeln("\tChecking requirement $moduleRequirement@$moduleRequirementVersion...", OutputInterface::VERBOSITY_DEBUG);
                if (array_key_exists($moduleRequirement, $rootRequirements)) {
                    if ($rootRequirements[$moduleRequirement] !== $moduleRequirementVersion) {
                        $output->writeln("<error>Module $moduleName requires $moduleRequirement@$moduleRequirementVersion, but the version is not the same as in the composer.json file ($rootRequirements[$moduleRequirement]).</error>");

                        $errors = true;
                    } else {
                        $output->writeln("\t\tOk. Marked as visited.", OutputInterface::VERBOSITY_DEBUG);

                        $visitedRootRequirements[$moduleRequirement] = true;
                    }
                } else if (array_key_exists($moduleRequirement, $rootReplacements)) {
                    $output->writeln("\t\tOk. Is replaced by {$package->getName()}@$rootReplacements[$moduleRequirement].", OutputInterface::VERBOSITY_DEBUG);
                } else {
                    $output->writeln("<error>Module $moduleName requires $moduleRequirement@$moduleRequirementVersion, but it is not in the composer.json file.</error>");

                    $errors = true;
                }
            }
        }

        if ($errors) {
            $output->writeln('<error>Some requirements are not in sync.</error>');

            return 1;
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $output->writeln('Checking visited status of root requirements:', OutputInterface::VERBOSITY_VERBOSE);
            foreach ($visitedRootRequirements as $rootRequirement => $visited) {
                $output->writeln("$rootRequirement: " . ($visited ? "<info>visited</info>" : "<error>not visited</error>"), OutputInterface::VERBOSITY_VERBOSE);
            }
        } else {
            $unvisitedRequirements = array_keys(array_filter($visitedRootRequirements, static fn($visited) => !$visited));
            if (empty($unvisitedRequirements)) {
                $output->writeln('<info>All requirements are in sync.</info>');
            } else {
                $output->writeln('<warning>Following root requirements were not found in any module and can be removed:</warning>');

                foreach ($unvisitedRequirements as $requirement) {
                    $output->writeln("\t- $requirement");
                }
            }
        }

        return 0;
    }
}
