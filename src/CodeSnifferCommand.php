<?php

namespace Avirdz\LaravelGitSniffer;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Class CodeSnifferCommand
 * @package Avirdz\LaravelGitSniffer
 */
class CodeSnifferCommand extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'git-sniffer:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check code standards on PHP_CodeSniffer and ESLint';

    protected $config;

    /** @var Filesystem */
    protected $files;

    /**
     * CodeSnifferCommand constructor.
     * @param $config
     * @param Filesystem $files
     */
    public function __construct($config, Filesystem $files)
    {
        parent::__construct();
        $this->config = $config;
        $this->files = $files;
    }

    /**
     * Handle the command
     */
    public function handle()
    {
        $environment = app()->environment();
        $gitSnifferEnv = $this->config->get('git-sniffer.env');

        if ($environment !== $gitSnifferEnv) {
            return;
        }

        $phpcsBin = $this->config->get('git-sniffer.phpcs_bin');
        $eslintBin = $this->config->get('git-sniffer.eslint_bin');
        $eslintConfig = $this->config->get('git-sniffer.eslint_config');
        $eslintIgnorePath = $this->config->get('git-sniffer.eslint_ignore_path');

        if (!empty($phpcsBin)) {
            if (!$this->files->exists($phpcsBin)) {
                $this->error('PHP CodeSniffer bin not found');
                exit(1);
            }
        }

        if (!empty($eslintBin)) {
            if (!$this->files->exists($eslintBin)) {
                $this->error('ESLint bin not found');
                exit(1);
            } elseif (!$this->files->exists($eslintConfig)) {
                $this->error('ESLint config file not found');
                exit(1);
            }

            if (!empty($eslintIgnorePath)) {
                if (!$this->files->exists($eslintIgnorePath)) {
                    $this->error('ESLint ignore file not found');
                    exit(1);
                }
            }
        }

        if (empty($phpcsBin) && empty($eslintBin)) {
            $this->error('Eslint bin and Phpcs bin are not configured');
            exit(1);
        }

        $revision = trim(shell_exec('git rev-parse --verify HEAD'));
        $against = "4b825dc642cb6eb9a060e54bf8d69288fbee4904";
        if (!empty($revision)) {
            $against = 'HEAD';
        }

        //this is the magic:
        //retrieve all files in staging area that are added, modified or renamed
        //but no deletions etc
        $files = trim(shell_exec("git diff-index --name-only --cached --diff-filter=ACMR {$against} --"));
        if (empty($files)) {
            exit(0);
        }

        $tempStaging = $this->config->get('git-sniffer.temp');
        //create temporary copy of staging area
        if ($this->files->exists($tempStaging)) {
            $this->files->deleteDirectory($tempStaging);
        }

        $fileList = explode("\n", $files);
        $validPhpExtensions = $this->config->get('git-sniffer.phpcs_extensions');
        $validEslintExtensions = $this->config->get('git-sniffer.eslint_extensions');
        $validFiles = [];

        foreach ($fileList as $l) {
            if (!empty($phpcsBin)) {
                if (in_array($this->files->extension($l), $validPhpExtensions)) {
                    $validFiles[] = $l;
                }
            }

            if (!empty($eslintBin)) {
                if (in_array($this->files->extension($l), $validEslintExtensions)) {
                    $validFiles[] = $l;
                }
            }
        }

        //Copy contents of staged version of files to temporary staging area
        //because we only want the staged version that will be commited and not
        //the version in the working directory
        if (empty($validFiles)) {
            exit(0);
        }

        $this->files->makeDirectory($tempStaging);
        $phpStaged = [];
        $eslintStaged = [];
        foreach ($validFiles as $f) {
            $id = shell_exec("git diff-index --cached {$against} \"{$f}\" | cut -d \" \" -f4");
            if (!$this->files->exists($tempStaging . '/' . $this->files->dirname($f))) {
                $this->files->makeDirectory($tempStaging . '/' . $this->files->dirname($f), 0755, true);
            }
            $output = shell_exec("git cat-file blob {$id}");
            $this->files->put($tempStaging . '/' . $f, $output);

            if (!empty($phpcsBin)) {
                if (in_array($this->files->extension($f), $validPhpExtensions)) {
                    $phpStaged[] = '"' . $tempStaging . '/' . $f . '"';
                }
            }

            if (!empty($eslintBin)) {
                if (in_array($this->files->extension($f), $validEslintExtensions)) {
                    $eslintStaged[] = '"' . $tempStaging . '/' . $f . '"';
                }
            }
        }

        $eslintOutput = null;
        $phpcsOutput = null;

        if (!empty($phpcsBin) && !empty($phpStaged)) {
            $standard = $this->config->get('git-sniffer.standard');
            $encoding = $this->config->get('git-sniffer.encoding');
            $ignoreFiles = $this->config->get('git-sniffer.phpcs_ignore');
            $phpcsExtensions = implode(',', $validPhpExtensions);
            $sniffFiles = implode(' ', $phpStaged);

            $phpcsIgnore = null;
            if (!empty($ignoreFiles)) {
                $phpcsIgnore = ' --ignore=' . implode(',', $ignoreFiles);
            }
            $phpcsOutput = shell_exec("\"{$phpcsBin}\" -s --standard={$standard} --encoding={$encoding} --extensions={$phpcsExtensions}{$phpcsIgnore} {$sniffFiles}");
        }

        if (!empty($eslintBin) && !empty($eslintStaged)) {
            $eslintFiles = implode(' ', $eslintStaged);
            $eslintIgnore = ' --no-ignore';

            if (!empty($eslintIgnorePath)) {
                $eslintIgnore = ' --ignore-path "' . $eslintIgnorePath . '"';
            }

            $eslintOutput = shell_exec("\"{$eslintBin}\" -c \"{$eslintConfig}\"{$eslintIgnore} --quiet  {$eslintFiles}");
        }

        $this->files->deleteDirectory($tempStaging);

        if (empty($phpcsOutput) && empty($eslintOutput)) {
            exit(0);
        } else {
            if (!empty($phpcsOutput)) {
                $this->error($phpcsOutput);
            }

            if (!empty($eslintOutput)) {
                $this->error($eslintOutput);
            }

            exit(1);
        }
    }
}
