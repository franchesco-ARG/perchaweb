<?php

function runVerifyAdminTest($cookieValue, $mockUserData) {
    $code = "<?php\n";

    // Suppress warnings to avoid output cluttering json response (if any)
    $code .= "error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);\n";

    // Mock $_COOKIE
    if ($cookieValue !== null) {
        $code .= "\$_COOKIE['perchero_session'] = " . var_export($cookieValue, true) . ";\n";
    }

    $code .= "ob_start();\n";
    $code .= "require_once '" . __DIR__ . "/../api.php';\n";
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
    public function __construct(\$userData) { \$this->userData = \$userData; }
    public function escapeString(\$str) { return \$str; }
    public function query(\$sql) { return new MockResult(\$this->userData); }
}
";

    $code .= "\$db = new MockSQLite3(" . var_export($mockUserData, true) . ");\n";

    $code .= "ob_start();\n";
    $code .= "
    \$res = verifyAdmin(\$db);
    ";
    $code .= "\$out = ob_get_clean();\n";
    $code .= "echo json_encode(['return' => \$res, 'output' => \$out]);\n";

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

echo "Running tests for verifyAdmin...\n";

// 1. No cookie
$out1 = runVerifyAdminTest(null, null);
assert($out1 === '{"success":false,"error":"No autorizado. Se requieren permisos de administrador."}', "Test 1 Failed: No cookie should return error.");

// 2. Cookie exists, but user not found
$out2 = runVerifyAdminTest('dummy_token', null);
assert($out2 === '{"success":false,"error":"No autorizado. Se requieren permisos de administrador."}', "Test 2 Failed: User not found should return error.");

// 3. Cookie exists, user found, but not admin
$out3 = runVerifyAdminTest('dummy_token', ['id' => 1, 'is_admin' => 0]);
assert($out3 === '{"success":false,"error":"No autorizado. Se requieren permisos de administrador."}', "Test 3 Failed: User found but not admin should return error.");

// 4. Cookie exists, user found, IS admin
$out4 = runVerifyAdminTest('dummy_token', ['id' => 1, 'is_admin' => 1]);
$res4 = json_decode($out4, true);
assert(isset($res4['return']) && $res4['return']['id'] === 1 && $res4['return']['is_admin'] === 1, "Test 4 Failed: Admin user should return the user data.");
assert($res4['output'] === '', "Test 4 Failed: Admin user shouldn't output any error JSON.");

echo "All tests passed successfully.\n";
