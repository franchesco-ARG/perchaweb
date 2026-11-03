<?php
// recapturar.php - To be executed by a cronjob every week
require_once __DIR__ . '/config.php';
date_default_timezone_set('America/Montevideo');

$dbDir = __DIR__ . '/db';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}
$dbFile = $dbDir . '/perchero.db';
if (!file_exists($dbFile)) {
    die("Database not found.");
}
$db = new SQLite3($dbFile);

// Find users inactive for more than 2 weeks
$two_weeks_ago = date('Y-m-d H:i:s', strtotime('-14 days'));
$queryUsers = "SELECT id, name, email, last_login FROM users WHERE last_login <= '$two_weeks_ago' OR (last_login IS NULL AND id > 0)";
$resUsers = $db->query($queryUsers);

// Get the 4 most viewed overall
$queryTop4 = "SELECT id, title, category, brand, size, price, views FROM products WHERE status = 'publicado' ORDER BY views DESC LIMIT 4";
$resTop4 = $db->query($queryTop4);
$top4_html = "";
$top4_items = [];
while ($row = $resTop4->fetchArray(SQLITE3_ASSOC)) {
    $top4_items[] = $row;
    $top4_html .= "<li>{$row['title']} - {$row['price']} UYU</li>";
}
if ($top4_html !== "") {
    $top4_html = "<h3>Los 4 artículos más vistos del sitio:</h3><ul>$top4_html</ul>";
}

$users = [];
while ($user = $resUsers->fetchArray(SQLITE3_ASSOC)) {
    $users[] = $user;
}

$recCache = [];
$chunks = array_chunk($users, 500);

foreach ($chunks as $chunk) {
    $userIds = array_column($chunk, 'id');
    if (empty($userIds)) {
        continue;
    }
    $userIdsStr = implode(',', $userIds);

    // Find categories, brands, and sizes the user most viewed
    $queryViews = "
        WITH user_views AS (
            SELECT pv.user_id, p.category, p.brand, p.size, COUNT(pv.id) as view_count
            FROM product_views pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.user_id IN ($userIdsStr) AND p.status = 'publicado'
            GROUP BY pv.user_id, p.category, p.brand, p.size
        ),
        ranked_views AS (
            SELECT user_id, category, brand, size, view_count,
                   ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY view_count DESC) as rn
            FROM user_views
        )
        SELECT user_id, category, brand, size, view_count
        FROM ranked_views
        WHERE rn <= 3
    ";
    $resViews = $db->query($queryViews);
    $userViewsMap = [];
    while ($view = $resViews->fetchArray(SQLITE3_ASSOC)) {
        $userViewsMap[$view['user_id']][] = $view;
    }

    foreach ($chunk as $user) {
        $user_id = $user['id'];
        $recommendations_html = "";

        $viewsForUser = isset($userViewsMap[$user_id]) ? $userViewsMap[$user_id] : [];
        foreach ($viewsForUser as $view) {
        // Recommend items based on this preference
        $cat = $db->escapeString($view['category']);
        $brand = $db->escapeString($view['brand']);
        $size = $db->escapeString($view['size']);

        $cacheKey = "$cat|$brand|$size";
        if (!isset($recCache[$cacheKey])) {
            $recQuery = "SELECT id, title, price FROM products WHERE status = 'publicado' AND category = '$cat' AND (brand = '$brand' OR size = '$size') ORDER BY RANDOM() LIMIT 2";
            $recRes = $db->query($recQuery);
            $recCache[$cacheKey] = [];
            while ($rec = $recRes->fetchArray(SQLITE3_ASSOC)) {
                $recCache[$cacheKey][] = $rec;
            }
        }

        foreach ($recCache[$cacheKey] as $rec) {
            $recommendations_html .= "<li>{$rec['title']} - {$rec['price']} UYU</li>";
        }
    }

        if ($recommendations_html !== "") {
            $recommendations_html = "<h3>Recomendaciones basadas en tus búsquedas:</h3><ul>$recommendations_html</ul>";
        }

        // Send email
        if ($recommendations_html !== "" || $top4_html !== "") {
            $subject = "¡Te extrañamos en Perchero, " . $user['name'] . "!";
            $body = "<h2>¡Volvé a ver qué hay de nuevo!</h2>";
            $body .= $recommendations_html;
            $body .= $top4_html;
            $body .= "<br><p>Visitanos en <a href='http://" . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'perchero.store') . "'>Perchero</a></p>";

            send_email_smtp($user['email'], $subject, $body, "From: info@perchero.store\r\nContent-Type: text/html; charset=UTF-8\r\n");
        }
    }
}

echo "Cronjob recapturar executed successfully.\n";
?>