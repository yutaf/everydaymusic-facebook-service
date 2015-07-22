<?php

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/bootstrap.php';

$fb = new Facebook\Facebook([
    'app_id' => '1576810885918079',
    'app_secret' => '95e4807ef31f33d54511f36a6c2fb48a',
    'default_graph_version' => 'v2.4',
]);

$helper = $fb->getJavaScriptHelper();
try {
    $accessToken = $helper->getAccessToken();
    if (isset($accessToken)) {
        // Logged in
        $fb->setDefaultAccessToken($accessToken);
        $response_user = $fb->get('/me?fields=id,name,email,first_name,last_name,gender,locale,timezone');
        $response_user_decodedBody = $response_user->getDecodedBody();
        $response_music = $fb->get('/me/music?limit=1000');
        $response_music_decodedBody = $response_music->getDecodedBody();
        //TODO email address が登録されていない時の処理
        $datetime_now = date('Y-m-d H:i:s');

        $dbManager = new DbManager();
        $dbManager->connect($_ENV);
        $dbManager->beginTransaction();

        $values_users = array(
            'email' => $response_user_decodedBody['email'],
            'name' => $response_user_decodedBody['name'],
            'first_name' => $response_user_decodedBody['first_name'],
            'last_name' => $response_user_decodedBody['last_name'],
            'gender' => $response_user_decodedBody['gender'],
            'locale' => $response_user_decodedBody['locale'],
            'timezone' => $response_user_decodedBody['timezone'],
            'fetch_cnt' => 1,
            'delivery_time' => '08:00:00',
            'delivery_interval' => '24:00:00',
            'is_active' => true,
            'created_at' => $datetime_now,
            'updated_at' => $datetime_now,
        );
        $dbManager->get('Users')->insert($values_users);
        $user_id = $dbManager->getLastInsertId();

        $values_facebooks = array(
            'user_id' => $user_id,
            'facebook_user_id' => $response_user_decodedBody['id'],
            'created_at' => $datetime_now,
            'updated_at' => $datetime_now,
        );
        $dbManager->get('Facebooks')->insert($values_facebooks);

        $dbManager->rollBack();
//        $dbManager->commit();
    }

} catch(Facebook\Exceptions\FacebookResponseException $e) {
    // When Graph returns an error
    echo 'Graph returned an error: ' . $e->getMessage();

    // code : message
    // 100 : This authorization code has been used.

    exit;
} catch(Facebook\Exceptions\FacebookSDKException $e) {
    // When validation fails or other local issues
    echo 'Facebook SDK returned an error: ' . $e->getMessage();
    exit;
} catch(PDOException $e) {
    echo 'Database error: ' . $e->getMessage();
    exit;
}