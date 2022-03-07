<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync\Commands;

use Composer\Question\StrictConfirmationQuestion;
use Elephox\ComposerModuleSync\Module;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class ReleaseCommand extends BaseCommand
{
    private const MAJOR_RELEASE_BRANCH = "main";

    public function configure(): void
    {
        $this->setName('modules:release');
        $this->setDescription('Release a new version for all modules');
        $this->addArgument("type", InputArgument::REQUIRED, "The type of release (major, minor, bugfix, security).");
        $this->addArgument("version", InputArgument::REQUIRED, "The new version.");
        $this->addOption("dry-run", "y", InputOption::VALUE_NONE, "Dry run.");
        $this->addOption("last-tag", "l", InputOption::VALUE_REQUIRED, "Override the last tag.");
        $this->addOption("skip-upmerge", "u", InputOption::VALUE_NONE, "Skip merging releases up into the main branch.");
        $this->addOption("monorepo-name", "r", InputOption::VALUE_REQUIRED, "The name of the monorepo.", "framework");
        $this->addModulesDirOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->git("status --porcelain")) {
            $output->writeln("<error>There are uncommitted changes. Please commit or stash them first.</error>");

            return 1;
        }

        $typeArg = strtolower($input->getArgument("type"));
        if (!in_array($typeArg, ["major", "minor", "bugfix", "security"])) {
            $output->writeln("<error>Invalid release type: '$typeArg'. Allowed are: major, minor, bugfix, security</error>");

            return 1;
        }

        $nextVersionPattern = match($typeArg) {
            'major' => /** @lang RegExp */ "/^(?<major>\d+)$/",
            'minor' => /** @lang RegExp */ "/^(?<major>\d+)\.(?<minor>\d+)$/",
            default => /** @lang RegExp */ "/^(?<major>\d+)\.(?<minor>\d+)\.(?<patch>\d+)$/",
        };

        $versionArg = $input->getArgument("version");
        if (!preg_match($nextVersionPattern, $versionArg, $versionParams)) {
            $output->writeln("<error>Invalid version: '$versionArg'. Must match $nextVersionPattern</error>");

            return 1;
        }
        ['major' => $releaseMajor, 'minor' => $releaseMinor, 'patch' => $releasePatch] = $versionParams + [ 'minor' => null, 'patch' => null ];

        $branchPattern = match($typeArg) {
            'major' => /** @lang RegExp */ "/^(?<major>" . self::MAJOR_RELEASE_BRANCH . ")$/",
            'minor' => /** @lang RegExp */ "/^(?<major>\d+)\.(?<minor>x)$/",
            default => /** @lang RegExp */ "/^(?<major>\d+)\.(?<minor>\d+)\.(?<patch>x)$/",
        };

        $currentBranch = $this->git("rev-parse --abbrev-ref HEAD");
        if (!preg_match($branchPattern, $currentBranch, $branchParams)) {
            $output->writeln("<error>Current branch '$currentBranch' does not match expected pattern $branchPattern</error>");

            return 1;
        }
        ['major' => $branchMajor, 'minor' => $branchMinor] = $branchParams + ['minor' => null];

        $output->writeln("<info>Current branch: $currentBranch</info>");

        if ($this->git("rev-parse HEAD") !== $this->git("rev-parse origin/$currentBranch")) {
            $output->writeln("<error>Your branch is not in sync with origin. Did you forget to push your changes?</error>");

            return 1;
        }

        if ($lastTag = $input->getOption('last-tag')) {
            if (!preg_match("/^(?<major>\d+)\.(?<minor>\d+)\.(?<patch>\d+)$/", $lastTag, $lastTagParams)) {
                $output->writeln("<error>Invalid last tag: '$lastTag'. Must have the form: x.x.x</error>");

                return 1;
            }

            $tagCommit = "overridden";
        } else {
            $filter = "v" . match ($typeArg) {
                'major' => "*",
                'minor' => $releaseMajor . ".*",
                default => $releaseMajor . "." . $releaseMinor . ".*",
            };

            $lastTagsResult = $this->git("tag --list --sort=-version:refname $filter");
            if (!is_string($lastTagsResult)) {
                $output->writeln("<error>Failed to get list of tags. Aborting.</error>");

                return 1;
            }

            $lastTags = array_map(static fn(string $line): string => trim($line, 'v'), array_slice(explode("\n", $lastTagsResult), 0, 10));
            $lastTag = reset($lastTags);
            while (!preg_match("/^(?<major>\d+)\.(?<minor>\d+)\.(?<patch>\d+)$/", $lastTag, $lastTagParams)) {
                $output->writeln("<error>Last tag in current branch ($lastTag) doesn't match expected pattern (x.x.x).</error>");

                if (!$input->isInteractive()) {
                    $output->writeln("<error>Input is not interactive and a valid tag needs to be selected. Aborting.</error>");

                    return 1;
                }

                $lastTag = $this->getHelper('question')->ask($input, $output, new ChoiceQuestion("Please select the last tag before the new version:", $lastTags, reset($lastTags)));
            }

            $tagCommit = $this->git("rev-list -1 v$lastTag");
        }
        ['major' => $lastTagMajor, 'minor' => $lastTagMinor, 'patch' => $lastTagPatch] = $lastTagParams;

        $output->writeln("<info>Last tag: $lastTag ($tagCommit)</info>");

        if ($typeArg === 'major') {
            $requiredBranchMajor = self::MAJOR_RELEASE_BRANCH;
            $requiredBranchMinor = null;

            $nextMajor = (int)$lastTagMajor + 1;
            $nextMinor = 0;
            $nextPatch = 0;
        } else if ($typeArg === 'minor') {
            $requiredBranchMajor = $lastTagMajor;
            $requiredBranchMinor = "x";

            $nextMajor = (int)$lastTagMajor;
            $nextMinor = (int)$lastTagMinor + 1;
            $nextPatch = 0;
        } else {
            $requiredBranchMajor = $lastTagMajor;
            $requiredBranchMinor = $lastTagMinor;

            $nextMajor = (int)$lastTagMajor;
            $nextMinor = (int)$lastTagMinor;
            $nextPatch = (int)$lastTagPatch + 1;
        }

        if ($branchMajor !== $requiredBranchMajor) {
            $output->writeln("<error>Branch major version ($branchMajor) does not match required previous tag major version ($requiredBranchMajor). Aborting.</error>");

            return 1;
        }

        if ($branchMinor !== $requiredBranchMinor) {
            $output->writeln("<error>Branch minor version ($branchMinor) does not match required previous tag minor version ($requiredBranchMinor). Aborting.</error>");

            return 1;
        }

        if ($nextMajor !== (int)$releaseMajor) {
            if ($typeArg === 'major') {
                $output->writeln("<error>A major release MUST increase the major version number by 1. Got: '$releaseMajor', expected: '$nextMajor'</error>");
            } else {
                $output->writeln("<error>A minor/patch release MUST NOT change the major version number. Got: '$releaseMajor', expected: '$nextMajor'</error>");
            }
            return 1;
        }

        if ($nextMinor !== (int)$releaseMinor) {
            if ($typeArg === 'major') {
                $output->writeln("<error>A major release MUST reset the minor version number to 0. Got: '$releaseMinor'</error>");
            } else if ($typeArg === 'minor') {
                $output->writeln("<error>A minor release MUST increase the minor version number by 1. Got: '$releaseMinor', expected: '$nextMinor'</error>");
            } else {
                $output->writeln("<error>A bugfix/security release MUST NOT change the minor version number. Got: '$releaseMinor', expected: '$nextMinor'</error>");
            }

            return 1;
        }

        if ($nextPatch !== (int)$releasePatch) {
            if ($typeArg === 'major') {
                $output->writeln("<error>A major/minor release MUST reset the patch version number to 0. Got: '$releasePatch'</error>");
            } else {
                $output->writeln("<error>A bugfix/security release MUST increase the patch version number by 1. Got: '$releasePatch', expected: '$nextPatch'</error>");
            }
            return 1;
        }

        $output->writeln("<info>Next version: $nextMajor.$nextMinor.$nextPatch</info>");

        if (!$modules = $this->getModules($input, $output)) {
            return 1;
        }

        $workingDirectory = sys_get_temp_dir() . '/composer-module-sync/' . uniqid('', true);
        if (!mkdir($workingDirectory, 0777, true) && !is_dir($workingDirectory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $workingDirectory));
        }
        $owd = getcwd();
        $this->cd($workingDirectory, $output);

        $extra = $this->getComposer()?->getPackage()->getExtra();
        if (
            !$extra ||
            !array_key_exists('module-sync', $extra) ||
            !array_key_exists('repository-base', $extra['module-sync'])
        ) {
            $output->writeln("<error>Missing repository base. Please set the 'extra.module-sync.repository-base' option in your composer.json.</error>");

            return 1;
        }
        $repositoryBase = $extra['module-sync']['repository-base'];
        $output->writeln("<info>Using repository base: $repositoryBase</info>");

        $armed = !$input->getOption('dry-run');
        $monoRepoName = $input->getOption("monorepo-name");
        $monoRepoModule = new Module($monoRepoName, $this->getApplication()->getInitialWorkingDirectory() . DIRECTORY_SEPARATOR . "composer.json");
        $releaseTag = match ($typeArg) {
            'major' => "v$releaseMajor.0.0",
            'minor' => "v$releaseMajor.$releaseMinor.0",
            default => "v$releaseMajor.$releaseMinor.$releasePatch"
        };
        array_unshift($modules, $monoRepoModule);

        $output->writeln("<info>About to release $releaseTag for " . count($modules) . " modules (including monorepo).</info>");
        if (!$armed) {
            $output->writeln("<warning>Dry run. No actual changes will be made.</warning>");
        }

        if (!$this->getHelper('question')->ask($input, $output, new StrictConfirmationQuestion("<question>Continue [y/N]?</question>", false))) {
            $output->writeln("<error>Aborting.</error>");

            return 1;
        }

        foreach ($modules as $module) {
            $output->writeln("====================================== $module->name");

            $normalizedModuleName = strtolower($module->name);
            $moduleRepositoryUrl = $repositoryBase . $normalizedModuleName;
            $moduleDirectory = $workingDirectory . DIRECTORY_SEPARATOR . $normalizedModuleName;

            // check out module repo
            $this->git("clone $moduleRepositoryUrl $normalizedModuleName", $output, $armed);
            if (!$armed && !mkdir($moduleDirectory, 0777, true) && !is_dir($moduleDirectory)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $moduleDirectory));
            }
            $this->cd($moduleDirectory, $output);

            if ($typeArg === 'major') {
                // tag main branch
                $output->writeln("<info>Tagging major release branch (" . self::MAJOR_RELEASE_BRANCH . ")</info>", OutputInterface::VERBOSITY_VERBOSE);
                $this->git("checkout " . self::MAJOR_RELEASE_BRANCH, $output, $armed);
                $this->git("tag $releaseTag", $output, $armed);

                // create major.x branch
                $output->writeln("<info>Creating new minor branch ($releaseMajor.x)</info>", OutputInterface::VERBOSITY_VERBOSE);
                $this->git("branch $releaseMajor.x", $output, $armed);

                // create major.0.x branch
                $output->writeln("<info>Creating new patch branch ($releaseMajor.0.x)</info>", OutputInterface::VERBOSITY_VERBOSE);
                $this->git("branch $releaseMajor.0.x", $output, $armed);
            } else if ($typeArg === 'minor') {
                // tag major.x branch
                $output->writeln("<info>Tagging major branch ($releaseMajor.x)</info>", OutputInterface::VERBOSITY_VERBOSE);
                $this->git("checkout $releaseMajor.x", $output, $armed);
                $this->git("tag $releaseTag", $output, $armed);

                // create major.minor.x branch
                $output->writeln("<info>Creating new patch branch ($releaseMajor.$releaseMinor.x)</info>", OutputInterface::VERBOSITY_VERBOSE);
                $this->git("branch $releaseMajor.$releaseMinor.x", $output, $armed);

                if (!$input->getOption("skip-upmerge")) {
                    // merge in main branch
                    $output->writeln("<info>Up-merging in to major release branch (" . self::MAJOR_RELEASE_BRANCH . ")</info>", OutputInterface::VERBOSITY_VERBOSE);
                    $this->git("checkout " . self::MAJOR_RELEASE_BRANCH, $output, $armed);
                    $this->git("merge $releaseMajor.x", $output, $armed);
                }
            } else {
                // tag major.minor.x branch
                $output->writeln("<info>Tagging minor branch ($releaseMajor.$releaseMinor.x)</info>", OutputInterface::VERBOSITY_VERBOSE);
                $this->git("checkout $releaseMajor.$releaseMinor.x", $output, $armed);
                $this->git("tag $releaseTag", $output, $armed);

                if (!$input->getOption("skip-upmerge")) {
                    // merge in major.x branch
                    $output->writeln("<info>Up-merging in to minor release branch ($releaseMajor.x)</info>", OutputInterface::VERBOSITY_VERBOSE);
                    $this->git("checkout $releaseMajor.x", $output, $armed);
                    $this->git("merge $releaseMajor.$releaseMinor.x", $output, $armed);

                    // merge in main branch
                    $output->writeln("<info>Up-merging in to major release branch (" . self::MAJOR_RELEASE_BRANCH . ")</info>", OutputInterface::VERBOSITY_VERBOSE);
                    $this->git("checkout " . self::MAJOR_RELEASE_BRANCH, $output, $armed);
                    $this->git("merge $releaseMajor.x", $output, $armed);
                }
            }

            $output->writeln("<info>Pushing changes to remote repository and creating new release ($moduleRepositoryUrl)</info>", OutputInterface::VERBOSITY_VERBOSE);
            $this->git("push --all", $output, $armed);

            $this->cd($workingDirectory, $output);
        }

        // create GitHub release
        $output->writeln("<info>Please enter release notes for $releaseTag. The token '%n' will be replaced by \\n</info>");
        $releaseNotes = $this->getHelper('question')->ask($input, $output, new Question("<question>Release Notes:</question>\n"));
        $releaseNotes = str_replace('%n', "\n", $releaseNotes);
        $notesPath = $workingDirectory . DIRECTORY_SEPARATOR . "release-notes-$releaseTag.md";
        file_put_contents($notesPath, $releaseNotes);
        $this->shell(sprintf("gh release create %s --generate-notes --title %s --target %s --notes-file %s", $releaseTag, $releaseTag, "$releaseMajor.$releaseMinor.x", $notesPath), $output, $armed);

        $output->writeln("======================================");
        $output->writeln("<info>Release $releaseTag published for all modules</info>");

        if ($owd) {
            $this->cd($owd, $output);

            // major.minor.x auschecken
            $this->git("checkout $releaseMajor.$releaseMinor.x", execute: $armed);
        }

        $output->writeln("Cleaning up...");
        $this->rmdirRecursive(dirname($workingDirectory), $output);

        return 0;
    }

    private function git(string $command, ?OutputInterface $output = null, bool $execute = true, false|null|string $default = null): false|null|string
    {
        return $this->shell("git $command", $output, $execute, $default);
    }

    private function cd(string $dir, ?OutputInterface $output = null): void
    {
        $output?->writeln("$ cd $dir", OutputInterface::VERBOSITY_DEBUG);
        chdir($dir);
    }

    private function shell(string $command, ?OutputInterface $output = null, bool $execute = true, false|null|string $default = null): false|null|string
    {
        $output?->writeln("$ $command", OutputInterface::VERBOSITY_DEBUG);

        if ($execute) {
            $result = shell_exec($command);
        } else {
            usleep(random_int(10, 100) * 1000);

            return $default;
        }

        if (is_string($result)) {
            return trim($result);
        }

        return $result;
    }

    private function rmdirRecursive(string $dir, ?OutputInterface $output = null): void
    {
        if (PHP_OS === "WINNT") {
            $this->shell(sprintf("rd /s /q %s", escapeshellarg($dir)), $output);
        } else {
            $this->shell(sprintf("rm -rf %s", escapeshellarg($dir)), $output);
        }
    }
}
