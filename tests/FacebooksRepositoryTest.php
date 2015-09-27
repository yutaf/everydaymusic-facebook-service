<?php

class FacebooksRepositoryTest extends PHPUnit_Framework_TestCase
{

    protected static $dbManager;

    public static function setUpBeforeClass()
    {
        self::$dbManager = new Yutaf\DbManager();
        self::$dbManager->connect($_ENV);
    }

    public function testFetchByConditions()
    {
        $facebook_user_id = '904535809601886';
        $conditions_facebooks = array(
            'wheres' => array(
                'facebook_user_id' => $facebook_user_id,
            ),
        );
        $result = self::$dbManager->get('Facebooks')->fetchByConditions($conditions_facebooks);
    }
}