#!/usr/bin/env php
<?php declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

try {
    (new Application('twigcheck', '1.0.0'))
        ->register('twigcheck')
        ->addArgument('path', InputArgument::REQUIRED, 'The directory')
        ->setCode(function (InputInterface $input, OutputInterface $output) {

            $io = new SymfonyStyle($input, $output);

            $io->title('Twig check');

            $path = $input->getArgument('path');
            $verbose = $output->isVerbose();

            $io->note('Reading templates...');

            $templatesPath = $path . '/var/cache/dev/templates.php';
            if (!file_exists($templatesPath)) {
                $io->error('Failed to read templates file, aborting');
                return;
            }

            // read templates file in cache
            $templates = include $templatesPath;
            if ($verbose) $io->comment('Read templates file :'.$templatesPath);

            // make paths canonical
            array_walk($templates, function (&$path, $key) {
                $path = realpath($path);
            });
            if ($verbose) $io->comment('Make paths canonical');

            $finderTwig = new Finder();
            $finderTwig->name('/\.twig$/');

            $io->note('Locating twig files...');

            // check directories validity
            if ($verbose) $io->comment('Checking validity for directories to scan...');
            $dirs = [$path . '/app', $path . '/src', $path . '/templates'];
            $dirs = array_filter($dirs, function($dir) {
               return file_exists($dir);
            });
            if (empty($dirs)) {
                $io->error('Failed to locate any twig directories, aborting');
                return;
            }
            if ($verbose) $io->comment('Directories to scan : '.implode(', ', $dirs));

            $finderTwig->in($dirs);
            $io->note(iterator_count($finderTwig) . ' template files found');

            $twigs = []; // valid files

            /** @var \SplFileInfo $file */
            foreach ($finderTwig->files() as $file) {
                $twigs[] = $file->getRealPath();
            }

            if ($verbose) $io->comment('Checking src directory validity...');
            if (!file_exists($path . '/src')) {
                $io->error('Failed to locate src directory, aborting');
                return;
            }
            if ($verbose) $io->comment('Found directory : '.$path.'/src');

            $validFiles = [];

            $finderPhp = new Finder();
            $finderPhp->name('/\.php$/')->in($path . '/src');

            if ($verbose) $io->comment('Iterating over php files in src...');
            if ($verbose) $io->comment('Scanning for template names in content...');
            /** @var \SplFileInfo $file */
            foreach ($finderPhp->files() as $file) {
                $contents = $file->getContents();

                if (preg_match_all('/\'(\@?[\w\/\_\.\:]+\.twig)\'/', $contents, $matches, PREG_PATTERN_ORDER)) {
                    if ($verbose) $io->comment("Scanning {$file->getFilename()}...");
                    $templateName = preg_replace('/^(@)([a-zA-Z\-\_]+)(\/)/','$2Bundle:' , $matches[1]);
                    $templateName = preg_replace('/(\/)([a-zA-Z\-\_\.]+)(twig)$/', ':$2$3', $templateName);

                    foreach ($templateName as $name) {
                        if (!isset($templates[$name])) {
                            $io->error($name);
                            continue;
                        }

                        $twigFile = new \SplFileInfo($templates[$name]);
                        $validFiles[] = $twigFile->getRealPath();
                        if ($verbose) $io->comment('[✔︎] Found : '.$twigFile->getRealPath());

                        // includes
                        $contents = file_get_contents($twigFile->getRealPath());

                        if ($verbose) $io->comment('Scanning for template names in template file content...');
                        if (preg_match_all('/\'(\@?[\w\/\_\.\:]+\.twig)\'/', $contents, $matches, PREG_PATTERN_ORDER)) {
                            $result = preg_replace('/^(@)([a-zA-Z\-\_]+)(\/)/','$2Bundle:' , $matches[1]);
                            $result = preg_replace('/(\/)([a-zA-Z\-\_\.]+)(twig)$/', ':$2$3', $result);

                            foreach ($result as $it) {
                                if (!isset($templates[$it])) {
                                    $io->error($it);
                                    continue;
                                }

                                $twigFile = new \SplFileInfo($templates[$it]);
                                $validFiles[] = $twigFile->getRealPath();
                                if ($verbose) $io->comment('[✔︎] Found : '.$twigFile->getRealPath());
                            }
                        }
                    }
                }
            }
            if ($verbose) $io->comment('Found '.count($validFiles).' consumed template files (duplicates)');
            $validFiles = array_unique($validFiles);
            if ($verbose) $io->comment('Found '.count($validFiles).' unique template files');

            if ($verbose) $io->comment('Looking for orphans...');
            $orphans = array_diff($twigs, $validFiles);

            if (empty($orphans)) {
                $io->success('Everything\'s fine ! 🌈');
            }
            else {
                $io->caution(count($orphans) . ' orphans files found.');
                $io->listing($orphans);
            }

        })
        ->getApplication()
        ->setDefaultCommand('twigcheck', true)
        ->run();

}
catch (Exception $e) {
    echo $e->getMessage();
}