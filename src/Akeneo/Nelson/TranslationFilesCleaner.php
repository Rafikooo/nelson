<?php

namespace Akeneo\Nelson;

use Akeneo\Event\Events;
use Akeneo\System\Executor;
use SplFileInfo;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * This class adapts the Crowdin format to the Akeneo format:
 * - Remove the '---' of all the translations files provided by Crowdin
 * - Change the full format locale (fr_FR) to 2-letters (fr) if needed.
 *
 * @author    Nicolas Dupont <nicolas@akeneo.com>
 * @copyright 2016 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class TranslationFilesCleaner
{
    /** @var Executor */
    protected $systemExecutor;

    /** @var EventDispatcherInterface */
    protected $eventDispatcher;

    /** @var string */
    protected $patternSuffix;

    /** @var array */
    protected $finderOptions;

    /**
     * @param Executor                 $systemExecutor
     * @param EventDispatcherInterface $eventDispatcher
     * @param string                   $patternSuffix
     * @param array                    $finderOptions
     */
    public function __construct(
        Executor $systemExecutor,
        EventDispatcherInterface $eventDispatcher,
        $patternSuffix,
        $finderOptions
    ) {
        $this->systemExecutor  = $systemExecutor;
        $this->patternSuffix   = $patternSuffix;
        $this->eventDispatcher = $eventDispatcher;
        $this->finderOptions   = $finderOptions;
    }

    /**
     * Clean the files before the Pull Request creation
     *
     * @param array  $localeMap
     * @param string $cleanerDir
     * @param string $projectDir
     */
    public function cleanFiles(array $localeMap, $cleanerDir, $projectDir)
    {
        $finder = new Finder();
        $translatedFiles = $finder->in($cleanerDir)->files();

        /** @var SplFileInfo $file */
        foreach ($translatedFiles as $file) {
            $this->cleanCrowdinYamlTranslation($file);

            $indent = null;
            $lines = file($file->getRealPath());
            foreach ($lines as $key => $line) {
                if ((bool) preg_match('/^\s*null\s*$/', $line)) {
                    unset($lines[$key]);
                    continue;
                }

                if (0 === strpos($line, '#') || '' === trim($line)) {
                    unset($lines[$key]);
                    continue;
                }

                if (null === $indent && 0 === strpos($line, ' ')) {
                    $matches = null;
                    preg_match('/^(?P<indent>[ ]++)/', $line, $matches);
                    $indent = strlen($matches['indent']);
                }

                if (null !== $indent) {
                    $length = strlen($line) - strlen(ltrim($line));

                    $lines[$key] = str_replace(
                        str_repeat(' ', $length),
                        str_repeat(' ', 4 * (int) floor($length / $indent)),
                        $line
                    );
                }

                $lines[$key] = rtrim($lines[$key]);
            }

            $fileContent = implode("\n", $lines) . "\n";

            if ('' === trim($fileContent)) {
                unlink($file->getRealPath());
                continue;
            }

            $fileContent =
                "# This file is part of the Sylius package.\n" .
                "# (c) Paweł Jędrzejewski\n" .
                "\n" .
                $fileContent
            ;

            file_put_contents($file->getRealPath(), $fileContent);

            $this->renameTranslation($file, $localeMap);
        }
    }

    /**
     * Crowdin adds "---" on the beginning of every Yaml during the export. This function removes the first line of
     * a file.
     *
     * @param SplFileInfo $file
     */
    protected function cleanCrowdinYamlTranslation(SplFileInfo $file)
    {
        $fileContent = file($file->getRealPath());

        if (count($fileContent)) {
            if (1 === preg_match("/^---/", $fileContent[0])) {
                unset($fileContent[0]);
            }

            file_put_contents($file->getRealPath(), $fileContent);
        }
    }

    /**
     * Returns a 2-letters locale if any map is needed
     *
     * @param string $locale
     * @param array  $localeMap
     *
     * @return null|string
     */
    protected function getSimpleLocale($locale, array $localeMap)
    {
        if (array_key_exists($locale, $localeMap)) {
            return $localeMap[$locale];
        }

        if (preg_match("/^[a-z]{2}_/i", $locale)) {
            return substr($locale, 0, 2);
        }

        return null;
    }

    /**
     * Rename the translation from complete locale format to 2-letters format.
     * For example, rename validators.fr_FR.yml to validators.fr.yml; this will erase the current translation file.
     *
     * @param SplFileInfo $file
     * @param array       $localeMap
     */
    protected function renameTranslation($file, $localeMap)
    {
        $pathInfo = pathinfo($file);

        $locale = pathinfo($pathInfo['filename'], PATHINFO_EXTENSION);
        $simpleLocale = $this->getSimpleLocale($locale, $localeMap);

        if ($simpleLocale) {
            $target = sprintf(
                '%s/%s.%s.%s',
                $pathInfo['dirname'],
                pathinfo($pathInfo['filename'], PATHINFO_FILENAME),
                $simpleLocale,
                $pathInfo['extension']
            );

            $target = preg_replace('/\+intl\-icu\.en\.([A-Za-z_]{2,5}\.\w+)/', '+intl-icu.$1', $target);

            $this->eventDispatcher->dispatch(Events::NELSON_RENAME, new GenericEvent($this, [
                'from' => $file,
                'to'   => $target,
            ]));

            rename($file, $target);
        }
    }

    /**
     * Move the cleaned files to the project directories
     *
     * @param string $cleanerDir
     * @param string $projectDir
     *
     * @throws \Exception
     */
    public function moveFiles($cleanerDir, $projectDir)
    {
        $fullCleanerDir = sprintf(
            '%s%s%s',
            $cleanerDir,
            DIRECTORY_SEPARATOR,
            $this->patternSuffix
        );

        $cleanerFinder = new Finder();
        $cleanerFinder->in($fullCleanerDir)->files();

        foreach ($cleanerFinder as $file) {
            $relativePath = substr($file->getPath(), strlen($fullCleanerDir));
            $projectFinder = new Finder();
            $fullProjectDir = $projectDir . DIRECTORY_SEPARATOR . $relativePath;
            $filename = substr($file->getFilename(), 0, strpos($file->getFilename(), '.'));
            $isDir = is_dir($fullProjectDir);


            if (true === $isDir) {
                $projectFinder
                    ->in($fullProjectDir)
                    ->name($this->finderOptions['name'])
                    ->name($filename . '.*')
                    ->files();
            }

            if ($isDir && ($projectFinder->count() > 0)) {
                $this->systemExecutor->execute(sprintf(
                    'cp %s %s',
                    $file->getPathname(),
                    $fullProjectDir
                ));
            } else {
                $this->eventDispatcher->dispatch(Events::NELSON_DROP_USELESS, new GenericEvent($this, [
                    'file' => $file->getPathname(),
                ]));
            }
        }

        $this->systemExecutor->execute(sprintf('rm -rf %s', $cleanerDir));
    }
}
