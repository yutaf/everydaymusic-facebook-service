<?php

// Error setting
ini_set('display_errors',           getenv('VALUE_DISPLAY_ERRORS'));
ini_set('display_startup_errors',   getenv('VALUE_DISPLAY_STARTUP_ERRORS'));

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/bootstrap.php';

// facebook
$fb = new Facebook\Facebook([
    'app_id' => $_ENV['FACEBOOK_APP_ID'],
    'app_secret' => $_ENV['FACEBOOK_APP_SECRET'],
    'default_graph_version' => $_ENV['FACEBOOK_GRAPH_VERSION'],
]);
$helper = $fb->getJavaScriptHelper();

// monolog
$log = new Monolog\Logger('fb-login');
$file = dirname(__DIR__).'/logs/app/app.log';
$maxFiles = 45;
$handler = new Monolog\Handler\RotatingFileHandler($file, $maxFiles, Monolog\Logger::WARNING);
//$rotating_file_handler = new Monolog\Handler\RotatingFileHandler($file, $maxFiles, Monolog\Logger::INFO);
//$handler = new Monolog\Handler\FingersCrossedHandler($rotating_file_handler, new Monolog\Handler\FingersCrossed\ErrorLevelActivationStrategy(Monolog\Logger::WARNING));

$log->pushHandler($handler);

try {
    $accessToken = $helper->getAccessToken();
    if(! isset($accessToken)) {
        redirectTo('/account');
        exit;
    }

    // Logged in
    $fb->setDefaultAccessToken($accessToken);

    $authcookie = $_COOKIE['auth'];
    if(empty($authcookie)) {
        redirectTo('/');
        exit;
    }

    // redis
    $redis = new Redis();
    $redis->connect('redis');

    $user_id = $redis->hGet('auths', $authcookie);
    if(empty($user_id)) {
        redirectTo('/');
        exit;
    }
    $authsecret = $redis->hGet("user:{$user_id}", 'auth');
    if($authcookie !== $authsecret) {
        redirectTo('/');
        exit;
    }

    // db
    $dbManager = new Yutaf\DbManager();
    $dbManager->connect($_ENV);

    $datetime_now = date('Y-m-d H:i:s');

    // get music data
    $response_music = $fb->get('/me/music?limit=1000');
    $response_music_decodedBody = $response_music->getDecodedBody();
    $music_sets = $response_music_decodedBody['data'];
    if(! isset($music_sets) || ! is_array($music_sets) || count($music_sets) === 0) {
        redirectTo('/account');
        exit;
    }

    $artists_rows = $dbManager->get('Artists')->fetchAll();
    $patterns_removing = getRemovingPatterns();
    $values_artists_users_sets = array();
    $inserted_artist_names = array();

    $conditions_artists_users = [
        'columns' => ['artist_id'],
        'wheres' => ['user_id' => $user_id],
        'fetch_style' => PDO::FETCH_COLUMN,
    ];
    $artist_ids_by_user = $dbManager->get('ArtistsUsers')->fetchAllByConditions($conditions_artists_users);

    // transaction
    $dbManager->beginTransaction();

    foreach($music_sets as $k_music_sets => $music_set) {
        // remove unnecessary words, strings
        $artist_name = preg_replace($patterns_removing, '', $music_set['name']);
        $artist_name_for_match = preg_replace('/^the /i', '', $artist_name);

        // Insert into artists table if the value does not exist in the table
        // Do not insert SAME NAME, CASE DIFFERENT data.
        foreach($artists_rows as $k_artists_rows => $artists_row) {
            $artist_row_name_for_match = preg_replace('/^the /i', '', $artists_row['name']);
            if(stripos($artist_row_name_for_match, $artist_name_for_match) !== 0) {
                continue;
            }
            if(in_array(mb_strtolower($artists_row['name']), $inserted_artist_names)) {
                // go to next data
                continue 2;
            }
            // matched
            if(! in_array($artists_row['id'], $artist_ids_by_user)) {
                $values_artists_users_sets[] = array(
                    'artist_id' => $artists_row['id'],
                    'user_id' => $user_id,
                );
            }

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
    $dbManager->get('ArtistsUsers')->insertMultipleRows($values_artists_users_sets);

    // commit
    $dbManager->commit();
    // redirect to list page
    redirectTo('/account');
    exit;

} catch(Facebook\Exceptions\FacebookResponseException $e) {
    // When Graph returns an error
    $message = 'Graph returned an error: ' . $e->getMessage();
    $trace = end($e->getTrace());
    $log->addWarning($message, $trace);
    renderErrorPage($message);
    exit;
} catch(Facebook\Exceptions\FacebookSDKException $e) {
    // When validation fails or other local issues
    $message = 'Facebook SDK returned an error: ' . $e->getMessage();
    $trace = end($e->getTrace());
    $log->addWarning($message, $trace);
    renderErrorPage($message);
    exit;
} catch(PDOException $e) {
    $message = 'Database error: ' . $e->getMessage();
    $trace = end($e->getTrace());
    $log->addWarning($message, $trace);
    renderErrorPage($message);
    exit;
} catch(Exception $e) {
    $message = 'Error: ' . $e->getMessage();
    $trace = end($e->getTrace());
    $log->addWarning($message, $trace);
    renderErrorPage($message);
    exit;
}

function redirectTo($path)
{
    $scheme = Yutaf\Url::getScheme();
    header("Location: {$scheme}://{$_SERVER['HTTP_HOST']}{$path}", true, 302);
}

function getRemovingPatterns()
{
    return array(
        '{ \([^\)]*\)$}',
        '{ official$}i',
    );
}

function renderErrorPage($message)
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
<p><a href="/account">back</a></p>
</body>
</html>
EOL;

}