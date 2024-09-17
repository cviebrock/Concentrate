<?php

/**
 * @category  Tools
 * @package   Concentrate
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2010-2015 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 */
class Concentrate_Concentrator
{


    protected $dataProvider = null;

    protected $cache = null;



    public function __construct(array $options = [])
    {
        if (array_key_exists('cache', $options)) {
            $this->setCache($options['cache']);
        } else {
            $this->setCache(new Concentrate_CacheArray());
        }

        if (array_key_exists('dataProvider', $options)) {
            $this->setDataProvider($options['dataProvider']);
        } elseif (array_key_exists('data_provider', $options)) {
            $this->setDataProvider($options['data_provider']);
        } else {
            $this->setDataProvider(new Concentrate_DataProvider());
        }
    }



    public function setDataProvider(Concentrate_DataProvider $dataProvider): static
    {
        $this->dataProvider = $dataProvider;
        return $this;
    }



    public function setCache(Concentrate_CacheInterface $cache): static
    {
        $this->cache = $cache;
        return $this;
    }



    public function loadDataFile($filename): static
    {
        $this->dataProvider->loadFile($filename);
        return $this;
    }



    public function loadDataFiles(array $filenames): static
    {
        foreach ($filenames as $filename) {
            $this->loadDataFile($filename);
        }
        return $this;
    }



    public function compareFiles($file1, $file2): int
    {
        if ($file1 == $file2) {
            return 0;
        }

        $sortOrder = $this->getFileSortOrder();

        if (!isset($sortOrder[$file1]) && !isset($sortOrder[$file2])) {
            return 0;
        }

        if (isset($sortOrder[$file1]) && !isset($sortOrder[$file2])) {
            return -1;
        }

        if (!isset($sortOrder[$file1]) && isset($sortOrder[$file2])) {
            return 1;
        }

        if ($sortOrder[$file1] < $sortOrder[$file2]) {
            return -1;
        }

        if ($sortOrder[$file1] > $sortOrder[$file2]) {
            return 1;
        }

        return 0;
    }



    public function isMinified($file): bool
    {
        $minified = false;

        if (!$minified) {
            $fileInfo = $this->getFileInfo();
            if (isset($fileInfo[$file])) {
                $minified = $fileInfo[$file]['Minify'];
            }
        }

        if (!$minified) {
            $combinesInfo = $this->getCombinesInfo();
            if (isset($combinesInfo[$file])) {
                $minified = $combinesInfo[$file]['Minify'];
            }
        }

        return $minified;
    }



    public function getConflicts(array $files): array
    {
        $conflicts = [];

        // flip so the files are hash keys to speed lookups
        $files = array_flip($files);

        $fileInfo = $this->getFileInfo();

        foreach ($files as $file => $garbage) {
            if (array_key_exists($file, $fileInfo)) {
                $fileFileInfo = $fileInfo[$file];
                if (isset($fileFileInfo['Conflicts'])
                    && is_array($fileFileInfo['Conflicts'])
                ) {
                    foreach ($fileFileInfo['Conflicts'] as $conflict) {
                        if (array_key_exists($conflict, $files)) {
                            if (!isset($conflicts[$file])) {
                                $conflicts[$file] = [];
                            }
                            $conflicts[$file][] = $conflict;
                        }
                    }
                }
            }
        }

        return $conflicts;
    }



    public function getCombines(array $files): array
    {
        $superset    = $files;
        $combinedSet = [];
        $combines    = [];

        $combinesInfo = $this->getCombinesInfo();
        foreach ($combinesInfo as $combine => $combineInfo) {
            $combinedFiles = array_keys($combineInfo['Includes']);

            // check if combine does not conflict with already added combines
            // and if combine contains one or more files in the required file
            // list
            if (count(array_intersect($combinedFiles, $combinedSet)) === 0
                && count(array_intersect($combinedFiles, $files)) > 0
            ) {
                $potentialSuperset = array_merge($superset, $combinedFiles);

                // make sure combining will not introduce conflicts
                if (count($this->getConflicts($potentialSuperset)) === 0) {
                    $combinedSet = array_merge($combinedSet, $combinedFiles);
                    $superset    = $potentialSuperset;
                    $combines[]   = $combine;
                }
            }

        }

        // remove dupes from superset caused by combines
        $superset = array_unique($superset);

        // exclude contents of combined sets from file list
        foreach ($combines as $combine) {
            $files = array_diff(
                $files,
                array_keys($combinesInfo[$combine]['Includes'])
            );

            $files[] = $combine;
        }

        $info = [
            // 'combines' contains the combined files that will be included.
            'combines' => $combines,
            // 'superset' contains all original files plus files pulled in by
            // the combined sets. The content of these files will be included.
            'superset' => $superset,
            // 'files' contains combined files and files in the original set
            // that did not fit in any combined set. These are the actual files
            // that will be included.
            'files'    => $files,
        ];

        return $info;
    }



    public function getFileSortOrder(): array
    {
        $fileSortOrder = $this->getCachedValue('fileSortOrder');
        if ($fileSortOrder === false) {

            $data = $this->dataProvider->getData();

            $fileSortOrder = [];

            // get flat list of file dependencies for each file
            $dependsInfo = $this->getDependsInfo();

            // build into graph
            $graph = new Concentrate_Graph();
            $nodes = [];
            foreach ($dependsInfo as $file => $dependencies) {
                if (!isset($nodes[$file])) {
                    $nodes[$file] = new Concentrate_Graph_Node(
                        $graph,
                        $file
                    );
                }
                foreach ($dependencies as $dependentFile) {
                    if (!isset($nodes[$dependentFile])) {
                        $nodes[$dependentFile] = new Concentrate_Graph_Node(
                            $graph,
                            $dependentFile
                        );
                    }
                }
            }
            foreach ($dependsInfo as $file => $dependencies) {
                foreach ($dependencies as $dependentFile) {
                    $nodes[$file]->connectTo($nodes[$dependentFile]);
                }
            }

            // add combines to graph
            $combinesInfo = $this->getCombinesInfo();
            foreach ($combinesInfo as $combine => $combineInfo) {

                $files = $combineInfo['Includes'];

                if (!isset($nodes[$combine])) {
                    $nodes[$combine] = new Concentrate_Graph_Node(
                        $graph,
                        $combine
                    );
                }

                // get combine dependencies as difference of union of
                // dependencies of contained files and combined set
                $depends = [];
                foreach ($files as $file => $info) {
                    if (isset($dependsInfo[$file])) {
                        $depends = array_merge(
                            $dependsInfo[$file],
                            $depends
                        );
                    }
                }
                $depends = array_diff($depends, array_keys($files));
                foreach ($depends as $depend) {
                    $nodes[$combine]->connectTo($nodes[$depend]);
                }

                // add combine as dependency of all contained files
                foreach ($files as $file => $info) {
                    if (!isset($nodes[$file])) {
                        $nodes[$file] = new Concentrate_Graph_Node(
                            $graph,
                            $file
                        );
                    }
                    $nodes[$file]->connectTo($nodes[$combine]);
                }
            }

            $sorter = new Concentrate_Graph_TopologicalSorter();
            try {
                $sortedNodes = $sorter->sort($graph);
            } catch (Concentrate_CyclicDependencyException $e) {
                throw new Concentrate_CyclicDependencyException(
                    'File dependency order can not be determined because '
                    . 'file dependencies contain one more more cycles. '
                    . 'There is likely a typo in the provides section of one '
                    . 'or more YAML files.'
                );
            }

            $fileSortOrder = [];
            foreach ($sortedNodes as $node) {
                $fileSortOrder[] = $node->getData();
            }

            // Flip the sorted array so it is indexed by file with values
            // being the relative sort order.
            $fileSortOrder = array_flip($fileSortOrder);

            $this->setCachedValue('fileSortOrder', $fileSortOrder);
        }

        return $fileSortOrder;
    }



    public function getFileInfo(): array
    {
        $fileInfo = $this->getCachedValue('fileInfo');
        if ($fileInfo === false) {

            $data = $this->dataProvider->getData();

            $fileInfo = [];

            foreach ($data as $packageId => $info) {
                $provides = $this->getProvidesForPackage($packageId);
                foreach ($provides as $file => $providesInfo) {
                    if (isset($providesInfo['Minify'])) {
                        $providesInfo['Minify'] = $this->parseBoolean(
                            $providesInfo['Minify']
                        );
                    } else {
                        $providesInfo['Minify'] = true;
                    }
                    $providesInfo['Package'] = $packageId;
                    $fileInfo[$file] = $providesInfo;
                }
            }

            $this->setCachedValue('fileInfo', $fileInfo);
        }

        return $fileInfo;
    }



    public function getCombinesInfo(): array
    {
        $combinesInfo = $this->getCachedValue('combinesInfo');
        if ($combinesInfo === false) {

            $data = $this->dataProvider->getData();
            $fileInfo = $this->getFileInfo();

            $combinesInfo = [];

            foreach ($data as $packageId => $info) {
                $combines = $this->getCombinesForPackage($packageId);
                foreach ($combines as $combine => $combineInfo) {

                    // create entry for the combine set if it does
                    // not exist
                    if (!isset($combinesInfo[$combine])) {
                        $combinesInfo[$combine] = [
                            'Includes' => [],
                            'Minify'   => true
                        ];
                    }

                    // set additional attributes
                    if (isset($combineInfo['Minify'])) {
                        $combinesInfo[$combine]['Minify'] = $this->parseBoolean(
                            $combinesInfo['Minify']
                        );
                    }

                    // add entries to the set
                    if (isset($combineInfo['Includes'])
                        && is_array($combineInfo['Includes'])
                    ) {
                        foreach ($combineInfo['Includes'] as $file) {
                            $combinesInfo[$combine]['Includes'][$file] = [
                                'explicit' => true
                            ];
                        }
                    }
                }
            }

            foreach ($combinesInfo as $combine => $info) {
                // Check for dependencies of each set that are not in the set.
                // If a missing dependency also has a dependency on an file in
                // the set, add it to the set.
                $combinesInfo[$combine]['Includes']
                    = $this->getImplicitCombinedFiles(
                        $info['Includes'],
                        $info['Includes']
                    );

                // minification of combine depends on minification of included
                // files
                foreach (array_keys($info['Includes']) as $file) {
                    if (isset($fileInfo[$file])
                        && !$fileInfo[$file]['Minify']
                    ) {
                        $combinesInfo[$combine]['Minify'] = false;
                        break;
                    }
                }
            }

            // sort largest sets first
            uasort($combinesInfo, $this->compareCombines(...));

            $this->setCachedValue('combinesInfo', $combinesInfo);
        }

        return $combinesInfo;
    }



    /**
     * Gets a flat list of file dependencies for each file
     *
     * @return array
     */
    public function getDependsInfo(): array
    {
        $dependsInfo = $this->getCachedValue('dependsInfo');
        if ($dependsInfo === false) {

            $data = $this->dataProvider->getData();

            $dependsInfo = [];

            foreach ($this->getPackageSortOrder() as $packageId => $order) {
                if (!isset($data[$packageId])) {
                    continue;
                }

                $provides = $this->getProvidesForPackage($packageId);
                foreach ($provides as $file => $fileInfo) {
                    if (!isset($dependsInfo[$file])) {
                        $dependsInfo[$file] = [];
                    }
                    if (isset($fileInfo['Depends'])) {
                        $dependsInfo[$file] = array_merge(
                            $dependsInfo[$file],
                            $fileInfo['Depends']
                        );
                    }
                    // TODO: some day we could treat optional-depends
                    // differently
                    if (isset($fileInfo['OptionalDepends'])) {
                        $dependsInfo[$file] = array_merge(
                            $dependsInfo[$file],
                            $fileInfo['OptionalDepends']
                        );
                    }
                }
            }

            $this->setCachedValue('dependsInfo', $dependsInfo);
        }

        return $dependsInfo;
    }



    protected function getImplicitCombinedFiles(
        array $filesToCheck,
        array $files
    ) {
        $dependsInfo = $this->getDependsInfo();

        // get depends
        $depends = [];
        foreach ($filesToCheck as $file => $info) {
            if (isset($dependsInfo[$file])) {
                $depends = array_merge($depends, $dependsInfo[$file]);
            }
        }

        // get depends not in the set
        $depends = array_diff($depends, array_keys($files));

        // check sub-dependencies to see any are in the set
        $implicitFiles = [];
        foreach ($depends as $file) {
            if (isset($dependsInfo[$file])) {
                $subDepends = array_intersect(
                    $dependsInfo[$file],
                    array_keys($files)
                );
                if (count($subDepends) > 0
                    && !isset($implicitFiles[$file])
                ) {
                    $files[$file] = ['explicit' => false];
                    $implicitFiles[$file] = $file;
                }
            }
        }

        // if implicit files were added, check those
        if (count($implicitFiles) > 0) {
            $files = $this->getImplicitCombinedFiles(
                $implicitFiles,
                $files
            );
        }

        return $files;
    }



    protected function getPackageSortOrder()
    {
        $packageSortOrder = $this->getCachedValue('packageSortOrder');
        if ($packageSortOrder === false) {

            $data = $this->dataProvider->getData();

            // get flat list of package dependencies for each package
            $packageDependencies = [];
            foreach ($data as $packageId => $info) {
                if (!isset($packageDependencies[$packageId])) {
                    $packageDependencies[$packageId] = [];
                }
                $depends = $this->getDependsForPackage($packageId);
                $packageDependencies[$packageId] = array_merge(
                    $packageDependencies[$packageId],
                    $depends
                );
            }

            // build into a graph
            $graph = new Concentrate_Graph();
            $nodes = [];
            foreach ($packageDependencies as $packageId => $dependencies) {
                if ($packageId === '__site__') {
                    // special package '__site__' is not sorted with other
                    // packages. It gets put at the end.
                    continue;
                }
                if (!isset($nodes[$packageId])) {
                    $nodes[$packageId] = new Concentrate_Graph_Node(
                        $graph,
                        $packageId
                    );
                }
                foreach ($dependencies as $dependentPackageId) {
                    if ($dependentPackageId === '__site__') {
                        // special package '__site__' is not sorted with other
                        // packages. It gets put at the end.
                        continue;
                    }
                    if (!isset($nodes[$dependentPackageId])) {
                        $nodes[$dependentPackageId]
                            = new Concentrate_Graph_Node(
                                $graph,
                                $dependentPackageId
                            );
                    }
                }
            }
            foreach ($packageDependencies as $packageId => $dependencies) {
                foreach ($dependencies as $dependentPackageId) {
                    $nodes[$packageId]->connectTo($nodes[$dependentPackageId]);
                }
            }

            $sorter = new Concentrate_Graph_TopologicalSorter();
            try {
                $sortedNodes = $sorter->sort($graph);
            } catch (Concentrate_CyclicDependencyException $e) {
                throw new Concentrate_CyclicDependencyException(
                    'Package dependency order can not be determined because '
                    . 'package dependencies contain one more more cycles. '
                    . 'There is likely a typo in the package dependency '
                    . 'section of one or more YAML files.'
                );
            }

            $order = [];
            foreach ($sortedNodes as $node) {
                $order[] = $node->getData();
            }

            // special package __site__ is always counted last by default
            $order[] = '__site__';

            // return indexed by package id, with values being the relative
            // sort order
            $packageSortOrder = array_flip($order);

            $this->setCachedValue('packageSortOrder', $packageSortOrder);
        }

        return $packageSortOrder;
    }



    protected function getProvidesForPackage($packageId): array
    {
        $cacheKey = 'packageProvides.'.$packageId;
        $packageProvides = $this->getCachedValue($cacheKey);
        if ($packageProvides === false) {
            $packageProvides = [];

            $data = $this->dataProvider->getData();
            if (isset($data[$packageId])) {
                $info = $data[$packageId];
                if (isset($info['Provides']) && is_array($info['Provides'])) {
                    $packageProvides = $info['Provides'];
                }
            }

            $this->setCachedValue($cacheKey, $packageProvides);
        }

        return $packageProvides;
    }



    protected function getDependsForPackage($packageId): array
    {
        $cacheKey = 'packageDepends.'.$packageId;
        $packageDepends = $this->getCachedValue($cacheKey);
        if ($packageDepends === false) {
            $packageDepends = [];

            $data = $this->dataProvider->getData();
            if (isset($data[$packageId])) {
                $info = $data[$packageId];
                if (isset($info['Depends']) && is_array($info['Depends'])) {
                    $packageDepends = $info['Depends'];
                }
            }

            $this->setCachedValue($cacheKey, $packageDepends);
        }

        return $packageDepends;
    }



    protected function getCombinesForPackage($packageId): array
    {
        $cacheKey = 'packageCombines.'.$packageId;
        $packageCombines = $this->getCachedValue($cacheKey);
        if ($packageCombines === false) {
            $packageCombines = [];

            $data = $this->dataProvider->getData();
            if (isset($data[$packageId])) {
                $info = $data[$packageId];
                if (isset($info['Combines']) && is_array($info['Combines'])) {
                    $packageCombines = $info['Combines'];
                }
            }

            $this->setCachedValue($cacheKey, $packageCombines);
        }

        return $packageCombines;
    }



    protected function compareCombines(array $combine1, array $combine2): int
    {
        if (count($combine1['Includes']) < count($combine2['Includes'])) {
            return 1;
        }

        if (count($combine1['Includes']) > count($combine2['Includes'])) {
            return -1;
        }

        return 0;
    }



    protected function getCachedValue($key)
    {
        $this->cache->setPrefix($this->dataProvider->getCachePrefix());
        return $this->cache->get($key);
    }



    protected function setCachedValue($key, $value)
    {
        $this->cache->setPrefix($this->dataProvider->getCachePrefix());
        return $this->cache->set($key, $value);
    }



    protected function parseBoolean($string): bool
    {
        return match (mb_strtolower($string)) {
            'no', 'false', 'off', '0' => false,
            default => true,
        };
    }

}

?>
