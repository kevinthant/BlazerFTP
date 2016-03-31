<?php

require_once BASE_DIR . '/BlazerFTP.php';
/**
 * Description of BlazerFTPTest
 *
 * @author kevin.thant
 */
class BlazerFTPTest extends PHPUnit_Framework_TestCase {
    
    public function setUp() {
        parent::setUp();
    }
    
    public function testTransferModes(){
        $class = new \ReflectionClass('BlazerFTP');
        $constants = $class->getConstants();
        
        $this->assertArrayHasKey('ACTIVE_MODE', $constants);
        $this->assertArrayHasKey('PASSIVE_MODE', $constants);
    }
    
    
}
