<?php

require_once 'BlazerFTP.php';
/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/*
$time_start = microtime(true);
$output = BlazerFTP::buildHash('C:\Projects\fidelitynfs');
$time_end = microtime(true);
//dividing with 60 will give the execution time in minutes other wise seconds
$execution_time = ($time_end - $time_start);

//execution time of the script
echo '<b>Total Execution Time:</b> '.number_format($execution_time).' seconds <br/>';

print_r($output); */


$ftp = new BlazerFTP('93.188.160.80', 'u516621118', 'blazer123');

//$ftp->sync('C:\wamp\www\dummyA', null, 'C:\wamp\www\dummyB');
$ftp->sync('C:\Users\Kevin\Documents\GitHub\lawfirm', '/public_html');