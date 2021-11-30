<?php
declare(strict_types=1);

namespace Elephox\ComposerModuleSync;

class Module
{
    public function __construct(
        public readonly string $name,
        public readonly string $composerJsonPath,
    )
    {
    }

    public function getComposerJson(): array
    {
        return json_decode(file_get_contents($this->composerJsonPath), true, flags: JSON_THROW_ON_ERROR);
    }

    public function putComposerJson(array $composerJson): void
    {
        file_put_contents($this->composerJsonPath, json_encode($composerJson, flags: JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function addRequirement(string $requirement, string $version): void
    {
        $composerJson = $this->getComposerJson();
        $composerJson['require'][$requirement] = $version;
        $this->putComposerJson($composerJson);
    }

    public function removeRequirement(string $requirement): void
    {
        $composerJson = $this->getComposerJson();
        unset($composerJson['require'][$requirement]);
        $this->putComposerJson($composerJson);
    }

    public function updateRequirement(string $requirement, string $version): void
    {
        $composerJson = $this->getComposerJson();
        $composerJson['require'][$requirement] = $version;
        $this->putComposerJson($composerJson);
    }
}
