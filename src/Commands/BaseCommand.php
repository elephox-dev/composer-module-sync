<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync\Commands;

use Composer\Command\BaseCommand as ComposerBaseCommand;
use Elephox\ComposerModuleSync\Module;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BaseCommand extends ComposerBaseCommand
{
    protected function addModulesDirOption(): self
    {
        $this->addOption("modules-dir", "m", InputOption::VALUE_REQUIRED, "Relative path to modules directory.", "modules");

        return $this;
    }

    protected function getModulesDir(InputInterface $input, OutputInterface $output, string &$modulesDirectory): bool
    {
        $rootDirectory = $this->getApplication()->getInitialWorkingDirectory();

        $modulesDirectory = $rootDirectory . DIRECTORY_SEPARATOR . $input->getOption('modules-dir');
        if (is_dir($modulesDirectory)) {
            $modulesDirectory = realpath($modulesDirectory);

            return true;
        }

        $output->writeln("<error>Modules directory does not exist: $modulesDirectory</error>");

        return false;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return Module[]
     */
    protected function getModules(InputInterface $input, OutputInterface $output): array
    {
        $modulesDirectory = "";
        if (!$this->getModulesDir($input, $output, $modulesDirectory)) {
            return [];
        }

        return array_map(
            static fn($module) => new Module($module['name'], $module['path']),
            array_filter(
                array_map(
                    static fn($moduleName) => [
                        'name' => $moduleName,
                        'path' => $modulesDirectory . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . 'composer.json',
                    ],
                    array_diff(scandir($modulesDirectory), ['.', '..'])
                ),
                static fn($module) => file_exists($module['path'])
            )
        );
    }
}
