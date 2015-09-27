<?php

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/bootstrap.php';

$fb = new Facebook\Facebook([
    'app_id' => $_ENV['FACEBOOK_APP_ID'],
    'app_secret' => $_ENV['FACEBOOK_APP_SECRET'],
    'default_graph_version' => $_ENV['FACEBOOK_GRAPH_VERSION'],
]);

$helper = $fb->getJavaScriptHelper();
try {
    $accessToken = $helper->getAccessToken();
    if(! isset($accessToken)) {
        // redirect to login page
        $scheme = Yutaf\Url::getScheme();
        header("Location: {$scheme}://".$_SERVER['HTTP_HOST'].'/login', true, 302);
        exit;
    }

    // Logged in
    $fb->setDefaultAccessToken($accessToken);
    // get user profile
    $response_user = $fb->get('/me?fields=id,email,locale,timezone');
    $response_user_decodedBody = $response_user->getDecodedBody();
    $facebook_user_id = $response_user_decodedBody['id'];

    // db
    $dbManager = new Yutaf\DbManager();
    $dbManager->connect($_ENV);

    // check if facebook_user_id is registered
    $conditions_facebooks = array(
        'wheres' => array(
            'facebook_user_id' => $facebook_user_id,
        ),
    );
    $facebooks_row = $dbManager->get('Facebooks')->fetchByConditions($conditions_facebooks);
    if($facebooks_row) {
        // authorize
        $user_id = $facebooks_row['user_id'];
        // redis
        $redis = new Redis();
        $redis->connect('redis');
        $authsecret = $redis->hGet("user:{$user_id}", 'auth');
        if(! $authsecret) {
            $authsecret = getrand();
            $ret = $redis->multi()
                ->hSet("user:{$user_id}", 'auth', $authsecret)
                ->hSet('auths', $authsecret, $user_id)
                ->exec();
        }
        setcookie("auth",$authsecret,time()+3600*24*365);

        // redirect to list page
        $scheme = Yutaf\Url::getScheme();
        header("Location: {$scheme}://".$_SERVER['HTTP_HOST'].'/list', true, 302);
        exit;
    }

    // transaction
    $dbManager->beginTransaction();

    $delivery_time_default = '08:00:00';
    $datetime_now = date('Y-m-d H:i:s');

    $values_users = array(
        'email' => $response_user_decodedBody['email'],
        'locale' => $response_user_decodedBody['locale'],
        'timezone' => $response_user_decodedBody['timezone'],
        'delivery_time' => $delivery_time_default,
        'is_active' => true,
        'created_at' => $datetime_now,
        'updated_at' => $datetime_now,
    );
    $dbManager->get('Users')->insert($values_users);
    $user_id = $dbManager->getLastInsertId();

    $values_facebooks = array(
        'user_id' => $user_id,
        'facebook_user_id' => $facebook_user_id,
        'created_at' => $datetime_now,
        'updated_at' => $datetime_now,
    );
    $dbManager->get('Facebooks')->insert($values_facebooks);

    // get music data
    $response_music = $fb->get('/me/music?limit=1000');
    $response_music_decodedBody = $response_music->getDecodedBody();
    $music_sets = $response_music_decodedBody['data'];
    if(! isset($music_sets) || ! is_array($music_sets) || count($music_sets) === 0) {
        // commit
        $dbManager->commit();

        // redirect to list page
        $scheme = Yutaf\Url::getScheme();
        header("Location: {$scheme}://".$_SERVER['HTTP_HOST'].'/list', true, 302);
        exit;
    }

    $artists_rows = $dbManager->get('Artists')->fetchAll();
    $patterns_removing = getRemovingPatterns();
    $values_artists_users_sets = array();
    $inserted_artist_names = array();

    foreach($music_sets as $k_music_sets => $music_set) {
        // remove unnecessary words, strings
        $artist_name = preg_replace($patterns_removing, '', $music_set['name']);

        // Insert into artists table if the value does not exist in the table
        // Do not insert SAME NAME but CASE DIFFERENT data into artists table. Avoid DUPLICATED data.
        foreach($artists_rows as $k_artists_rows => $artists_row) {
            $result_match = preg_match('{^'.$artists_row['name'].'$}i', $artist_name);
            if($result_match !== 1) {
                continue;
            }
            if(in_array(mb_strtolower($artists_row['name']), $inserted_artist_names)) {
                // go to next data
                continue 2;
            }
            // matched
            $values_artists_users_sets[] = array(
                'artist_id' => $artists_row['id'],
                'user_id' => $user_id,
            );
            // delete matched row
            unset($artists_rows[$k_artists_rows]);
            $inserted_artist_names[] = mb_strtolower($artists_row['name']);
            // go to next data
            continue 2;
        }

        // insert new artist
        if(in_array(mb_strtolower($artist_name), $inserted_artist_names)) {
            // go to next data
            continue;
        }

        $values_artists = array(
            'name' => $artist_name,
            'created_at' => $datetime_now,
            'updated_at' => $datetime_now,
        );
        $dbManager->get('Artists')->insert($values_artists);
        $inserted_artist_names[] = mb_strtolower($artist_name);

        $artist_id = $dbManager->getLastInsertId();
        $values_artists_users_sets[] = array(
            'artist_id' => $artist_id,
            'user_id' => $user_id,
        );
    }

    // insert into artists_users
    $dbManager->get('ArtistsUsers')->insertMultipleTimes($values_artists_users_sets);

    // commit
    $dbManager->commit();

    // authorize
    $authsecret = getrand();
    // redis
    $redis = new Redis();
    $redis->connect('redis');
    // transaction
    $ret = $redis->multi()
        ->hSet("user:{$user_id}", 'auth', $authsecret)
        ->hSet('auths', $authsecret, $user_id)
        ->exec();
//    $redis->hSet("user:{$user_id}", 'auth', $authsecret);
//    $redis->hSet('auths', $authsecret, $user_id);
    setcookie("auth",$authsecret,time()+3600*24*365);

    // redirect to list page
    $scheme = Yutaf\Url::getScheme();
    header("Location: {$scheme}://".$_SERVER['HTTP_HOST'].'/list', true, 302);
    exit;

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

function getRemovingPatterns()
{
    return array(
        '{ \([^\)]*\)$}',
        '{ official$}i',
    );
}

function getrand() {
    $fd = fopen("/dev/urandom","r");
    $data = fread($fd,16);
    fclose($fd);
    return md5($data);
}

function printErrorPage($message)
{
    echo <<<EOL
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Error</title>
</head>
<body>
<p>{$message}</p>
<p><a href="/login">back</a></p>
</body>
</html>
EOL;

}