<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync\Commands;

use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('modules:check');
        $this->setDescription('Check if all requirements are in sync.');
        $this->addModulesDirOption();
        $this->addOption('namespaces');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checkNamespaces = (bool)$input->getOption('namespaces');

        $package = $this->getComposer()?->getPackage();
        if (!$package) {
            $output->writeln('<error>No package found.</error>');

            return 1;
        }

        $output->writeln('Checking requirements...', OutputInterface::VERBOSITY_VERBOSE);

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

        $modulesDirectory = "";
        if (!$this->getModulesDir($input, $output, $modulesDirectory)) {
            return 1;
        }

        $errors = false;
        $modules = $this->getModules($input, $output);
        foreach ($modules as $module) {
            try {
                $moduleComposer = $module->getComposerJson();
            } catch (JsonException) {
                $moduleComposer = null;
            }

            if (!$moduleComposer) {
                $output->writeln("Skipping module $module->name because the composer.json couldn't be parsed.", OutputInterface::VERBOSITY_VERBOSE);

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

            if ($checkNamespaces) {
                $output->writeln("\tChecking namespaces...", OutputInterface::VERBOSITY_VERBOSE);

                $dependenciesByCode = [];
                $recursiveIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($module->composerJsonPath), FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO));
                /**
                 * @var \SplFileInfo $fileInfo
                 */
                foreach ($recursiveIterator as $fileInfo) {
                    if ($fileInfo->getExtension() !== "php") {
                        continue;
                    }

                    $contents = file_get_contents($fileInfo->getRealPath());
                    preg_match_all('/use Elephox\\\\([^\\\\]*)/im', $contents, $matches);
                    foreach ($matches[1] as $elephoxNamespace) {
                        $dependenciesByCode[] = "elephox/" . strtolower($elephoxNamespace);
                    }
                }

                $ownNamespace = "elephox/" . strtolower($module->name);
                $dependenciesByCode = array_unique(array_filter($dependenciesByCode, static fn ($d) => $d !== $ownNamespace));
                $moduleRequirementNames = array_keys($moduleRequirements);
                $usedRequirements = array_fill_keys($moduleRequirementNames, false);

                $output->writeln(sprintf("\t%d internal dependencies found.", count($dependenciesByCode)), OutputInterface::VERBOSITY_VERBOSE);
                foreach ($dependenciesByCode as $dependency) {
                    $output->writeln("\t\t- " . $dependency, OutputInterface::VERBOSITY_VERY_VERBOSE);
                    if (!in_array($dependency, $moduleRequirementNames, true)) {
                        $output->writeln("<error>Module '$module->name' uses namespaces of '$dependency' but the requirement in composer.json is missing.</error>");

                        $errors = true;
                    }

                    $usedRequirements[$dependency] = true;
                }

                $unusedRequirements = array_keys(array_filter($usedRequirements, static fn($visited) => !$visited));
                if (empty($unusedRequirements)) {
                    $output->writeln("\tAll internal requirements are in sync.", OutputInterface::VERBOSITY_VERBOSE);
                } else {
                    $output->writeln('<warning>Following module requirements were not used and can be removed:</warning>');

                    foreach ($unusedRequirements as $requirement) {
                        $output->writeln("\t\t- $requirement");
                    }
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
