<?php

declare(strict_types=1);

namespace ALDIDigitalServices\ComposerPackageDevelopmentToolset;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Exception;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    private readonly Composer $composer;

    private readonly IOInterface $io;

    private readonly string $workingDirectory;

    private readonly Filesystem $filesystem;

    private ?array $packagePathsByNameCache = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->workingDirectory = getcwd();
        $this->filesystem = new Filesystem();
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::PRE_INSTALL_CMD => 'removePackageVendorDirectories',
            ScriptEvents::PRE_UPDATE_CMD => 'removePackageVendorDirectories',
            ScriptEvents::POST_INSTALL_CMD => 'installLocalPackages',
            ScriptEvents::POST_UPDATE_CMD => 'installLocalPackages',
        ];
    }

    public function removePackageVendorDirectories(): void
    {
        $packagePathsByName = $this->getPackagePathsByName();
        $vendorPath = $this->composer->getConfig()->get('vendor-dir');

        foreach ($packagePathsByName as $packageName => $packagePath) {
            $packageVendorPath = "$vendorPath/$packageName";

            if (is_link($packageVendorPath)) {
                unlink($packageVendorPath);
            }
        }
    }

    public function installLocalPackages(): void
    {
        $packagePathsByName = $this->getPackagePathsByName();

        if (count($packagePathsByName) === 0) {
            return;
        }

        $composerJsonContents = $this->filesystem->getContents("$this->workingDirectory/composer.json");
        $composerLockContents = $this->filesystem->getContents("$this->workingDirectory/composer.lock");
        $composerJson = json_decode($composerJsonContents, associative: false, flags: JSON_THROW_ON_ERROR);

        try {
            foreach ($packagePathsByName as $packageName => $packagePath) {
                $this->addPackageRepository($composerJson, $packagePath);
                $this->setPackageVersion($composerJson, $packageName);
            }

            $this->writeComposerJson($composerJson);
            $this->updatePackages();
        } finally {
            $this->filesystem->setContents("$this->workingDirectory/composer.json", $composerJsonContents);
            $this->filesystem->setContents("$this->workingDirectory/composer.lock", $composerLockContents);
        }
    }

    private function getPackagePathsByName(): array
    {
        if ($this->packagePathsByNameCache === null) {
            $extra = $this->composer->getPackage()->getExtra();
            $packageDir = rtrim($extra['composer-package-development-toolset']['package-dir'] ?? 'dev-packages', '/');
            $composerJsonPaths = glob("$this->workingDirectory/$packageDir/*/composer.json");

            if ($composerJsonPaths === false) {
                throw new Exception('Could not search for packages');
            }

            $this->packagePathsByNameCache = [];

            foreach ($composerJsonPaths as $composerJsonPath) {
                $composerJsonContents = $this->filesystem->getContents($composerJsonPath);
                $composerJson = json_decode($composerJsonContents, associative: false, flags: JSON_THROW_ON_ERROR);
                $name = $composerJson->name ?? throw new Exception("$composerJsonPath has no name attribute");
                // phpcs:ignore Squiz.PHP.NonExecutableCode.Unreachable -- https://github.com/squizlabs/PHP_CodeSniffer/issues/2857
                $this->packagePathsByNameCache[$name] = preg_replace('|/composer.json$|', '', $composerJsonPath);
            }
        }

        return $this->packagePathsByNameCache;
    }

    private function addPackageRepository(object $composerJson, string $packagePath): void
    {
        if (!property_exists($composerJson, 'repositories')) {
            $composerJson->repositories = [];
        }

        array_unshift($composerJson->repositories, (object)[
            'type' => 'path',
            'url' => preg_replace("|^$this->workingDirectory/|", '', $packagePath),
            'options' => [
                'symlink' => true,
            ],
        ]);
    }

    private function setPackageVersion(object $composerJson, string $packageName): void
    {
        if (!property_exists($composerJson, 'require')) {
            $composerJson->require = (object)[];
        }

        $composerJson->require->$packageName = '@dev';
    }

    private function writeComposerJson(object $composerJson): void
    {
        $composerJsonContents = json_encode(
            $composerJson,
            flags: JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );

        $this->filesystem->setContents("$this->workingDirectory/composer.json", $composerJsonContents);
    }

    private function updatePackages(): void
    {
        $packageNameList = implode(' ', array_keys($this->getPackagePathsByName()));

        $this->io->writeError("Linking dev packages: <info>$packageNameList</info>");

        $command = implode(' ', [
            'composer',
            '--no-plugins',
            '--no-scripts',
            "--working-dir=$this->workingDirectory",
            'update',
            '--no-audit',
            $packageNameList,
        ]);

        if ($this->composer->getLoop()->getProcessExecutor()->execute($command, $out) !== 0) {
            $this->io->error(
                'Could not link dev packages:' . PHP_EOL .
                $this->composer->getLoop()->getProcessExecutor()->getErrorOutput(),
            );
        }
    }
}
