<?php

use League\CLImate\CLImate;

/**
 * @category  Tools
 * @package   Concentrate
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2010-2020 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class Concentrate_CLI
{
    const VERBOSITY_NONE     = 0;
    const VERBOSITY_MESSAGES = 1;
    const VERBOSITY_DETAILS  = 2;

    /**
     * @var Console_CommandLine
     */
    protected $parser = null;

    /**
     * @var CLImate
     */
    protected $climate = null;

    /**
     * @var Concentrate_Concentrator
     */
    protected $concentrator = null;

    /**
     * @var boolean
     */
    protected $minify = false;

    /**
     * @var boolean
     */
    protected $combine = false;

    /**
     * @var boolean
     */
    protected $compile = false;

    /**
     * @var boolean
     */
    protected $clear = false;

    /**
     * @var string
     */
    protected $webroot = './';

    /**
     * @var string
     */
    protected $directory = '';

    /**
     * @var integer
     */
    protected $verbosity = self::VERBOSITY_NONE;

    /**
     * @var Concentrate_FileCache
     */
    protected $compiledCache = null;

    /**
     * @var Concentrate_FileCache
     */
    protected $minifiedCache = null;

    public function run()
    {
        $this->concentrator = new Concentrate_Concentrator();

        $this->parser = Console_CommandLine::fromXmlFile($this->getUiXml());
        $this->climate = new CLImate();
        try {
            $result = $this->parser->parse();

            $this->setOptions($result->options);
            $this->setWebRoot($result->args['webroot']);
            $this->loadDataFiles();

            if ($this->clear) {
                $this->clearCompiledDirectory();
            }

            if ($this->combine) {
                $this->writeCombinedFiles();
                $this->writeCombinedFlagFile();
            }

            if ($this->compile) {
                $this->writeCompiledFiles();
                $this->writeCompiledFlagFile();
            }

            if ($this->minify) {
                $this->writeMinifiedFiles();
                $this->writeMinifiedFlagFile();
            }
        } catch (Console_CommandLine_Exception $e) {
            $this->climate->to('error')->out($e->getMessage());
            exit(1);
        } catch (Exception $e) {
            $this->climate->to('error')->out($e->getMessage());
            $this->climate->to('error')->out($e->getTraceAsString());
            exit(1);
        }
    }

    public function setCompiledCache(Concentrate_FileCache $cache)
    {
        $this->compiledCache = $cache;
        return $this;
    }

    public function setMinifiedCache(Concentrate_FileCache $cache)
    {
        $this->minifiedCache = $cache;
        return $this;
    }

    protected function setWebRoot($webroot)
    {
        if ($this->verbosity >= self::VERBOSITY_MESSAGES) {
            $this->climate->br();
            $this->climate->out('Web root:');
        }

        $this->webroot = strval($webroot);
        $this->webroot = rtrim($this->webroot, DIRECTORY_SEPARATOR);

        if ($this->verbosity >= self::VERBOSITY_MESSAGES) {
            $this->climate->out('=> set to "' . $this->webroot . '"');
        }

        return $this;
    }

    protected function setOptions(array $options)
    {
        if (array_key_exists('verbose', $options)
            && $options['verbose'] !== null
        ) {
            $this->verbosity = intval($options['verbose']);
        }

        if (array_key_exists('clear', $options)
            && $options['clear'] !== null
        ) {
            $this->clear = ($options['clear']) ? true : false;
        }


        if (array_key_exists('combine', $options)
            && $options['combine'] !== null
        ) {
            $this->combine = ($options['combine']) ? true : false;
        }

        if (array_key_exists('minify', $options)
            && $options['minify'] !== null
        ) {
            $this->minify = ($options['minify']) ? true : false;
        }

        if (array_key_exists('compile', $options)
            && $options['compile'] !== null
        ) {
            $this->compile = ($options['compile']) ? true : false;
        }

        if (array_key_exists('directory', $options)
            && $options['directory'] !== null
        ) {
            $this->directory = strval($options['directory']);
            $this->directory = rtrim($this->directory, DIRECTORY_SEPARATOR);
        }

        if ($this->verbosity >= self::VERBOSITY_MESSAGES) {
            $this->climate->br();
            $this->climate->out('Options:');
            $this->climate->out('=> directory : ' . $this->directory);
            $this->climate->out(
                sprintf(
                    '=> combine   : %s',
                    ($this->combine) ? 'yes' : 'no'
                )
            );
            $this->climate->out(
                sprintf(
                    '=> minify    : %s',
                    ($this->minify) ? 'yes' : 'no'
                )
            );
            $this->climate->out(
                sprintf(
                    '=> compile   : %s',
                    ($this->compile) ? 'yes' : 'no'
                )
            );
        }

        return $this;
    }

    protected function loadDataFiles()
    {
        // load data files from composer vendor dir
        $fileFinder = new Concentrate_DataProvider_FileFinderComposer(
            $this->webroot
        );
        $this->concentrator->loadDataFiles(
            $fileFinder->getDataFiles()
        );

        // load data files from optional directory
        if ($this->directory != '') {
            $fileFinder = new Concentrate_DataProvider_FileFinderDirectory(
                $this->directory
            );
            $this->concentrator->loadDataFiles(
                $fileFinder->getDataFiles()
            );
        }

        return $this;
    }

    protected function clearCompiledDirectory()
    {
        $directory = $this->webroot . DIRECTORY_SEPARATOR . 'compiled';

        $confirm = $this->climate->confirm(
            sprintf(
                'Clear all files from %s?',
                $directory
            )
        );

        if (!$confirm) {
            $this->climate->to('error')->out('Aborting.');
            exit(1);
        }

        if ($this->verbosity >= self::VERBOSITY_MESSAGES) {
            $this->climate->br();
            $this->climate->out("Clearing all files from {$directory}:");
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . '*');

        foreach ($files as $file) {
            $this->climate->out("...{$file}");
        }

    }

    protected function writeCombinedFiles()
    {
        if ($this->verbosity >= self::VERBOSITY_MESSAGES) {
            $this->climate->br();
            $this->climate->out('Writing combined files:');
        }

        $packer       = new Concentrate_Packer();
        $combinesInfo = $this->concentrator->getCombinesInfo();

        if (count($combinesInfo) === 0
            && $this->verbosity >= self::VERBOSITY_MESSAGES
        ) {
            $this->climate->out('=> no combined files to write.');
        }

        foreach ($combinesInfo as $combine => $info) {
            if ($this->verbosity >= self::VERBOSITY_MESSAGES) {
                $filename = $this->webroot . DIRECTORY_SEPARATOR . $combine;
                $this->climate->out('=> writing "' . $filename . '"');
            }

            $files = $info['Includes'];

            $this->checkForConflicts(array_keys($files));
            uksort($files, array($this->concentrator, 'compareFiles'));

            if ($this->verbosity >= self::VERBOSITY_DETAILS) {
                foreach ($files as $file => $info) {
                    $this->climate->inline(' * ' . $file);
                    if (!$info['explicit']) {
                        $this->climate->cyan()->inline(' (implicit)');
                    };
                    $this->climate->br();
                }
                $this->climate->br();
            }

            $packer->pack($this->webroot, array_keys($files), $combine);
        }
    }

    protected function writeMinifiedFiles()
    {
        $filter = new Concentrate_Filter_Minifier_YUICompressor(['types' => []]);
        $filter->chain(new Concentrate_Filter_Minifier_Terser());
        $filter->chain(new Concentrate_Filter_Minifier_CleanCSS());
        $filter->chain(new Concentrate_Filter_CSSMover('', ''));

        $yuiFallbacks = [
            'js' => Concentrate_Filter_Minifier_Terser::class,
            'css' => Concentrate_Filter_Minifier_CleanCSS::class,
        ];

        // If YUI Compressor is available and Terser or Clean-CSS are not
        // available, fall back to using YUI Compressor by enabling its types.
        foreach ($yuiFallbacks as $type => $class) {
            if (!$filter->get($class)->isSuitable($type)) {
                $filter->addType($type);
            }
        }

        $this->writeMinifiedFilesFromDirectory($filter);

        if ($this->compile) {
            $this->writeMinifiedFilesFromDirectory(
                $filter,
                'compiled',
                array(
                    'css',
                    'js',
                    'less'
                )
            );
        }
    }

    protected function writeMinifiedFilesFromDirectory(
        Concentrate_Filter_Abstract $filter,
        $directory = '',
        array $types = array('css', 'js')
    ) {
        if ($this->verbosity >= self::VERBOSITY_MESSAGES) {
            $this->climate->br();
            if ($directory != '') {
                $this->climate->out(
                    sprintf(
                        'Writing minified files from %s:',
                        $directory
                    )
                );
            } else {
                $this->climate->out(
                    'Writing minified files:'
                );
            }
        }

        $fileInfo = $this->concentrator->getFileInfo();
        foreach ($fileInfo as $file => $info) {
            if ($directory == '') {
                $fromFilename = $this->webroot
                    . DIRECTORY_SEPARATOR . $file;
            } else {
                $fromFilename = $this->webroot
                    . DIRECTORY_SEPARATOR . $directory
                    . DIRECTORY_SEPARATOR . $file;
            }

            // if source file does not exist, skip it
            if (!file_exists($fromFilename)) {
                continue;
            }

            // if file specifies that is should not be minified, skip it
            if (!$info['Minify']) {
                continue;
            }

            // only minify valid types
            $type = pathinfo($fromFilename, PATHINFO_EXTENSION);
            if (!in_array($type, $types)) {
                continue;
            }

            // Compiled less files are actually CSS. Set type appropriately.
            if ($type === 'less') {
                $type = 'css';
            }

            if ($directory == '') {
                $toFilename = $this->webroot
                    . DIRECTORY_SEPARATOR . 'min'
                    . DIRECTORY_SEPARATOR . $file;
            } else {
                $toFilename = $this->webroot
                    . DIRECTORY_SEPARATOR . 'min'
                    . DIRECTORY_SEPARATOR . $directory
                    . DIRECTORY_SEPARATOR . $file;
            }

            if ($this->verbosity >= self::VERBOSITY_DETAILS) {
                $this->climate->out(' * ' . $directory . '/' . $file);
            }

            // If type is CSS, add extra filter to chain to update relative
            // URIs within the CSS
            if ($type === 'css') {
                $fromFilterFile = ($directory == '')
                    ? $file
                    : $directory . '/' . $file;

                $toFilterFile = ($directory =='')
                    ? 'min/' . $file
                    : 'min/' . $directory . '/' . $file;

                $filter->get(Concentrate_Filter_CSSMover::class)
                    ->setFromPath($fromFilterFile)
                    ->setToPath($toFilterFile);
            }

            $this->writeMinifiedFile(
                $filter,
                $fromFilename,
                $toFilename,
                $type
            );
        }

        if ($this->combine) {
            $combinesInfo = $this->concentrator->getCombinesInfo();
            foreach ($combinesInfo as $combine => $info) {
                if ($directory == '') {
                    $fromFilename = $this->webroot
                        . DIRECTORY_SEPARATOR . $combine;
                } else {
                    $fromFilename = $this->webroot
                        . DIRECTORY_SEPARATOR . $directory
                        . DIRECTORY_SEPARATOR . $combine;
                }

                // if source file does not exist, skip it
                if (!file_exists($fromFilename)) {
                    continue;
                }

                // if file specifies that is should not be minified, skip it
                if (!$info['Minify']) {
                    continue;
                }

                // only minify valid types
                $type = pathinfo($fromFilename, PATHINFO_EXTENSION);
                if (!in_array($type, $types)) {
                    continue;
                }

                // Compiled less files are actually CSS. Set type appropriately.
                if ($type === 'less') {
                    $type = 'css';
                }

                if ($directory == '') {
                    $toFilename = $this->webroot
                        . DIRECTORY_SEPARATOR . 'min'
                        . DIRECTORY_SEPARATOR . $combine;
                } else {
                    $toFilename = $this->webroot
                        . DIRECTORY_SEPARATOR . 'min'
                        . DIRECTORY_SEPARATOR . $directory
                        . DIRECTORY_SEPARATOR . $combine;
                }

                if ($this->verbosity >= self::VERBOSITY_DETAILS) {
                    $this->climate->out(' * ' . $directory . '/' . $combine);
                }

                // If type is CSS, add extra filter to chain to update relative
                // URIs within the CSS
                if ($type === 'css') {
                    $fromFilterFile = ($directory == '')
                        ? $combine
                        : $directory . '/' . $combine;

                    $toFilterFile = ($directory == '')
                        ? 'min/' . $combine
                        : 'min/' . $directory . '/' . $combine;

                    $filter->get(Concentrate_Filter_CSSMover::class)
                        ->setFromPath($fromFilterFile)
                        ->setToPath($toFilterFile);
                }

                $this->writeMinifiedFile(
                    $filter,
                    $fromFilename,
                    $toFilename,
                    $type
                );
            }
        }
    }

    protected function writeMinifiedFile(
        Concentrate_Filter_Abstract $filter,
        $fromFilename,
        $toFilename,
        $type
    ) {
        $key = md5(
            $fromFilename                // file name
            . md5_file($fromFilename)    // file content
            . $filter->getChainId($type) // applied filter chain
        );

        $cache = $this->minifiedCache;

        if ($cache instanceof Concentrate_FileCache
            && $cache->copyTo($key, $toFilename)
        ) {
            if ($this->verbosity >= self::VERBOSITY_DETAILS) {
                $this->climate->lightGray()->out(
                    '   used cached version'
                );
            }
        } else {
            // minify
            $filter->filterFile($fromFilename, $toFilename, $type);

            if ($cache instanceof Concentrate_FileCache
                && $cache->write($key, $toFilename)
            ) {
                if ($this->verbosity >= self::VERBOSITY_DETAILS) {
                    $this->climate->lightGray()->out(
                        '   wrote cached version'
                    );
                }
            } else {
                if ($this->verbosity >= self::VERBOSITY_DETAILS) {
                    $this->climate->lightGray()->out(
                        '   could not write cached version'
                    );
                }
            }
        }
    }

    protected function writeCompiledFiles()
    {
        if ($this->verbosity >= self::VERBOSITY_MESSAGES) {
            $this->climate->br();
            $this->climate->out('Writing compiled files:');
        }

        $compilers = [
            new Concentrate_Compiler_Less(),
            new Concentrate_Compiler_Babel(),
        ];

        $fileInfo = $this->concentrator->getFileInfo();
        foreach ($fileInfo as $file => $info) {
            $fromFilename = $this->webroot
                . DIRECTORY_SEPARATOR . $file;

            // if source file does not exist, skip it
            if (!file_exists($fromFilename)) {
                continue;
            }

            $type = pathinfo($fromFilename, PATHINFO_EXTENSION);
            $compiler = current(
                array_filter(
                    $compilers,
                    fn($c) => $c->isSuitable($type)
                )
            );

            // only compile supported files
            if ($compiler === false) {
                continue;
            }

            $toFilename = $this->webroot
                . DIRECTORY_SEPARATOR . 'compiled'
                . DIRECTORY_SEPARATOR . $file;

            if ($this->verbosity >= self::VERBOSITY_DETAILS) {
                $this->climate->out(' * ' . $file);
            }

            $filter = ($type === 'less')
                ? new Concentrate_Filter_CSSMover(
                    $file,
                    'compiled/' . $file
                )
                : null;

            $this->writeCompiledFile(
                $compiler,
                $filter,
                $fromFilename,
                $toFilename,
                $type
            );
        }

        if ($this->combine) {
            $combinesInfo = $this->concentrator->getCombinesInfo();
            foreach ($combinesInfo as $combine => $info) {
                $fromFilename = $this->webroot
                    . DIRECTORY_SEPARATOR . $combine;

                // if source file does not exist, skip it
                if (!file_exists($fromFilename)) {
                    continue;
                }

                $type = pathinfo($fromFilename, PATHINFO_EXTENSION);
                $compiler = current(
                    array_filter(
                        $compilers,
                        fn($c) => $c->isSuitable($type)
                    )
                );

                // only compile supported files
                if ($compiler === false) {
                    continue;
                }

                $toFilename = $this->webroot
                    . DIRECTORY_SEPARATOR . 'compiled'
                    . DIRECTORY_SEPARATOR . $combine;

                if ($this->verbosity >= self::VERBOSITY_DETAILS) {
                    $this->climate->out(' * ' . $combine);
                }

                $filter = ($type === 'less')
                    ? new Concentrate_Filter_CSSMover(
                        $combine,
                        'compiled/' . $combine
                    )
                    : null;

                $this->writeCompiledFile(
                    $compiler,
                    $filter,
                    $fromFilename,
                    $toFilename,
                    $type
                );
            }
        }
    }

    protected function writeCompiledFile(
        Concentrate_Compiler_Abstract $compiler,
        Concentrate_Filter_Abstract $filter = null,
        $fromFilename,
        $toFilename,
        $type
    ) {
        // cache key is unique on file path and file content
        $key = md5($fromFilename . md5_file($fromFilename));

        $cache = $this->compiledCache;

        if ($cache instanceof Concentrate_FileCache
            && $cache->copyTo($key, $toFilename)
        ) {
            if ($this->verbosity >= self::VERBOSITY_DETAILS) {
                $this->climate->lightGray()->out(
                    '   used cached version'
                );
            }
        } else {
            // compile
            $compiler->compileFile($fromFilename, $toFilename, $type);

            // TODO: perform filtering before writing file
            if ($filter instanceof Concentrate_Filter_Abstract) {
                $filter->filterFile($toFilename, $toFilename, $type);
            }

            if ($cache instanceof Concentrate_FileCache
                && $cache->write($key, $toFilename)
            ) {
                if ($this->verbosity >= self::VERBOSITY_DETAILS) {
                    $this->climate->lightGray()->out(
                        '   wrote cached version'
                    );
                }
            } else {
                if ($this->verbosity >= self::VERBOSITY_DETAILS) {
                    $this->climate->lightGray()->out(
                        '   could not write cached version'
                    );
                }
            }
        }
    }

    protected function writeCombinedFlagFile()
    {
        $this->writeFlagFile(Concentrate_FlagFile::COMBINED);
    }

    protected function writeMinifiedFlagFile()
    {
        $this->writeFlagFile(Concentrate_FlagFile::MINIFIED);
    }

    protected function writeCompiledFlagFile()
    {
        $this->writeFlagFile(Concentrate_FlagFile::COMPILED);
    }

    protected function writeFlagFile($filename)
    {
        $filename = $this->webroot
            . DIRECTORY_SEPARATOR . $filename;

        if ($this->verbosity >= self::VERBOSITY_MESSAGES) {
            $this->climate->br();
            $this->climate->out(
                "Writing flag file '{$filename}':"
            );
        }

        if ((!file_exists($filename) && !is_writable($this->webroot))
            || (file_exists($filename) && !is_writable($filename))
        ) {
            throw new Concentrate_FileException(
                "The flag file '{$filename}' could not be written."
            );
        }

        file_put_contents($filename, time());

        if ($this->verbosity >= self::VERBOSITY_MESSAGES) {
            $this->climate->out('=> written');
        }
    }

    protected function getUiXml()
    {
        return __DIR__ . DIRECTORY_SEPARATOR . '..'
            . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
            . 'cli.xml';
    }

    /**
     * @param array $files
     *
     * @throws Exception if one or more conflicts are present.
     */
    protected function checkForConflicts(array $files)
    {
        $conflicts = $this->concentrator->getConflicts($files);
        if (count($conflicts) > 0) {
            $conflictList = '';
            $count = 0;
            foreach ($conflicts as $file => $conflict) {
                $conflictList.= sprintf(
                    "\n- %s conflicts with %s",
                    $file,
                    implode(', ', $conflict)
                );
                $count++;
            }
            throw new Exception(
                'The following conflicts were detected: ' . $conflictList
            );
        }
    }
}
