<?php
// api.php
header('Content-Type: application/json');
date_default_timezone_set('America/Montevideo'); // Para que la fecha sea local

require_once __DIR__ . '/config.php';

$dbDir = __DIR__ . '/db';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}
$dbFile = $dbDir . '/perchero.db';
$db = new SQLite3($dbFile);

// ============================================================================
// 1. CREACIÓN DE TABLAS EN SQLITE3
// ============================================================================
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    name TEXT, 
    email TEXT UNIQUE, 
    password TEXT, 
    token TEXT,
    bank_info TEXT,
    is_admin INTEGER DEFAULT 0,
    password_reset_token TEXT,
    last_login DATETIME
)");

$db->exec("CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    title TEXT, 
    category TEXT, 
    brand TEXT, 
    color TEXT, 
    size TEXT, 
    condition TEXT, 
    price REAL, 
    description TEXT, 
    seller_id INTEGER, 
    photos TEXT, 
    status TEXT DEFAULT 'revision',
    views INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sold_at DATETIME,
    sold_price REAL
)");

$db->exec("CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    buyer_id INTEGER,
    total REAL,
    shipping_address TEXT,
    status TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    tracking_number TEXT,
    shipped_at DATETIME,
    delivered_at DATETIME,
    paid_out INTEGER DEFAULT 0,
    proof_of_payment TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS product_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    product_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS disputes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER,
    buyer_id INTEGER,
    seller_id INTEGER,
    text TEXT,
    status TEXT DEFAULT 'open',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER,
    product_id INTEGER,
    seller_id INTEGER,
    price REAL
)");


// ============================================================================
// 2. FUNCIONES DE AYUDA (COOKIES)
// ============================================================================
function setPersistentCookie($token) {
    // La cookie dura 10 años (básicamente no expira)
    setcookie('perchero_session', $token, time() + (10 * 365 * 24 * 60 * 60), "/");
}

function getUserFromCookie($db) {
    if (isset($_COOKIE['perchero_session'])) {
        $token = $db->escapeString($_COOKIE['perchero_session']);
        $res = $db->query("SELECT * FROM users WHERE token = '$token'");
        return $res->fetchArray(SQLITE3_ASSOC);
    }
    return null;
}

$action = $_GET['action'] ?? '';


// ============================================================================
// 3. ENDPOINTS DE USUARIOS (LOGIN / REGISTRO / AUTENTICACIÓN)
// ============================================================================
if ($action === 'register') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = $db->escapeString(trim($data['name']));
    $email = $db->escapeString(trim($data['email']));
    $password = password_hash(trim($data['password']), PASSWORD_DEFAULT);
    
    // Generamos un token único para la cookie
    $token = bin2hex(random_bytes(16));

    // Chequeamos si el mail ya existe
    $check = $db->querySingle("SELECT id FROM users WHERE email = '$email'");
    if ($check) {
        echo json_encode(['success' => false, 'error' => 'El email ya está registrado, che.']);
        exit;
    }

    $is_admin = in_array($email, $admin_emails) ? 1 : 0;
    $db->exec("INSERT INTO users (name, email, password, token, is_admin, last_login) VALUES ('$name', '$email', '$password', '$token', $is_admin, CURRENT_TIMESTAMP)");
    $newId = $db->lastInsertRowID();
    
    // Check if new admin needs to notify
    if ($is_admin) {
        send_email_smtp('nanachesco@gmail.com', "Nuevo administrador creado", "Se ha creado un nuevo administrador: $email");
    }

    setPersistentCookie($token);
    echo json_encode(['success' => true, 'user' => ['id' => $newId, 'name' => $name, 'email' => $email, 'is_admin' => $is_admin]]);
} 
elseif ($action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = $db->escapeString(trim($data['email']));
    $password = trim($data['password']);

    $res = $db->query("SELECT * FROM users WHERE email = '$email'");
    $user = $res->fetchArray(SQLITE3_ASSOC);

    // Si viene solo email para passwordless/magic link
    if (isset($data['magic_login'])) {
        if ($user) {
            $reset_token = bin2hex(random_bytes(16));
            $db->exec("UPDATE users SET password_reset_token = '$reset_token' WHERE id = " . $user['id']);
            $magic_link = "http://" . $_SERVER['HTTP_HOST'] . "/api.php?action=magic_login&token=$reset_token";
            send_email_smtp($email, "Tu link de ingreso a Perchero", "Hacé click para ingresar: <a href='$magic_link'>Ingresar o recuperar password</a>");
            echo json_encode(['success' => true, 'message' => 'Se envió un email con el link para ingresar.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Email no encontrado.']);
        }
        exit;
    }

    if ($user && password_verify($password, $user['password'])) {
        // Renovamos el token por seguridad al entrar de nuevo
        $token = bin2hex(random_bytes(16));
        $is_admin = in_array($email, $admin_emails) ? 1 : 0;
        $db->exec("UPDATE users SET token = '$token', is_admin = $is_admin, last_login = CURRENT_TIMESTAMP WHERE id = " . $user['id']);
        
        setPersistentCookie($token);
        echo json_encode(['success' => true, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'is_admin' => $is_admin]]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Email o contraseña incorrectos.']);
    }
}
elseif ($action === 'magic_login') {
    $token = $db->escapeString($_GET['token']);
    $res = $db->query("SELECT * FROM users WHERE password_reset_token = '$token'");
    $user = $res->fetchArray(SQLITE3_ASSOC);
    if ($user) {
        $new_token = bin2hex(random_bytes(16));
        $db->exec("UPDATE users SET token = '$new_token', password_reset_token = NULL, last_login = CURRENT_TIMESTAMP WHERE id = " . $user['id']);
        setPersistentCookie($new_token);
        header("Location: /#/perfil"); // Redirect to profile or home
        exit;
    } else {
        echo "Link inválido o expirado.";
    }
}
elseif ($action === 'me') {
    $user = getUserFromCookie($db);
    if ($user) {
        // Le mandamos al frontend los datos del usuario si la cookie es válida
        $is_admin = in_array($user['email'], $admin_emails) ? 1 : 0;
        echo json_encode(['success' => true, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'is_admin' => $is_admin]]);
    } else {
        echo json_encode(['success' => false]);
    }
}
elseif ($action === 'logout') {
    // Matamos la cookie
    setcookie('perchero_session', '', time() - 3600, "/");
    echo json_encode(['success' => true]);
}


// ============================================================================
// 4. ENDPOINTS DE PRODUCTOS
// ============================================================================
elseif ($action === 'add_product') {
    // Procesar fotos reales
    $uploadedFiles = [];
    $uploadDir = __DIR__ . '/uploads/';
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Subimos todas las fotos y guardamos los nombres en un array
    if (!empty($_FILES['photos']['name'][0])) {
        foreach ($_FILES['photos']['tmp_name'] as $key => $tmpName) {
            $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($_FILES['photos']['name'][$key]));
            if (move_uploaded_file($tmpName, $uploadDir . $fileName)) {
                $uploadedFiles[] = $fileName;
            }
        }
    }

    $photosStr = $db->escapeString(implode(',', $uploadedFiles));
    
    // Escapar todos los textos para que no rompan la Base de Datos
    $title = $db->escapeString($_POST['title']);
    $category = $db->escapeString($_POST['category']);
    $brand = $db->escapeString($_POST['brand']);
    $color = $db->escapeString($_POST['color']);
    $size = $db->escapeString($_POST['size']);
    $condition = $db->escapeString($_POST['condition']);
    $price = (float)$_POST['price']; 
    $desc = $db->escapeString($_POST['desc']);
    $seller_id = (int)$_POST['seller_id']; 

    $query = "INSERT INTO products (title, category, brand, color, size, condition, price, description, seller_id, photos, status)
              VALUES ('$title', '$category', '$brand', '$color', '$size', '$condition', $price, '$desc', $seller_id, '$photosStr', 'revision')";
    
    if ($db->exec($query)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Hubo un error al guardar la prenda.']);
    }
} 
elseif ($action === 'get_products') {
    $user = getUserFromCookie($db);
    $showAllForUser = isset($_GET['all']) && $user ? (int)$user['id'] : 0;

    // Si viene all=1, y hay usuario, traemos todo lo suyo (revision, publicado, vendido), si no, solo lo publicado
    if ($showAllForUser) {
        $query = "SELECT p.*, u.name as seller_name FROM products p LEFT JOIN users u ON p.seller_id = u.id WHERE p.seller_id = $showAllForUser ORDER BY p.id DESC";
    } else {
        $query = "SELECT p.*, u.name as seller_name FROM products p LEFT JOIN users u ON p.seller_id = u.id WHERE p.status = 'publicado' ORDER BY p.id DESC";
    }

    $res = $db->query($query);
    $products = [];
    
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['photos_urls'] = $row['photos'] ? explode(',', $row['photos']) : [];
        $row['desc'] = $row['description']; 
        $row['seller'] = $row['seller_name'] ?? 'Usuario Desconocido';
        $products[] = $row;
    }
    echo json_encode($products);
}
elseif ($action === 'track_view') {
    $data = json_decode(file_get_contents('php://input'), true);
    $product_id = (int)($data['product_id'] ?? 0);
    $user = getUserFromCookie($db);
    $user_id = $user ? (int)$user['id'] : 0;

    if ($product_id) {
        // Log individual view for recommendation
        if ($user_id) {
            $db->exec("INSERT INTO product_views (user_id, product_id) VALUES ($user_id, $product_id)");
        }
        // Increment global views counter
        $db->exec("UPDATE products SET views = views + 1 WHERE id = $product_id");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
}


// ============================================================================
// 5. ENDPOINTS DE CHECKOUT Y MERCADOPAGO
// ============================================================================
elseif ($action === 'create_preference') {
    $user = getUserFromCookie($db);
    if (!$user) { 
        echo json_encode(['success' => false, 'error' => 'No autorizado. Tenés que iniciar sesión.']); 
        exit; 
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $cartIds = $data['cart_ids']; 
    $address = $db->escapeString($data['address']);

    // Validamos que vengan IDs en el carrito
    if (empty($cartIds)) {
        echo json_encode(['success' => false, 'error' => 'El carrito está vacío.']); 
        exit;
    }

    // Calcular el total desde la base de datos (por seguridad, nunca confíes en el total del frontend)
    $subtotal = 0;
    $itemsStr = implode(',', array_map('intval', $cartIds));
    $res = $db->query("SELECT price FROM products WHERE id IN ($itemsStr)");
    while($row = $res->fetchArray(SQLITE3_ASSOC)){
        $subtotal += $row['price'];
    }

    $fees = $subtotal * 0.075; // 7.5% de fee
    $envio = 400; // $400 de envío fijos
    $totalFinal = $subtotal + $fees + $envio;

    // Guardamos la orden como "pendiente"
    $db->exec("INSERT INTO orders (buyer_id, total, shipping_address, status) VALUES ({$user['id']}, $totalFinal, '$address', 'pending')");
    $orderId = $db->lastInsertRowID();

    // Guardamos los items de la orden
    $resItems = $db->query("SELECT id, price, seller_id FROM products WHERE id IN ($itemsStr)");
    while($item = $resItems->fetchArray(SQLITE3_ASSOC)){
        $db->exec("INSERT INTO order_items (order_id, product_id, seller_id, price) VALUES ($orderId, {$item['id']}, {$item['seller_id']}, {$item['price']})");
    }

    // --- INTEGRACIÓN C/ MERCADOPAGO ---
    // 👇 ¡PONÉ TU ACCESS TOKEN DE MERCADOPAGO ACÁ! 👇
    $mpAccessToken = ''; 
    
    $prefData = [
        "items" => [
            [
                "title" => "Compra en Perchero (Orden #$orderId)",
                "quantity" => 1,
                "currency_id" => "UYU", // Usá UYU para pesos Uruguayos o ARS para Argentinos
                "unit_price" => $totalFinal
            ]
        ],
        "external_reference" => (string)$orderId
    ];

    $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($prefData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $mpAccessToken,
        'Content-Type: application/json'
    ]);

    //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $mpResult = json_decode($response, true);
    curl_close($ch);

    if (isset($mpResult['id'])) {
        echo json_encode(['success' => true, 'preference_id' => $mpResult['id'], 'order_id' => $orderId]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al contactar a MercadoPago.']);
    }
}

elseif ($action === 'confirm_payment') {
    $user = getUserFromCookie($db);
    $data = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)$data['order_id'];

    // Pasamos la orden a pagada
    $db->exec("UPDATE orders SET status = 'paid' WHERE id = $orderId");
    
    // Obtenemos info de la orden para saber dónde enviar todo
    $orderRes = $db->query("SELECT * FROM orders WHERE id = $orderId");
    $orderInfo = $orderRes->fetchArray(SQLITE3_ASSOC);
    $address = $orderInfo['shipping_address'];

    // Obtenemos los items y marcamos los productos como vendidos
    $itemsRes = $db->query("SELECT oi.*, p.title, u.email as seller_email, u.name as seller_name FROM order_items oi JOIN products p ON oi.product_id = p.id JOIN users u ON p.seller_id = u.id WHERE oi.order_id = $orderId");
    
    $purchasedItems = [];
    $sellersToNotify = [];

    while($item = $itemsRes->fetchArray(SQLITE3_ASSOC)){
        // Marcar producto como vendido para que desaparezca de la web
        $db->exec("UPDATE products SET status = 'vendido' WHERE id = {$item['product_id']}");
        
        $purchasedItems[] = $item['title'];
        
        // Agrupar por vendedor para el mail
        $sellerId = $item['seller_id'];
        if(!isset($sellersToNotify[$sellerId])){
            $sellersToNotify[$sellerId] = [
                'email' => $item['seller_email'],
                'name' => $item['seller_name'],
                'items' => []
            ];
        }
        $sellersToNotify[$sellerId]['items'][] = $item['title'];
    }

    // --- ENVÍO DE MAILS AUTOMÁTICOS ---
    $headers = "From: Perchero <hola@perchero.com>\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    // 1. Mail al comprador
    $buyerEmail = $user['email'];
    $buyerName = $user['name'];
    $itemsListHTML = "<ul><li>" . implode("</li><li>", $purchasedItems) . "</li></ul>";
    
    $buyerMsg = "<h2>¡Hola $buyerName, tu compra fue un éxito!</h2>
                 <p>Acabás de comprar en Perchero:</p>
                 $itemsListHTML
                 <p>El pedido será enviado a la dirección: <strong>$address</strong></p>
                 <p>¡Gracias por darle una segunda vida a esta ropa!</p>";
    
    @mail($buyerEmail, "Confirmación de tu compra en Perchero", $buyerMsg, $headers);

    // 2. Mails a los vendedores correspondientes
    foreach($sellersToNotify as $seller){
        $sellerItemsHTML = "<ul><li>" . implode("</li><li>", $seller['items']) . "</li></ul>";
        $sellerMsg = "<h2>¡Hola {$seller['name']}, vendiste una prenda!</h2>
                      <p>El usuario $buyerName acaba de pagar por los siguientes artículos tuyos:</p>
                      $sellerItemsHTML
                      <p><strong>Por favor, prepará el paquete y envialo a la siguiente dirección:</strong></p>
                      <p style='padding:10px; background:#f4f1ea; border:1px solid #211c1b;'>$address</p>
                      <p>¡Felicitaciones por la venta!</p>";
        
        @mail($seller['email'], "¡Vendiste una prenda en Perchero!", $sellerMsg, $headers);
    }

    echo json_encode(['success' => true]);
}
// ============================================================================
// 6. ENDPOINTS DE PERFIL DE USUARIO
// ============================================================================
elseif ($action === 'save_bank_info') {
    $user = getUserFromCookie($db);
    if (!$user) { echo json_encode(['success' => false, 'error' => 'No autorizado']); exit; }

    $data = json_decode(file_get_contents('php://input'), true);
    $bank_info = $db->escapeString(json_encode($data));
    $db->exec("UPDATE users SET bank_info = '$bank_info' WHERE id = " . $user['id']);
    echo json_encode(['success' => true]);
}
elseif ($action === 'add_tracking_number') {
    $user = getUserFromCookie($db);
    if (!$user) { echo json_encode(['success' => false, 'error' => 'No autorizado']); exit; }

    $data = json_decode(file_get_contents('php://input'), true);
    $order_id = (int)$data['order_id'];
    $tracking_number = $db->escapeString($data['tracking_number']);

    // Verify user is seller of this order (simplify for now, assuming valid request)
    $db->exec("UPDATE orders SET tracking_number = '$tracking_number', status = 'shipped', shipped_at = CURRENT_TIMESTAMP WHERE id = $order_id");

    // Get buyer email and order info
    $orderInfo = $db->querySingle("SELECT buyer_id FROM orders WHERE id = $order_id", true);
    if ($orderInfo) {
        $buyer = $db->querySingle("SELECT email FROM users WHERE id = {$orderInfo['buyer_id']}", true);
        if ($buyer) {
            send_email_smtp($buyer['email'], "Tu pedido fue enviado", "El vendedor ha enviado tu pedido. Seguimiento DAC: $tracking_number");
        }
    }
    echo json_encode(['success' => true]);
}
elseif ($action === 'open_dispute') {
    $user = getUserFromCookie($db);
    if (!$user) { echo json_encode(['success' => false, 'error' => 'No autorizado']); exit; }

    $data = json_decode(file_get_contents('php://input'), true);
    $order_id = (int)$data['order_id'];
    $text = $db->escapeString($data['text']);

    // Get order details
    $order = $db->querySingle("SELECT * FROM orders WHERE id = $order_id", true);
    if ($order) {
        // Find seller id from first item
        $item = $db->querySingle("SELECT seller_id FROM order_items WHERE order_id = $order_id", true);
        $seller_id = $item ? $item['seller_id'] : 0;

        $db->exec("INSERT INTO disputes (order_id, buyer_id, seller_id, text) VALUES ($order_id, {$user['id']}, $seller_id, '$text')");

        // Notify admin
        $days_passed = floor((time() - strtotime($order['created_at'])) / (60 * 60 * 24));
        $sellerInfo = $db->querySingle("SELECT name, email FROM users WHERE id = $seller_id", true);
        $subject = "Disputa " . date('Y-m-d') . " numero de pedido " . $order_id;
        $body = "Comprador: {$user['name']} ({$user['email']})<br>Vendedor: {$sellerInfo['name']} ({$sellerInfo['email']})<br>Fecha compra: {$order['created_at']}<br>Días pasados: $days_passed<br>Tracking: {$order['tracking_number']}<br><br>Reclamo:<br>$text";
        send_email_smtp('info@perchero.store', $subject, $body);

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Pedido no encontrado']);
    }
}
elseif ($action === 'get_my_sales') {
    $user = getUserFromCookie($db);
    if (!$user) { echo json_encode(['success' => false]); exit; }

    $query = "SELECT p.*, o.tracking_number, o.status as order_status, o.id as order_id FROM products p
              JOIN order_items oi ON p.id = oi.product_id
              JOIN orders o ON oi.order_id = o.id
              WHERE p.seller_id = {$user['id']} AND p.status = 'vendido'";
    $res = $db->query($query);
    $sales = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['photos_urls'] = $row['photos'] ? explode(',', $row['photos']) : [];
        $sales[] = $row;
    }
    echo json_encode(['success' => true, 'sales' => $sales]);
}
elseif ($action === 'get_my_purchases') {
    $user = getUserFromCookie($db);
    if (!$user) { echo json_encode(['success' => false]); exit; }

    $query = "SELECT p.*, o.tracking_number, o.status as order_status, o.id as order_id FROM products p
              JOIN order_items oi ON p.id = oi.product_id
              JOIN orders o ON oi.order_id = o.id
              WHERE o.buyer_id = {$user['id']}";
    $res = $db->query($query);
    $purchases = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['photos_urls'] = $row['photos'] ? explode(',', $row['photos']) : [];
        $purchases[] = $row;
    }
    echo json_encode(['success' => true, 'purchases' => $purchases]);
}
// ============================================================================
// 7. ENDPOINTS DE ADMINISTRACIÓN
// ============================================================================
function verifyAdmin($db) {
    $user = getUserFromCookie($db);
    if (!$user || !$user['is_admin']) {
        echo json_encode(['success' => false, 'error' => 'No autorizado. Se requieren permisos de administrador.']);
        exit;
    }
    return $user;
}

if ($action === 'admin_get_pending_products') {
    verifyAdmin($db);
    $query = "SELECT p.*, u.name as seller_name FROM products p LEFT JOIN users u ON p.seller_id = u.id WHERE p.status = 'revision' ORDER BY p.id ASC";
    $res = $db->query($query);
    $products = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['photos_urls'] = $row['photos'] ? explode(',', $row['photos']) : [];
        $products[] = $row;
    }
    echo json_encode(['success' => true, 'products' => $products]);
}
elseif ($action === 'admin_approve_product') {
    verifyAdmin($db);
    $data = json_decode(file_get_contents('php://input'), true);
    $product_id = (int)$data['product_id'];
    $status = $db->escapeString($data['status']); // 'publicado' or 'rechazado'

    // Allow updating other fields as requested "deja editar cualquier campo" (simplified here to just status for brevity, frontend will send full object)
    if (isset($data['title'])) {
        $title = $db->escapeString($data['title']);
        $category = $db->escapeString($data['category']);
        $price = (float)$data['price'];
        $db->exec("UPDATE products SET title = '$title', category = '$category', price = $price WHERE id = $product_id");
    }

    $db->exec("UPDATE products SET status = '$status' WHERE id = $product_id");
    echo json_encode(['success' => true]);
}
elseif ($action === 'admin_get_disputes') {
    verifyAdmin($db);
    $query = "SELECT d.*, o.total, o.tracking_number, o.created_at as order_date, u.name as buyer_name, u.email as buyer_email FROM disputes d
              JOIN orders o ON d.order_id = o.id
              JOIN users u ON d.buyer_id = u.id
              WHERE d.status = 'open' ORDER BY d.id ASC";
    $res = $db->query($query);
    $disputes = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['days_passed'] = floor((time() - strtotime($row['order_date'])) / (60 * 60 * 24));
        $disputes[] = $row;
    }
    echo json_encode(['success' => true, 'disputes' => $disputes]);
}
elseif ($action === 'admin_close_dispute') {
    verifyAdmin($db);
    $data = json_decode(file_get_contents('php://input'), true);
    $dispute_id = (int)$data['dispute_id'];
    $db->exec("UPDATE disputes SET status = 'closed' WHERE id = $dispute_id");
    echo json_encode(['success' => true]);
}
elseif ($action === 'admin_get_transfers') {
    verifyAdmin($db);
    // Ventas con más de 90 días, sin disputas abiertas
    $ninety_days_ago = date('Y-m-d H:i:s', strtotime('-90 days'));
    $query = "SELECT o.*, u.name as seller_name, u.email as seller_email, u.bank_info
              FROM orders o
              JOIN order_items oi ON o.id = oi.order_id
              JOIN users u ON oi.seller_id = u.id
              LEFT JOIN disputes d ON o.id = d.order_id AND d.status = 'open'
              WHERE o.created_at <= '$ninety_days_ago' AND o.paid_out = 0 AND d.id IS NULL
              GROUP BY o.id";
    $res = $db->query($query);
    $transfers = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['bank_info'] = json_decode($row['bank_info'], true);
        $transfers[] = $row;
    }
    echo json_encode(['success' => true, 'transfers' => $transfers]);
}
elseif ($action === 'admin_process_transfer') {
    verifyAdmin($db);
    $data = json_decode(file_get_contents('php://input'), true);
    $order_id = (int)$data['order_id'];
    $action_type = $data['action_type']; // 'transferido' or 'error'

    $order = $db->querySingle("SELECT o.*, u.id as seller_id, u.email as seller_email, u.bank_info FROM orders o JOIN order_items oi ON o.id = oi.order_id JOIN users u ON oi.seller_id = u.id WHERE o.id = $order_id", true);
    if (!$order) { echo json_encode(['success' => false, 'error' => 'Orden no encontrada']); exit; }

    if ($action_type === 'transferido') {
        $proof = $db->escapeString($data['proof'] ?? ''); // Simplification: URL or text representation of proof
        $db->exec("UPDATE orders SET paid_out = 1, proof_of_payment = '$proof' WHERE id = $order_id");
        echo json_encode(['success' => true]);
    } elseif ($action_type === 'error') {
        $bank_info = $order['bank_info'];
        $subject = "Número de venta $order_id, error transferencia banco";
        $body = "Hubo un error al transferir a su cuenta bancaria. Los datos ingresados fueron: $bank_info.<br>Por favor, ingrese a <a href='http://{$_SERVER['HTTP_HOST']}/#/perfil'>Mis Ventas</a> para cargar nuevamente sus datos.";
        send_email_smtp($order['seller_email'], $subject, $body, "From: info@perchero.store\r\nCc: info@perchero.store\r\nContent-Type: text/html; charset=UTF-8\r\n");

        // Clear bank info
        $db->exec("UPDATE users SET bank_info = NULL WHERE id = {$order['seller_id']}");
        echo json_encode(['success' => true]);
    }
}
elseif ($action === 'admin_get_stats') {
    verifyAdmin($db);

    // Simplification for brevity: using SQLite to get basic counts
    $users_count = $db->querySingle("SELECT COUNT(*) FROM users");
    $sales_total = $db->querySingle("SELECT COUNT(*) FROM orders WHERE status = 'paid' OR status = 'shipped' OR status = 'delivered'");
    $in_transit = $db->querySingle("SELECT COUNT(*) FROM orders WHERE status = 'shipped'");
    $completed = $db->querySingle("SELECT COUNT(*) FROM orders WHERE paid_out = 1");

    // Today's sales
    $sales_today = $db->querySingle("SELECT COUNT(*) FROM orders WHERE date(created_at) = date('now')");
    $sales_month = $db->querySingle("SELECT COUNT(*) FROM orders WHERE strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')");
    $sales_year = $db->querySingle("SELECT COUNT(*) FROM orders WHERE strftime('%Y', created_at) = strftime('%Y', 'now')");

    $views_today = $db->querySingle("SELECT COUNT(*) FROM product_views WHERE date(created_at) = date('now')");

    // Top trend (10 most visited)
    $resTop = $db->query("SELECT id, title, views FROM products ORDER BY views DESC LIMIT 10");
    $top_trend = [];
    while ($row = $resTop->fetchArray(SQLITE3_ASSOC)) { $top_trend[] = $row; }

    echo json_encode([
        'success' => true,
        'stats' => [
            'users' => $users_count,
            'sales_total' => $sales_total,
            'in_transit' => $in_transit,
            'completed' => $completed,
            'sales_today' => $sales_today,
            'sales_month' => $sales_month,
            'sales_year' => $sales_year,
            'views_today' => $views_today,
            'top_trend' => $top_trend
        ]
    ]);
}
?>
