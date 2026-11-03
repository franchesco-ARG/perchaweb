<?php

function runLogoutTest($cookieValue, $mockUserData) {
    $code = "<?php\n";

    // Suppress warnings to avoid output cluttering json response (if any)
    $code .= "error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);\n";

    // Mock $_COOKIE
    if ($cookieValue !== null) {
        $code .= "\$_COOKIE['perchero_session'] = " . var_export($cookieValue, true) . ";\n";
    }

    $code .= "\$_GET['action'] = 'logout';\n";

    $code .= "ob_start();\n";
    // We mock config variables that api.php might need
    $code .= "\$admin_emails = [];\n";
    $code .= "\$API_KEY_MERCADO_PAGO = '';\n";
    $code .= "ob_end_clean();\n";

    // Mock classes
    $code .= "
class MockResult {
    private \$data;
    public function __construct(\$data) { \$this->data = \$data; }
    public function fetchArray() { return \$this->data; }
}

class MockSQLite3 {
    private \$userData;
    public \$queries = [];
    public function __construct(\$userData) { \$this->userData = \$userData; }
    public function escapeString(\$str) { return \$str; }
    public function query(\$sql) { return new MockResult(\$this->userData); }
    public function exec(\$sql) { \$this->queries[] = \$sql; return true; }
}
";

    $code .= "\$db = new MockSQLite3(" . var_export($mockUserData, true) . ");\n";

    $code .= "ob_start();\n";
    // We include a modified version of api.php that doesn't instantiate its own DB but uses our mock
    $code .= "
    // Redefine header to not actually send headers in CLI
    if (!function_exists('header')) {
        function header(\$str) {}
    }
    if (!function_exists('setcookie')) {
        function setcookie() {}
    }
    ";

    // Instead of including api.php, we extract the logout logic since api.php executes top-level code that tries to create SQLite3
    // We will just test if our logic works in an isolated way or we can mock SQLite3 globally by renaming the class in api.php
    // It's easier to just read api.php, replace `new SQLite3` and require it

    $apiPath = __DIR__ . '/../api.php';
    $code .= "
    \$apiCode = file_get_contents('$apiPath');
    \$apiCode = str_replace('new SQLite3(\$dbFile)', '\$db', \$apiCode);
    \$apiCode = str_replace('require_once __DIR__ . \'/config.php\';', '', \$apiCode);
    eval('?>' . \$apiCode);
    ";

    $code .= "\$out = ob_get_clean();\n";
    $code .= "echo json_encode(['output' => \$out, 'queries' => \$db->queries]);\n";

    $tmp = tempnam(sys_get_temp_dir(), 'tst');
    file_put_contents($tmp, $code);

    $output = shell_exec("php " . escapeshellarg($tmp));
    unlink($tmp);

    return $output;
}

// Enable assert to throw exceptions
assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_WARNING, 0);
assert_options(ASSERT_BAIL, 0);

function custom_assert_handler($file, $line, $code, $desc = null) {
    echo "Assertion failed at $file:$line: $code";
    if ($desc) {
        echo ": $desc";
    }
    echo "\n";
    exit(1);
}
assert_options(ASSERT_CALLBACK, 'custom_assert_handler');

echo "Running tests for logout vulnerability fix...\n";

// 1. Logout without cookie
$out1 = runLogoutTest(null, null);
$res1 = json_decode($out1, true);
assert(empty($res1['queries']), "Test 1 Failed: Without cookie, no query should be executed.");
assert(strpos($res1['output'], '{"success":true}') !== false, "Test 1 Failed: Should output success.");

// 2. Logout with cookie but user not found
$out2 = runLogoutTest('dummy_token', null);
$res2 = json_decode($out2, true);
assert(empty($res2['queries']), "Test 2 Failed: If user is not found, no query should be executed.");

// 3. Logout with cookie and user found
$out3 = runLogoutTest('dummy_token', ['id' => 123, 'email' => 'test@test.com']);
$res3 = json_decode($out3, true);
assert(!empty($res3['queries']), "Test 3 Failed: If user is found, query should be executed.");
$expectedQuery = "UPDATE users SET token = NULL WHERE id = 123";
$found = false;
foreach ($res3['queries'] as $query) {
    if (strpos($query, $expectedQuery) !== false) {
        $found = true;
        break;
    }
}
assert($found, "Test 3 Failed: The exact UPDATE query to set token to NULL was not found.");

echo "All tests passed successfully.\n";
