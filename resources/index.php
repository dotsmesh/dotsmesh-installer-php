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
    $config = require DOTSMESH_SOURCE_DIR . '/../config.php';
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
        $indexContent = '<?php' . "\n\n" . 'define(\'DOTSMESH_SOURCE_DIR\', __DIR__);' . "\n\n" . 'require DOTSMESH_SOURCE_DIR . \'/' . $version . '/dotsmesh.phar\';';
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
    if (isset($config['autoUpdate']) && $config['autoUpdate']) {
        $lastUpdateTimeFilename = sys_get_temp_dir() . '/dotsmesh-update-' . md5(DOTSMESH_SOURCE_DIR);
        $lastUpdateTime = is_file($lastUpdateTimeFilename) ? (int) file_get_contents($lastUpdateTimeFilename) : 0;
        if ($lastUpdateTime + 600 < time()) {
            file_put_contents($lastUpdateTimeFilename, time());
            $update(DOTSMESH_SOURCE_DIR);
            echo 'Updated successfully!';
        } else {
            echo 'Checked/Updated in the last 10 minutes!';
        }
    } else {
        echo 'Auto-update is disabled!';
    }
    exit;
} elseif (isset($_GET['host'])) {
    $config = $getConfig();
    if (isset($_GET['admin'])) {
        define('DOTSMESH_INSTALLER_CONFIG', $config); // Send data to the admin panel. Improve maybe?
    }
    if (isset($config['serverDataDir'])) {
        if (!is_string($config['serverDataDir'])) {
            $showError('The serverDataDir config variable is not valid!');
        }
        define('DOTSMESH_SERVER_DATA_DIR', $config['serverDataDir']);
    }
    if (isset($config['serverLogsDir'])) {
        if (!is_string($config['serverLogsDir'])) {
            $showError('The serverLogsDir config variable is not valid!');
        }
        define('DOTSMESH_SERVER_LOGS_DIR', $config['serverLogsDir']);
    }
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
