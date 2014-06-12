<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 * Author: Kevin Thant (2014)
 */

class BlazerFTP {

    const ACTIVE_MODE = 1;
    const PASSIVE_MODE = 2;

    private $_host = null;
    private $_username = null;
    private $_pass = null;
    private $_mode = null;
    private $_tmpDir = null;
    private $_transferMode = FTP_BINARY;
    private $_conn = null;

    public function __construct($host, $username, $pass, $mode = BlazerFTP::PASSIVE_MODE, $transferMode = FTP_BINARY, $tmpDir = null) {
        $this->_host = $host;
        $this->_username = $username;
        $this->_pass = $pass;
        $this->_mode = $mode;
        $this->_transferMode = $transferMode;

        if ($tmpDir && is_dir($tmpDir) && is_writable($tmpDir)) {
            $this->_tmpDir = $tmpDir;
        } else {
            $this->_tmpDir = sys_get_temp_dir();
        }
    }

    public function getTransferMode() {
        return $this->_transferMode;
    }

    public function setTransferMode($transferMode) {
        $this->_transferMode = $transferMode;
    }

    public function getConnectionMode() {
        return $this->_mode;
    }

    public function setConnectionMode($mode) {
        $this->_mode = $mode;
    }

    public function connect($port = 21) {

        if ($this->_conn) {
            return;
        }

        $this->_conn = ftp_connect($this->_host, $port);

        if (!ftp_login($this->_conn, $this->_username, $this->_pass)) {
            throw new Exception(sprintf('Unable to connect to FTP host %s with given username (%s) and password (%s)', $this->_host, $this->_username, $this->_pass));
        }

        if ($this->_mode === BlazerFTP::PASSIVE_MODE) {
            ftp_pasv($this->_conn, true);
        }
    }

    public function disconnect() {
        if (!$this->_conn) {
            return;
        }

        ftp_close($this->_conn);
        $this->_conn = null;
    }

    public function uploadDirectory($localDir, $remoteDir, $clearFirst = true) {

        if (!is_dir($localDir)) {
            throw new Exception(sprintf('%s is not a directory', $localDir));
        }

        if ($clearFirst) {
           $this->_ftpRecursiveDelete($remoteDir);
           ftp_mkdir($this->_conn, $remoteDir);
        }
        else if(!@ftp_chdir ($this->_conn, $remoteDir)){
             ftp_mkdir($this->_conn, $remoteDir);
        }
        

        $this->_ftpUploadAll($localDir, $remoteDir);
    }

    public function sync($localDir, $remoteDir, $remoteDirCloned = null) {

        if(!is_dir($localDir)){
            throw new Exception(sprintf('Invalid local directory given: %s', $localDir));
        }
        
        $treeA = self::buildHashTree($localDir);

        if (!empty($remoteDirCloned) && is_dir($remoteDirCloned)) {
            $treeB = self::buildHashTree($remoteDirCloned);
        } else {
            //TODO get hash file from remote FTP
            $this->connect();
            $hashFile = tempnam($this->_tmpDir, 'BlazerFTP_');

            if (!$hashFile) {
                throw new Exception(sprintf('Unable to create a temporary hash file %s for comparing contents in synchronizing local and remote FTP directories', $hashFile));
            }


            if (!@ftp_get($this->_conn, $hashFile, $remoteDir .'/BlazerFTP.json', FTP_BINARY)) {
                $this->uploadDirectory($localDir, $remoteDir, true);
                file_put_contents($hashFile, json_encode($treeA));
                ftp_put($this->_conn, $remoteDir .'/BlazerFTP.json', $hashFile, FTP_BINARY);
                return;
            }

            $treeB = json_decode(file_get_contents($hashFile), true);

            if (!$treeB) {

                $this->uploadDirectory($localDir, $remoteDir, true);
                file_put_contents($hashFile, json_encode($treeA));
                ftp_put($this->_conn, $remoteDir . '/BlazerFTP.json', $hashFile, FTP_BINARY);
                return;
            }
          
            unlink($hashFile);
        }

       

        $tasks = array();
        $branchQue = array(DIRECTORY_SEPARATOR);
        

        while (!empty($branchQue)) {

            $branchPath = array_shift($branchQue);

            $branchTreeA = self::getTreeByPath($treeA, $branchPath);
            $branchTreeB = self::getTreeByPath($treeB, $branchPath);

            $AList = array_keys($branchTreeA);
            $BList = array_keys($branchTreeB);


            $DList = array_diff($BList, $AList); //Delete files list
            $NList = array_diff($AList, $BList); //New files list
            $MList = array(); //modified file list
            $CList = array_diff($AList, $NList); //all the files in A that are common in B as well

            foreach ($CList as $Fi) {

                if (self::getNodeType($branchTreeA[$Fi]) !== self::getNodeType($branchTreeB[$Fi])) {
                    $DList[] = $Fi; //delete first on B
                    $NList[] = $Fi; //use the one from A as new
                } else if (self::getNodeType($branchTreeA[$Fi]) == 'directory') {
                    $branchQue[] = $branchPath . $Fi . DIRECTORY_SEPARATOR;
                } else if (!self::nodeEqual($branchTreeA[$Fi], $branchTreeB[$Fi])) {

                    $MList[] = $Fi; //add to modified file list
                }
            }

            //proccess the DList, NList, MList
            foreach ($DList as $filename) {
                $tasks[$branchPath . $filename] = 'D';
            }
            foreach ($MList as $filename) {
                $tasks[$branchPath . $filename] = 'M';
            }
            foreach ($NList as $filename) {
                $tasks[$branchPath . $filename] = 'A';
            }
        }

        $this->_processSyncTasks($tasks, $localDir, $remoteDir);
        
        //Update the remote with new hash file
        $newHashFile =  tempnam($this->_tmpDir, 'BlazerFTP_');
        file_put_contents($newHashFile, json_encode($treeA));
        ftp_put($this->_conn, $remoteDir .'/BlazerFTP.json', $newHashFile, FTP_BINARY);
        
    }

    public static function buildHashFlat($localDir, $recursive = true, $ignoreList = array('.', '..', '.svn', '.git')) {

        if (!file_exists($localDir)) {
            return null;
        }

        $startingPath = trim($localDir);
        $startingPathLen = strlen($startingPath);
        $output = array();
        $dirQue = array($localDir);

        while (!empty($dirQue)) {
            $dir = array_shift($dirQue);

            $files = scandir($dir);
            foreach ($files as $i => $filename) {

                if (in_array($filename, $ignoreList))
                    continue;

                $fullpath = $dir . DIRECTORY_SEPARATOR . $filename;
                $isDir = is_dir($fullpath);

                $output[substr($fullpath, $startingPathLen)] = array(
                    'isDir' => $isDir ? 1 : 0,
                    'hash' => !$isDir ? sha1_file($fullpath) : null,
                    'size' => filesize($fullpath)
                );

                if ($isDir && $recursive) {
                    $dirQue[] = $fullpath;
                }
            }
        }


        return $output;
    }

    public static function buildHashTree($localDir, $recursive = true, $ignoreList = array('.', '..', '.svn', '.git')) {

        if (!file_exists($localDir)) {
            return null;
        }

        $startingPath = trim($localDir);
        $startingPathLen = strlen($startingPath);
        $output = array(
            'children' => array(
            /*
              'file1' => array('hash' => 'xxx'),
              'dir1' => array('children') */
            )
        );
        $dirQue = array($localDir);

        while (!empty($dirQue)) {
            $dir = array_shift($dirQue);


            $files = scandir($dir);
            foreach ($files as $i => $filename) {

                if (in_array($filename, $ignoreList))
                    continue;

                $relativePath = substr($dir, $startingPathLen + 1);
                $fullpath = $dir . DIRECTORY_SEPARATOR . $filename;
                $isDir = is_dir($fullpath);

                if ($isDir) {
                    self::updateTreeByPath($output, $relativePath, $filename, array('children' => array()));
                } else {
                    self::updateTreeByPath($output, $relativePath, $filename, array(
                        'hash' => !$isDir ? sha1_file($fullpath) : null,
                        'size' => filesize($fullpath)
                    ));
                }


                if ($isDir && $recursive) {
                    $dirQue[] = $fullpath;
                }
            }
        }

        return $output;
    }

    public static function updateTreeByPath(&$A, $path, $key, $value) {
        $el = &$A['children'];

        $paths = explode(DIRECTORY_SEPARATOR, $path);

        foreach ($paths as $index) {
            if (empty($index))
                continue;
            $el = &$el[$index]['children'];
        }


        $el[$key] = $value;

        return $el;
    }

    public static function getTreeByPath($A, $path) {
        $path = trim($path);
        $tree = $A['children'];

        $paths = explode(DIRECTORY_SEPARATOR, $path);
        foreach ($paths as $index) {
            if (empty($index))
                continue;
            $tree = $tree[$index]['children'];
        }

        return $tree;
    }

    public static function getNodeType($node) {
        return (isset($node['children']) && is_array($node['children'])) ? 'directory' : 'file';
    }

    public static function nodeEqual($node1, $node2) {

        $node1Type = self::getNodeType($node1);
        $node2Type = self::getNodeType($node2);

        //if both are directory, always regard it as equal
        if ($node1Type == $node2Type && $node1Type == 'directory') {
            return true;
        }

        return $node1['hash'] == $node2['hash'] && $node1['size'] == $node2['size'];
    }

    private function _processSyncTasks(array $tasks, $localDir, $remoteDir){
       
       foreach($tasks as $filename => $action){
           switch($action){
               case 'A':
               case 'M':
                   $this->_syncUpload($filename, $localDir, $remoteDir);
                   break;
               
               case 'D':
                   $this->_syncDelete($filename, $remoteDir);
                   break;
           }
       }
    }
    
    private function _isFtpDir($dir) {

        // get current directory
        $original_directory = ftp_pwd($this->_conn);
        // test if you can change directory to $dir
        // suppress errors in case $dir is not a file or not a directory
        if (@ftp_chdir($this->_conn, $dir)) {
            // If it is a directory, then change the directory back to the original directory
            ftp_chdir($this->_conn, $original_directory);
            return true;
        } else {
            return false;
        }
    }

    function _syncUpload($filename, $localDir, $remoteDir){
        
        $localFile = $localDir . $filename;
        $remoteFile = $remoteDir . str_replace('\\', '/', $filename);
        
        if(is_file($localFile)){
            if($this->_isFtpDir($remoteFile)){
                $this->_ftpRecursiveDelete($remoteFile);
            }
            ftp_put($this->_conn, $remoteFile, $localFile, $this->_transferMode);
        }
        else if(is_dir($localDir . $filename)){
            if(!$this->_isFtpDir($remoteFile)){
                @ftp_delete($this->_conn, $remoteFile);
            }
            else{
                $this->_ftpRecursiveDelete($remoteFile);
            }
        }
        
        $this->_ftpUploadAll($localFile, $remoteFile);
    }
    
    function _syncDelete($filename, $remoteDir){
        
        $remoteFile = $remoteDir . $filename;
        $this->_ftpRecursiveDelete($remoteFile);
    }
    
    function _ftpUploadAll($src_dir, $dst_dir) {
        
        if(is_file($src_dir)){
            ftp_put($this->_conn, $dst_dir, $src_dir, $this->_transferMode);
            return;
        }
        
        $d = dir($src_dir);
        while ($file = $d->read()) { // do this for each file in the directory
            if ($file != "." && $file != "..") { // to prevent an infinite loop
                if (is_dir($src_dir . "/" . $file)) { // do the following if it is a directory
                    if (!@ftp_chdir($this->_conn, $dst_dir . "/" . $file)) {
                        ftp_mkdir($this->_conn, $dst_dir . "/" . $file); // create directories that do not yet exist
                    }
                    $this->_ftpUploadAll($src_dir . "/" . $file, $dst_dir . "/" . $file); // recursive part
                } else {
                    $upload = ftp_put($this->_conn, $dst_dir . "/" . $file, $src_dir . "/" . $file, $this->_transferMode); // put the files
                }
            }
        }
        $d->close();
    }
    
    private function _ftpRecursiveDelete($directory){
        $handle = $this->_conn;
        if( !(@ftp_rmdir($handle, $directory) || @ftp_delete($handle, $directory)) )
        {            
            # if the attempt to delete fails, get the file listing
            $filelist = @ftp_nlist($handle, $directory);
            
            // var_dump($filelist);exit;
            # loop through the file list and recursively delete the FILE in the list
            foreach($filelist as $file) {  
                if($file === '.' || $file === '..') continue;
                $this->_ftpRecursiveDelete($directory . '/' . $file);   
            }
            $this->_ftpRecursiveDelete($directory);
        }
    }
}
