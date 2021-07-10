<?php

namespace SV\AlertImprovements\Repository;

use XF\AddOn\AddOn;
use XF\Mvc\Entity\Repository;
use XF\Notifier\AbstractNotifier;
use XF\Util\File as FileUtil;

class NotifierExtender extends Repository
{
    protected $mountPoint = 'code-cache://';
    protected $codeCachePath = 'svAlertCache'; // warning this entire directory can and will be deleted!
    protected $extensionsFile = 'svAlertCache/extensions.php';

    protected function logException($e)
    {
        // Suppress error reporting, as it is likely to be a transient issue during add-on install/upgrade that can be safely ignored
        if (\XF::$debugMode)
        {
            \XF::logException($e, false, 'Suppressed:');
        }
    }

    public function extendNotifiers(bool $generateExtensions)
    {
        if (\XF::$versionId < 2020000)
        {
            if ($generateExtensions)
            {
                $this->deleteNotifierExtensions();
            }

            return;
        }

        if ($generateExtensions)
        {
            $this->rebuildNotifierExtensions();
        }

        $rawExtensionFile = FileUtil::getCodeCachePath() . '/' . $this->extensionsFile;
        try
        {
            /** @noinspection PhpIncludeInspection */
            $result = @include($rawExtensionFile);
        }
        catch(\Throwable $e)
        {
            $this->logException($e);
            $result = false;
        }
        if ($result !== true)
        {
            $this->rebuildNotifierExtensions();
            if (!@\file_exists($rawExtensionFile))
            {
                // sanity check, file permission issues?
                return;
            }
        }
    }

    public function deleteNotifierExtensions()
    {
        try
        {
            FileUtil::deleteAbstractedDirectory($this->mountPoint . $this->codeCachePath);
        }
        catch(\Throwable $e)
        {
            $this->logException($e);
        }
    }

    public function rebuildNotifierExtensions()
    {
        // There is a race condition between deleting the directory and recreating it.
        // However XenForo has infrastructure to disable an add-on during the 'processing' stage.
        // This skips entity preSave/PostSave as to avoid compatibility issues with installers which modify is_processing flag handling

        $addonManager = $this->app()->addOnDataManager();
        /** @var \XF\Entity\AddOn $addon */
        $addon = \XF::em()->find('XF:AddOn', 'SV/AlertImprovements');
        $wasProcessing = $addon->is_processing;

        $addon->fastUpdate('is_processing', true);
        $addonManager->triggerRebuildProcessingChange($addon);
        try
        {
            try
            {
                $this->deleteNotifierExtensions();
                $this->writeExtensionFiles();
            }
            catch(\Throwable $e)
            {
                $this->logException($e);
            }
        }
        finally
        {
            if (!$wasProcessing)
            {
                $addon->fastUpdate('is_processing', false);
                $addonManager->triggerRebuildProcessingChange($addon);
            }
        }
    }

    protected function writeExtensionFiles()
    {
        $grouped = $this->getGroupedExtensions();

        foreach ($grouped as $namespace => $extensions)
        {
            foreach ($extensions as $extension)
            {
                $parts = \explode('\\', $namespace);
                \array_shift($parts);

                $classFile = $this->mountPoint . $this->codeCachePath . '/Faker/' . ($parts ? \implode('/', $parts) . '/' : '') .
                             $extension['class'] . '.php';


                FileUtil::writeToAbstractedPath($classFile, '<?php' . "\n" . $this->getExtensionFileValue($namespace, $extension), [], true);
            }
        }

        FileUtil::writeToAbstractedPath($this->mountPoint . $this->extensionsFile, $this->getExtensionCacheFileValue($grouped), [], true);
    }

    protected function getGroupedExtensions(): array
    {
        $notifiers = $this->getNotifiers();

        $grouped = [];

        foreach ($notifiers as $notifier)
        {
            $parts = \explode('\\', $notifier);

            $class = \array_pop($parts);
            $namespace = 'svNotifierFaker\\' . \implode('\\', $parts);

            $grouped[\ltrim($namespace, '\\')][] = [
                'class'       => $class,
                'proxy' => 'XFCP_' . $class,
                'base'  => \ltrim($notifier, '\\')
            ];
        }

        return $grouped;
    }

    /**
     * Find all extendable notifiers from XF & active add-ons
     *
     * @return array
     * @noinspection PhpIncludeInspection
     */
    protected function getNotifiers(): array
    {
        $rootDir = \XF::getSourceDirectory();
        $matches = \glob($rootDir . '/XF/Notifier/*/*.php', \GLOB_NOSORT);
        if (\is_array($matches))
        {
            foreach($matches as $file)
            {
                try
                {
                    @include_once($file);
                }
                catch(\Throwable $e)
                {}
            }
        }
        /** @var AddOn[] $addons */
        $addons = \XF::app()->addOnManager()->getInstalledAddOns();
        foreach($addons as $addon)
        {
            if (!$addon->isActive())
            {
                continue;
            }

            $globPatterns = [
                '/Notifier/*.php',
                '/Notifier/*/*.php',
            ];
            $rootDir = $addon->getAddOnDirectory();
            foreach ($globPatterns as $pattern)
            {
                $matches = \glob($rootDir . $pattern, \GLOB_NOSORT);
                if (!\is_array($matches))
                {
                    continue;
                }
                foreach ($matches as $file)
                {
                    try
                    {
                        @include_once($file);
                    }
                    catch (\Throwable $e)
                    {
                        $this->logException($e);
                    }
                }
            }
        }

        $classes = [];
        foreach(\get_declared_classes() as $class)
        {
            if (\is_subclass_of($class, AbstractNotifier::class, true))
            {
                $valid = false;
                try
                {
                    $testClass = new \ReflectionClass($class);
                    if (!$testClass->isFinal() && $testClass->isInstantiable())
                    {
                        $valid = true;
                    }
                }
                catch (\Throwable $e)
                {
                    $this->logException($e);
                }

                if ($valid)
                {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    protected function getExtensionCacheFileValue(array $grouped): string
    {
        $output = $this->getExtensionFileHeader($grouped);
        foreach ($grouped as $namespace => $extensions)
        {
            $output .= "\n";
            foreach ($extensions as $extension)
            {
                $output .= "\n\$extension->addClassExtension('{$extension['base']}', '{$namespace}\\{$extension['class']}');";
            }
        }
        $output .= "\n";

        return '<?php' . "\n" . $output . "\nreturn true;";
    }

    /** @noinspection PhpUnusedParameterInspection */
    protected function getExtensionFileHeader(array $grouped): string
    {
        return <<<TEXTBLOB
// ################## THIS IS A GENERATED FILE ##################
// DO NOT EDIT DIRECTLY

    \\XF::\$autoLoader->addPsr4('svNotifierFaker\\\', dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . '{$this->codeCachePath}' . DIRECTORY_SEPARATOR . 'Faker', false);
\$extension = \\XF::app()->extension();

TEXTBLOB;
    }

    protected function getExtensionFileValue(string $namespace, array $extension): string
    {
        return <<<TEXTBLOB
// ################## THIS IS A GENERATED FILE ##################
// DO NOT EDIT DIRECTLY
namespace {$namespace};

class {$extension['class']} extends {$extension['proxy']} { use \SV\AlertImprovements\NotifierPatchTrait; }

TEXTBLOB;
    }
}