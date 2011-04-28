#!/usr/bin/php
<?php
$base           = '/Users/rgranadino/Downloads/'; 
$upgradePath    = $base .'1.10.1.0';
$oldPath        = $base. '1.10.0.1';
$storePath      = '/Users/rgranadino/Documents/Projects/MyProject/';


/* @var $mu mageUpgrade */
$mu     = mageUpgrade::getInstance();
$mu->diffVersions($oldPath, $upgradePath);
$mu->addLocalDir(mageUpgrade::DIR_CODE, $storePath.'app/code/local');
$mu->addLocalDir(mageUpgrade::DIR_THEME_PACKAGE, $storePath.'app/design/frontend/myproject');
$mu->findUpdates();
$mu->getCounts();

exit(0);

/**
 * mageUpgrade class
 */
class mageUpgrade {
    /**
     * updated file type constants
     */
    const FILE_UPDATED  = 'updated';
    const FILE_NEW      = 'new';
    const FILE_DELETED  = 'deleted';
    /**
     * local directory type constants
     */
    const DIR_CODE      = 'code';
    const DIR_SKIN      = 'skin';
    const DIR_THEME     = 'theme';
    const DIR_THEME_PACKAGE = 'package';
    /**
     * updated files array
     * @param array
     */
    private $files      = null;
    /**
     * local directory array
     * @param array
     */
    private $localDirs  = null;
    /**
     * old path
     * @param str
     */
    private $oldPath    = '';
    /**
     * new path
     * @param str
     */
    private $newPath    = null;
    /**
     * object instance
     * @param mageUpgrade
     */
    protected static $instance = null;
    /**
     * constructor, set up file and directory arrays
     */
    protected function __construct()
    {
        $this->files        = array(
            self::FILE_UPDATED  => array(),
            self::FILE_NEW      => array(),
            self::FILE_DELETED  => array()
        );
        $this->localDirs    = array(
            self::DIR_CODE  => array(),
            self::DIR_THEME  => array(),
            self::DIR_SKIN  => array()
        );
    }
    /**
     * get object instance
     * @return mageUpgrade
     */
    public static function getInstance()
    {
        if (self::$instance == null) {
            self::$instance = new mageUpgrade();
        }
        return self::$instance;
    }
    /**
     * compare two magento directories
     * @param str $v1
     * @param str $v2
     * @return mageUpgrade
     */
    public function diffVersions($v1, $v2)
    {
        $cacheName = 'magediff.tmp';
        if (file_exists($cacheName)) {
            echo "READING FROM CACHE...$cacheName\n";
            $data   = file_get_contents($cacheName);
            $output = unserialize($data);
        }
        if (empty($output)) {
            $cmd        = "diff -rq {$v1} {$v2}";
            echo "Running Command: `{$cmd}`....\n";
            exec($cmd, $output);
            file_put_contents($cacheName, serialize($output));
        }
        foreach ($output as $line) {
            $diffPattern    = '#^Files (.*) and (.*) differ$#';
            if (preg_match($diffPattern, $line, $matches)) {
                //FILE UPDATED
                $relPath    = str_replace($v1, '', trim($matches[1]));
                $this->addFile(mageUpgrade::FILE_UPDATED, $relPath);
                $updates['updated'][] = $relPath;
            } else if (strpos($line, "Only in {$v1}") !== false) {
                $relPath    = str_replace( "Only in {$v1}", '', $line);
                $this->addFile(mageUpgrade::FILE_DELETED, $relPath);
            } else if (strpos($line, "Only in {$v2}") !== false) {
                $relPath    = str_replace("Only in {$v2}", '', $line);
                $this->addFile(mageUpgrade::FILE_NEW, $relPath);
            }
        }
        $this->oldPath = $v1;
        $this->newPath = $v2;
        return $this;
    }
    /**
     * add updated file
     * @param str $key
     * @param str $path
     * @throws Exception
     * @return mageUpgrade
     */
    public function addFile($key, $path)
    {
        $path       = trim($path);
        $keys   = array(self::FILE_UPDATED, self::FILE_NEW, self::FILE_DELETED);
        if (!in_array($key, $keys)) {
            throw new Exception('Invalid Key: '.$key);
        }
        $wasUpdated = $this->wasFileUpdated($path);
        if ($wasUpdated !== false) {
            throw new Exception("Something might have gone wrong, $path has already been marked as updated: $wasUpdated");
        }
        $this->files[$key][] = $path;
        return $this;
    }
    /**
     * check if a relative path has been upgraded
     * works on local paths, returns update type or false
     * @param str $path
     * @return str
     */
    public function wasFileUpdated($path)
    {
        foreach ($this->files as $key => $files) {
            if (in_array($path, $files)) {
                return $key;
            }
        }
        return false;
    }
    /**
     * get count of changed magento files from one version to the next
     */
    public function getCounts()
    {
        echo "File Changed In Magento: ";
        foreach ($this->files as $key => $files) {
            echo "$key: ".count($files).' ';
        }
        echo "\n";
    }
    /**
     * scan theme directory for changes
     */
    public function scanThemeDir($path)
    {
        $files      = $this->_scanDir($path);
        $corePaths  = array('/app/design/frontend/base/default', '/app/design/frontend/enterprise/default');
        foreach ($files as $file) {
            $relPath = str_replace($path, '' , $file);
            foreach ($corePaths as $corePath) {
                $wasUpdated = $this->wasFileUpdated(trim($corePath.$relPath));
                if ($wasUpdated) {
                    $this->_sayReview($file, $corePath.$relPath, $wasUpdated);
                }
            }
        }
    }
    protected function _sayReview($localPath, $relPath, $type, $includeLocalDiff = true)
    {
        echo "Needs Review: {$localPath} [$type]\n";
        if ($type == self::FILE_UPDATED) {
            $oldPath    = realpath($this->oldPath.'/'.$relPath);
            $newPath    = realpath($this->newPath.'/'.$relPath);
            $cmd        = "\t vimdiff -O {$oldPath} {$newPath}";
            if ($includeLocalDiff) {
                $cmd .= " {$localPath}";
            }
            echo $cmd . "\n\n";
        }
        echo "\n";
    }
    /**
     * scan code directory for changes
     * will tokenize php files and file core classes that are extended and have been updated
     * @param str $path
     * @todo parse xml files for rewrites?
     */
    public function scanCodeDir($path)
    {
        $files      = $this->_scanDir($path);
        $coreFiles  = array();
        foreach ($files as $file) {
            $ext =   substr($file, -3);
            if ($ext == 'php') {
                $source         = file_get_contents($file);
                $tokens         = token_get_all($source);
                $saveNextString = false;
                $as             = null;
                foreach ($tokens as $token) {
                    if (is_array($token)) {
                        list($id, $text) = $token;
                        if ($id == T_EXTENDS) {
                            $as             = 'object';
                            $saveNextString = true;
                        } else if ($id == T_STRING && $saveNextString) {
                            if (preg_match('/^(Varien|Mage|Enterprise)/', $text, $matches)) {
                                if ($matches[1] == 'Varien') {
                                    $coreBase   = '/lib/';
                                } else {
                                    $coreBase   = '/app/code/core/';
                                }
                                $corePath = $coreBase.str_replace('_', '/', $text).'.php';
                                $wasUpdated = $this->wasFileUpdated($corePath);
                                if ($wasUpdated) {
                                    $coreFiles[$file] = array('local'=> $file, 'core'=> $corePath, 'what'=> $wasUpdated) ;
                                }
                            }
                            $saveNextString = false;
                        }
                    }
                };
            } else if ($ext == '.xml') {
                //$sxe = new SimpleXMLElement($xmlstr);
            }
        }
        foreach ($coreFiles as $info) {
            $this->_sayReview($info['local'], $info['core'], $info['what'], false);
        }
    }
    /**
     * scan skin directory for changes
     * @param str $path
     * @todo implement?
     */
    public function scanSkinDir($path)
    {
        echo "IMPLEMENT SKIN DIFF\n";
    }
    /**
     * add local directory to scan for changes
     * @param str $type
     * @param str path
     * @return mageUpgrade
     */
    public function addLocalDir($type, $path)
    {
        if (!is_readable($path)) {
            throw new Exception("Cannot read path: $path");
        }
        $types = array(self::DIR_CODE, self::DIR_SKIN, self::DIR_THEME, self::DIR_THEME_PACKAGE);
        if (!in_array($type, $types)) {
            throw new Exception("Invalid dir type: $type");
        }
        if ($type == self::DIR_THEME_PACKAGE) {
            $dirs = $this->_scanDir($path, true, 1);
            foreach ($dirs as $dir) {
                $this->localDirs[self::DIR_THEME][] = realpath($dir);
            }
        } else {
            $this->localDirs[$type][] = realpath($path);
        }
        return $this;
    }
    /**
     * find files to update based on added local dirs
     */
    public function findUpdates()
    {
        foreach ($this->localDirs as $type => $paths) {
            foreach ($paths as $path) {
                switch ($type) {
                    case self::DIR_CODE:
                        $this->scanCodeDir($path);
                    break;
                    case self::DIR_SKIN:
                        $this->scanSkinDir($path);
                    break;
                    case self::DIR_THEME:
                        $this->scanThemeDir($path);
                    break;
                }
            }
        }
    }
    /**
     * get directory files
     *  default behavior is to recursively get only file names
     * @param str $path
     * @param bool $includeDirs
     * @param int $maxDepth
     * @return array 
     */
    protected function _scanDir($path, $includeDirs = false, $maxDepth = null)
    {
        $files  = array();
        $d      = dir($path);
        static $depth = 1;
        while (false !== ($entry = $d->read())) {
            if ($entry == '.' || $entry == '..') {
                continue;
            }
            $fullPath = realpath($path.'/'.$entry);
            if (is_dir($fullPath)) {
                $depth++;
                if ($maxDepth == null || $depth <= $maxDepth) {
                    $subFiles    = $this->_scanDir($fullPath, $includeDirs, $maxDepth);
                    $files       = array_merge($files, $subFiles);
                }
                $depth--;
                if ($includeDirs) {
                    $files[]    = $fullPath;
                }
            } else {
                $files[]     = $fullPath;
            }
        }
        $d->close();
        return $files;
    }
}

