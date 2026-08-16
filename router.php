<?php
// router.php for PHP built-in web server
$rawUri = $_SERVER['REQUEST_URI'];
$uri = parse_url($rawUri, PHP_URL_PATH);
$uri = rawurldecode($uri);

// 1. Root redirect
if ($uri === '/' || $uri === '' || $uri === '/index.php') {
    header('Location: /frontend/index.php');
    exit;
}

// 2. Direct file in project root
$file = __DIR__ . $uri;
if (file_exists($file) && !is_dir($file)) {
    // If it's a php file, include it with proper SCRIPT_FILENAME / SCRIPT_NAME
    return false;
}

// 3. Check if mapped under frontend/
$frontendFile = __DIR__ . '/frontend' . $uri;
if (file_exists($frontendFile) && !is_dir($frontendFile)) {
    if (str_ends_with($frontendFile, '.php')) {
        $_SERVER['SCRIPT_NAME'] = '/frontend' . $uri;
        $_SERVER['PHP_SELF'] = '/frontend' . $uri;
        $_SERVER['SCRIPT_FILENAME'] = $frontendFile;
        chdir(dirname($frontendFile));
        require $frontendFile;
        return true;
    }
    
    // Serve static files
    $ext = pathinfo($frontendFile, PATHINFO_EXTENSION);
    $mimes = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'json' => 'application/json',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';
    header("Content-Type: $mime");
    header("Content-Length: " . filesize($frontendFile));
    readfile($frontendFile);
    return true;
}

// 4. Directory indices
if (is_dir($file) && file_exists($file . '/index.php')) {
    $_SERVER['SCRIPT_NAME'] = rtrim($uri, '/') . '/index.php';
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['SCRIPT_FILENAME'] = $file . '/index.php';
    chdir($file);
    require $file . '/index.php';
    return true;
}

if (is_dir($frontendFile) && file_exists($frontendFile . '/index.php')) {
    $_SERVER['SCRIPT_NAME'] = '/frontend' . rtrim($uri, '/') . '/index.php';
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['SCRIPT_FILENAME'] = $frontendFile . '/index.php';
    chdir($frontendFile);
    require $frontendFile . '/index.php';
    return true;
}

http_response_code(404);
echo "404 Not Found: " . htmlspecialchars($uri);
