#!/usr/bin/env php
<?php declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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

            if ($verbose) $io->note('Reading templates...');

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

            // check directories validity
            if ($verbose) $io->note('Checking validity for directories to scan...');
            $dirs = [$path . '/app', $path . '/src', $path . '/templates'];
            $dirs = array_filter($dirs, function($dir) {
               return file_exists($dir);
            });
            if (empty($dirs)) {
                $io->error('Failed to locate any twig directories, aborting');
                return;
            }
            if ($verbose) $io->comment("Directories to scan : \n".implode("\n", $dirs));

            if ($verbose) $io->note('Locating twig files...');
            $finderTwig->in($dirs);
            $io->success(iterator_count($finderTwig) . ' template files found');

            $twigs = []; // valid files

            /** @var \SplFileInfo $file */
            foreach ($finderTwig->files() as $file) {
                $twigs[] = $file->getRealPath();
            }

            if ($verbose) $io->note('Checking src directory validity...');
            if (!file_exists($path . '/src')) {
                $io->error('Failed to locate src directory, aborting');
                return;
            }
            if ($verbose) $io->comment('Found directory : '.$path.'/src');


            if ($verbose) $io->note('Scanning files for template names...');

            $valids = [];
            $invalids = [];

            $finderPhp = new Finder();
            $finderPhp->name('/\.php$/')->name('/\.twig$/')->in($dirs);

            /** @var \SplFileInfo $file */
            foreach ($finderPhp->files() as $file) {
                $contents = $file->getContents();

                if (preg_match_all('/\'(\@?[\w\/\_\.\:]+\.twig)\'/', $contents, $matches, PREG_PATTERN_ORDER)) {
                    if ($verbose) $io->section("Scanning in {$file->getFilename()}...");
                    $templateName = preg_replace('/^(@)([a-zA-Z\-\_]+)(\/)/','$2Bundle:' , $matches[1]);
                    $templateName = preg_replace('/(\/)([a-zA-Z\-\_\.]+)(twig)$/', ':$2$3', $templateName);

                    foreach ($templateName as $name) {
                        if (!isset($templates[$name])) {
                            $invalids[] = $name;
                            if ($verbose) $io->writeln("<error>✘︎</error> {$name}");
                            continue;
                        }

                        $twigFile = new \SplFileInfo($templates[$name]);
                        $valids[] = $twigFile->getRealPath();
                        if ($verbose) $io->writeln('<info>✔︎</info>︎ '.$twigFile->getRealPath());

                        // includes
                        $contents = file_get_contents($twigFile->getRealPath());

                        if (preg_match_all('/\'(\@?[\w\/\_\.\:]+\.twig)\'/', $contents, $matches, PREG_PATTERN_ORDER)) {
                            if ($verbose) $io->section("Scanning {$twigFile->getFilename()}...");
                            $result = preg_replace('/^(@)([a-zA-Z\-\_]+)(\/)/','$2Bundle:' , $matches[1]);
                            $result = preg_replace('/(\/)([a-zA-Z\-\_\.]+)(twig)$/', ':$2$3', $result);

                            foreach ($result as $item) {
                                if (!isset($templates[$item])) {
                                    $invalids[] = $item;
                                    if ($verbose) $io->writeln("<error>✘</error> {$item}");
                                    continue;
                                }

                                $twigFile = new \SplFileInfo($templates[$item]);
                                $valids[] = $twigFile->getRealPath();
                                if ($verbose) $io->writeln('<info>✔︎</info>︎ '.$twigFile->getRealPath());
                            }
                        }
                    }
                }
            }



            if ($verbose) $io->comment('Found '.count($valids).' consumed template files (duplicates)');
            $valids = array_unique($valids);
            if ($verbose) $io->comment('Found '.count($valids).' unique template files');

            if ($verbose) $io->note('Looking for orphans...');
            $orphans = array_diff($twigs, $valids);

            if (empty($orphans)) {
                $io->success('Everything\'s fine ! 🌈');
            }
            else {
                $io->caution(count($orphans) . ' orphans found.');
                $io->listing($orphans);
            }
            $io->warning(count($invalids) . ' invalid files found.');
            $io->listing($invalids);

        })
        ->getApplication()
        ->setDefaultCommand('twigcheck', true)
        ->run();

}
catch (Exception $e) {
    echo $e->getMessage();
}