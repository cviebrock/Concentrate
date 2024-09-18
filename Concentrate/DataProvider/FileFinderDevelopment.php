<?php

/**
 * @category  Tools
 *
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2010 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class Concentrate_DataProvider_FileFinderDevelopment implements Concentrate_DataProvider_FileFinderInterface
{
    public function getDataFiles()
    {
        $files = [];

        foreach ($this->getIncludeDirs() as $includeDir) {
            $dependencyDir = $includeDir . DIRECTORY_SEPARATOR . 'dependencies';

            $finder = new Concentrate_DataProvider_FileFinderDirectory(
                $dependencyDir
            );

            $files = array_merge($files, $finder->getDataFiles());
        }

        return $files;
    }

    protected function getIncludeDirs()
    {
        $dirs = explode(PATH_SEPARATOR, get_include_path());
        $dirs[] = '..';

        return $dirs;
    }
}
