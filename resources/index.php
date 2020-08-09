<?php

/**
 * This files goes into the phar build
 */

$showError = function (string $text) {
    header('Status: 503 Service Temporarily Unavailable');
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
};

$getConfig = function () {
    $config = require __DIR__ . '/../config.php';
    if (is_array($config)) {
        return $config;
    }
    $showError('The config file is not valid!');
};

// Copied to dotsmesh-installer.php
$makeRequest = function (string $method, string $url, array $data = [], int $timeout = 30): string {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . ($method === 'GET' && !empty($data) ? '?' . http_build_query($data) : ''));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $response = curl_exec($ch);
    $error = curl_error($ch);
    if (isset($error[0])) {
        throw new \Exception('Request curl error: ' . $error . ' (1027)');
    }
    return $response;
};
$update = function (string $dir) use ($makeRequest) {
    $makeDir = function (string $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new \Exception('Cannot create dir (' . $dir . ')!');
            }
        }
    };
    $latestVersionData = $makeRequest('GET', 'https://downloads.dotsmesh.com/stable-php.json', [], 240);
    $latestVersionData = json_decode($latestVersionData, true);
    if (isset($latestVersionData['version'], $latestVersionData['checksums'], $latestVersionData['urls'])) {
        $version = $latestVersionData['version'];
        //$checksums = $latestVersionData['checksums'];
        $urls = $latestVersionData['urls'];
        $targetDir = $dir . '/' . $version;
        $indexFilename = $dir . '/index.php';
        $indexContent = '<?php' . "\n\n" .  'require __DIR__ . \'/' . $version . '/dotsmesh.phar\';';
        if (!is_file($indexFilename) || file_get_contents($indexFilename) !== $indexContent) {
            $makeDir($targetDir);
            foreach ($urls as $url) {
                $content = $makeRequest('GET', $url, [], 240);
                if (strlen($content) > 0) {
                    // todo check checksums
                    file_put_contents($targetDir . '/dotsmesh.phar', $content);
                    // todo check checksums
                    file_put_contents($indexFilename, $indexContent);
                    return true;
                }
            }
        }
    }
    return false;
};

if (isset($_GET['update'])) {
    $config = $getConfig();
    header('Content-Type: text/plain; charset=utf-8');
    $update = false;
    if (isset($config['autoUpdate']) && $config['autoUpdate']) {
        $update = true;
    } else if (isset($config['updateSecret'], $_GET['secret']) && (string) $config['updateSecret'] === (string) $_GET['secret']) {
        $update = true;
    } else {
        echo 'Auto-update is disabled!';
    }
    if ($update) {
        $lastUpdateTimeFilename = sys_get_temp_dir() . '/dotsmesh-update-' . md5(__DIR__);
        $lastUpdateTime = is_file($lastUpdateTimeFilename) ? (int) file_get_contents($lastUpdateTimeFilename) : 0;
        if ($lastUpdateTime + 600 < time()) {
            file_put_contents($lastUpdateTimeFilename, time());
            $update(__DIR__);
            echo 'Updated successfully!';
        } else {
            echo 'Checked/Updated in the last 10 minutes!';
        }
    }
    exit;
} elseif (isset($_GET['host'])) {
    $config = $getConfig();
    if (isset($_GET['admin'])) {
        // Send data to the admin panel. Improve maybe?
        define('DOTSMESH_INSTALLER_CONFIG', $config);
        define('DOTSMESH_INSTALLER_DIR', realpath(__DIR__ . '/..'));
    }
    define('DOTSMESH_SERVER_DATA_DIR', realpath(__DIR__ . '/../server-data'));
    define('DOTSMESH_SERVER_LOGS_DIR', realpath(__DIR__ . '/../server-logs'));
    if (isset($config['hosts'])) {
        if (!is_array($config['hosts'])) {
            $showError('The hosts config variable is not valid!');
        }
        define('DOTSMESH_SERVER_HOSTS', $config['hosts']);
    }
    require __DIR__ . '/dotsmesh-server-php/app/index.php';
} else {
    require __DIR__ . '/dotsmesh-web-app/app/index.php';
}
