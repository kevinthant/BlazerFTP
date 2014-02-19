<?php
/* 
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */
class BlazerFTP {
    const ACTIVE_MODE = 1;
    const PASSIVE_MODE = 2;
    
    public function __construct(){
        
    }


    public function connect($host, $username, $pass, $mode = BlazerFTP::PASSIVE_MODE){
        
    }

    public function sync($localDir, $destinationPath, $remoteDirCloned = null){

        $treeA = self::buildHashTree($localDir);
        
        if(!empty($remoteDirCloned) && is_dir($remoteDirCloned)){
            $treeB = self::buildHashTree($remoteDirCloned);
        }
        else{
            //TODO get hash file from remote FTP
        }

        

        $tasks = array();
        $branchQue = array(DIRECTORY_SEPARATOR);

        
        while(!empty($branchQue)){

            $branchPath = array_shift($branchQue);

            $branchTreeA = self::getTreeByPath($treeA, $branchPath);
            $branchTreeB = self::getTreeByPath($treeB, $branchPath);

            $AList = array_keys($branchTreeA);
            $BList = array_keys($branchTreeB);

            
            $DList = array_diff($BList, $AList); //Delete files list
            $NList = array_diff($AList, $BList); //New files list
            $MList = array(); //modified file list
            $CList = array_diff($AList, $NList); //all the files in A that are common in B as well
            
            foreach($CList as $Fi){

                if(self::getNodeType($branchTreeA[$Fi]) !== self::getNodeType($branchTreeB[$Fi])){
                    $DList[] = $Fi; //delete first on B
                    $NList[] = $Fi; //use the one from A as new
                }
                else if(self::getNodeType($branchTreeA[$Fi]) == 'directory'){
                     $branchQue[] = $branchPath .  $Fi . DIRECTORY_SEPARATOR;
                     
                }
                else if(!self::nodeEqual($branchTreeA[$Fi], $branchTreeB[$Fi])){

                    $MList[] = $Fi; //add to modified file list
                    
                }
            }

            //proccess the DList, NList, MList
            foreach($DList as $filename){
                $tasks[$branchPath .  $filename] = 'D';
            }
            foreach($MList as $filename){
                $tasks[$branchPath .  $filename] = 'M';
            }
            foreach($NList as $filename){
                $tasks[$branchPath . $filename] = 'A';
            }

         
            
        }


        var_dump($tasks);
    }

    public static function buildHashFlat($localDir, $recursive = true, $ignoreList = array('.', '..', '.svn', '.git')){

        if(!file_exists($localDir)){
            return null;
        }

        $startingPath = trim($localDir);
        $startingPathLen = strlen($startingPath);
        $output = array();
        $dirQue = array($localDir);

        while(!empty($dirQue)){
            $dir = array_shift($dirQue);
      
            $files = scandir($dir);
            foreach($files as $i => $filename){

                if(in_array($filename, $ignoreList)) continue;

                $fullpath = $dir . DIRECTORY_SEPARATOR . $filename;
                $isDir = is_dir($fullpath);

                $output[substr($fullpath, $startingPathLen)] = array(
                    'isDir' => $isDir ? 1 : 0,
                    'hash' => !$isDir ? sha1_file($fullpath) : null,
                    'size' => filesize($fullpath)
                );

                if($isDir && $recursive){
                    $dirQue[] = $fullpath;
                }
            }
        }


        return $output;
        
    }


    public static function buildHashTree($localDir, $recursive = true, $ignoreList = array('.', '..', '.svn', '.git')){

        if(!file_exists($localDir)){
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

        while(!empty($dirQue)){
            $dir = array_shift($dirQue);


            $files = scandir($dir);
            foreach($files as $i => $filename){

                if(in_array($filename, $ignoreList)) continue;

                $relativePath = substr($dir, $startingPathLen + 1);
                $fullpath = $dir . DIRECTORY_SEPARATOR . $filename;
                $isDir = is_dir($fullpath);

                if($isDir){
                    self::updateTreeByPath($output, $relativePath, $filename, array('children' => array()));
                }
                else{
                    self::updateTreeByPath($output, $relativePath, $filename, array(
                        'hash' => !$isDir ? sha1_file($fullpath) : null,
                        'size' => filesize($fullpath)
                    ));

                }


                if($isDir && $recursive){
                    $dirQue[] = $fullpath;
                }
            }


        }

        return $output;
    }

    public static function updateTreeByPath(&$A, $path, $key, $value){
        $el = &$A['children'];

        $paths = explode(DIRECTORY_SEPARATOR, $path);

        foreach($paths as $index){
            if(empty($index)) continue;
            $el = &$el[$index]['children'];
        }


        $el[$key] = $value;

        return $el;
    }

    public static function getTreeByPath($A, $path){
        $path = trim($path);
        $tree = $A['children'];
        
        $paths = explode(DIRECTORY_SEPARATOR, $path);
        foreach($paths as $index){
            if(empty($index)) continue;
            $tree = $tree[$index]['children'];
        }
        
        return $tree;
    }

    public static function getNodeType($node){
        return (isset($node['children']) && is_array($node['children'])) ? 'directory' : 'file';
    }

    public static function nodeEqual($node1, $node2){

        $node1Type = self::getNodeType($node1);
        $node2Type = self::getNodeType($node2);

        //if both are directory, always regard it as equal
        if($node1Type == $node2Type && $node1Type == 'directory'){
            return true;
        }

        return $node1['hash'] == $node2['hash'] && $node1['size'] == $node2['size'];
    }
}

/*
$time_start = microtime(true);
$output = BlazerFTP::buildHash('C:\Projects\fidelitynfs');
$time_end = microtime(true);
//dividing with 60 will give the execution time in minutes other wise seconds
$execution_time = ($time_end - $time_start);

//execution time of the script
echo '<b>Total Execution Time:</b> '.number_format($execution_time).' seconds <br/>';

print_r($output); */

$ftp = new BlazerFTP();
$ftp->sync('C:\wamp\www\dummyA', null, 'C:\wamp\www\dummyB');