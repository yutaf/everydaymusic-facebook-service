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
    if(! isset($accessToken)) {
        // redirect to login page
        $scheme = Url::getScheme();
        header("Location: {$scheme}://".$_SERVER['HTTP_HOST'].'/login', true, 302);
        exit;
    }

    // Logged in
    $fb->setDefaultAccessToken($accessToken);
    // get user profile
    $response_user = $fb->get('/me?fields=id,name,email,first_name,last_name,gender,locale,timezone');
    $response_user_decodedBody = $response_user->getDecodedBody();
    $facebook_user_id = $response_user_decodedBody['id'];

    // db
    $dbManager = new DbManager();
    $dbManager->connect($_ENV);

    // check if facebook_user_id is registered
    $facebooks_row = $dbManager->get('Facebooks')->fetchByFacebookUserId($facebook_user_id);
    if($facebooks_row) {
        // redirect to list page
        $scheme = Url::getScheme();
        header("Location: {$scheme}://".$_SERVER['HTTP_HOST'].'/list', true, 302);
        exit;
    }

    // transaction
    $dbManager->beginTransaction();

    //TODO email address が登録されていない時の処理
    $datetime_now = date('Y-m-d H:i:s');

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

    // get music data
    $response_music = $fb->get('/me/music?limit=1000');
    $response_music_decodedBody = $response_music->getDecodedBody();
    $music_lists = $response_music_decodedBody['data'];
    if(! isset($music_lists) || ! is_array($music_lists) || count($music_lists) === 0) {
        // commit
        $dbManager->rollBack();
//        $dbManager->commit();

        // redirect to list page
        $scheme = Url::getScheme();
        header("Location: {$scheme}://".$_SERVER['HTTP_HOST'].'/list', true, 302);
        exit;
    }

    // commit
    $dbManager->rollBack();
//    $dbManager->commit();

} catch(Facebook\Exceptions\FacebookResponseException $e) {
    // When Graph returns an error
    $message = 'Graph returned an error: ' . $e->getMessage();
    printErrorPage($message);
    exit;
} catch(Facebook\Exceptions\FacebookSDKException $e) {
    // When validation fails or other local issues
    $message = 'Facebook SDK returned an error: ' . $e->getMessage();
    printErrorPage($message);
    exit;
} catch(PDOException $e) {
    $message = 'Database error: ' . $e->getMessage();
    printErrorPage($message);
    exit;
} catch(Exception $e) {
    $message = 'Error: ' . $e->getMessage();
    printErrorPage($message);
    exit;
}

function printErrorPage($message)
{
    echo <<<EOL
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Document</title>
</head>
<body>
<p>{$message}</p>
<p><a href="/login">back</a></p>
</body>
</html>
EOL;

}