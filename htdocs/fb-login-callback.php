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
        $scheme = Url::getScheme();
        header("Location: {$scheme}://".$_SERVER['HTTP_HOST'].'/list', true, 302);
        exit;
    }

    $artists_rows = $dbManager->get('Artists')->fetchAll();
    $patterns_removing = getRemovingPatterns();
    $values_artists_users_sets = array();
    $inserted_artist_names = array();

    foreach($music_sets as $k_music_sets => $music_set) {
        //TODO Insert into artists table if the value does not exist in the table
        //TODO use partial match & ignore letter case
        // facebook データの case 違いの同名に注意
        // Do not insert SAME NAME but CASE DIFFERENT data into artists table. Avoid DUPLICATED data.
        foreach($artists_rows as $k_artists_rows => $artists_row) {
            $result_match = preg_match('{^'.$artists_row['name'].'$}i', $music_set['name']);
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
        $artist_name = preg_replace($patterns_removing, '', $music_set['name']);
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

    // redirect to list page
    $scheme = Url::getScheme();
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