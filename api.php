<?php
// api.php
header('Content-Type: application/json');
date_default_timezone_set('America/Montevideo'); // Para que la fecha sea local

$dbFile = __DIR__ . 'db/perchero.db';
$db = new SQLite3($dbFile);

// ============================================================================
// 1. CREACIÓN DE TABLAS EN SQLITE3
// ============================================================================
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    name TEXT, 
    email TEXT UNIQUE, 
    password TEXT, 
    token TEXT
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
    status TEXT DEFAULT 'disponible'
)");

$db->exec("CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    buyer_id INTEGER,
    total REAL,
    shipping_address TEXT,
    status TEXT,
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

    $db->exec("INSERT INTO users (name, email, password, token) VALUES ('$name', '$email', '$password', '$token')");
    $newId = $db->lastInsertRowID();
    
    setPersistentCookie($token);
    echo json_encode(['success' => true, 'user' => ['id' => $newId, 'name' => $name, 'email' => $email]]);
} 
elseif ($action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = $db->escapeString(trim($data['email']));
    $password = trim($data['password']);

    $res = $db->query("SELECT * FROM users WHERE email = '$email'");
    $user = $res->fetchArray(SQLITE3_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        // Renovamos el token por seguridad al entrar de nuevo
        $token = bin2hex(random_bytes(16));
        $db->exec("UPDATE users SET token = '$token' WHERE id = " . $user['id']);
        
        setPersistentCookie($token);
        echo json_encode(['success' => true, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']]]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Email o contraseña incorrectos.']);
    }
}
elseif ($action === 'me') {
    $user = getUserFromCookie($db);
    if ($user) {
        // Le mandamos al frontend los datos del usuario si la cookie es válida
        echo json_encode(['success' => true, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']]]);
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

    $query = "INSERT INTO products (title, category, brand, color, size, condition, price, description, seller_id, photos) 
              VALUES ('$title', '$category', '$brand', '$color', '$size', '$condition', $price, '$desc', $seller_id, '$photosStr')";
    
    if ($db->exec($query)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Hubo un error al guardar la prenda.']);
    }
} 
elseif ($action === 'get_products') {
    // Traemos los productos cruzando el seller_id con la tabla users para tener el nombre
    $query = "SELECT p.*, u.name as seller_name FROM products p LEFT JOIN users u ON p.seller_id = u.id WHERE p.status = 'disponible' ORDER BY p.id DESC";
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
?>
