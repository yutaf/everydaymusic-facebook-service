<?php

class FacebooksRepositoryTest extends PHPUnit_Framework_TestCase
{

    protected static $dbManager;

    public static function setUpBeforeClass()
    {
        self::$dbManager = new Yutaf\DbManager();
        self::$dbManager->connect($_ENV);
    }

    public function testFetchByFacebookUserId()
    {
        $facebook_user_id = '904535809601886';
        $result = self::$dbManager->get('Facebooks')->fetchByFacebookUserId($facebook_user_id);
    }
}