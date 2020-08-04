<?php

$scheme = (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') || (isset($_SERVER['HTTP_X_FORWARDED_PROTOCOL']) && $_SERVER['HTTP_X_FORWARDED_PROTOCOL'] === 'https') || (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'unknown';
$defaultInstallDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dotsmesh';

if (isset($_POST['dir'], $_POST['pass'])) {
    $dir = rtrim($_POST['dir'], '\\/');
    $password = $_POST['pass'];
    $throwError = function (string $message) {
        echo json_encode(['status' => 'error', 'message' => $message]);
        exit;
    };
    if (strpos($host, 'dotsmesh.') !== 0) {
        $throwError('The domain name of the host must start with "dotsmesh." (dotsmesh.example.com)!');
    }
    if ($scheme !== 'https') {
        $throwError('Your connection is not secure! HTTPS is a requirement!');
    }
    $publicIndexFilename = __DIR__ . '/index.php';
    if (is_file($publicIndexFilename)) {
        $throwError('There is a file called "index.php" in the current directory!');
    }

    $pack = function (string $name, $value): string {
        return $name . ':' . json_encode($value);
    };

    set_error_handler(function ($errorNumber, $errorMessage, $errorFile, $errorLine) {
        throw new \ErrorException($errorMessage, 0, $errorNumber, $errorFile, $errorLine);
    });

    $makeDir = function (string $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new \Exception('Cannot create dir (' . $dir . ')!');
            }
        }
    };

    $makeFileDir = function (string $filename) use ($makeDir) {
        $makeDir(pathinfo($filename, PATHINFO_DIRNAME));
    };

    // Copied from resources/index.php
    $update = function ($dir) {
        $makeRequest = function (string $method, string $url, array $data = []): string {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url . ($method === 'GET' && !empty($data) ? '?' . http_build_query($data) : ''));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
        $makeDir = function (string $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0777, true)) {
                    throw new \Exception('Cannot create dir (' . $dir . ')!');
                }
            }
        };
        $latestVersionData = $makeRequest('GET', 'https://downloads.dotsmesh.com/stable-php.json');
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
                    $content = $makeRequest('GET', $url);
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

    try {

        $makeDir($dir);

        $files = scandir($dir);
        if (sizeof($files) > 2) {
            $throwError('The directory "' . $dir . '" is not empty!');
        }

        $update($dir . '/source');

        $filename = $dir . '/server-data/objects/a/p/' . substr($host, 9);
        $makeFileDir($filename);
        file_put_contents($filename, $pack('0', password_hash($password, PASSWORD_DEFAULT)));

        $makeDir($dir . '/server-logs');

        $filename = $dir . '/config.php';
        file_put_contents($filename, '<?php

return [
    \'serverDataDir\' => __DIR__ . \'/server-data\',
    \'serverLogsDir\' => __DIR__ . \'/server-logs\',
    \'hosts\' => [\'' . substr($host, 9) . '\'],
    \'autoUpdate\' => true
];
');

        file_put_contents($publicIndexFilename, '<?php' . "\n\n" . 'require \'' . $dir . '/index.php\';');
        // todo delete dotsmesh-installer.php
        echo json_encode(['status' => 'success']);
        exit;
    } catch (\Exception $e) {
        $throwError($e->getMessage());
    }
}

?><html>

<head>
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,minimal-ui">
    <style>
        html,
        body {
            padding: 0;
            margin: 0;
            min-height: 100%;
        }

        * {
            outline: none;
            -webkit-tap-highlight-color: rgba(0, 0, 0, 0);
            line-height: 160%;
        }

        h1,
        h2,
        h3 {
            margin: 0;
        }

        body,
        input {
            font-size: 17px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
        }

        body {
            background: #111111;
            color: #fff;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            display: flex;
        }


        header {
            flex: 0 0 auto;
            padding-top: 40px;
            padding-bottom: 25px;
        }

        .window {
            flex: 1 1 auto;
            transition: opacity 100ms;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            max-width: 680px;
            margin: 0 auto;
            padding: 0 15px 30px 15px;
            box-sizing: border-box;
            width: 100%;
        }

        .window>*:not(:first-child) {
            margin-top: 15px;
        }

        .text {
            font-size: 15px;
            line-height: 24px;
            text-align: center;
        }

        .title {
            padding-bottom: 30px;
            font-size: 17px;
            line-height: 24px;
            text-align: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            font-weight: bold;
            font-size: 25px;
            line-height: 160%;
        }

        .label {
            font-size: 15px;
            line-height: 160%;
            display: block;
            padding-bottom: 2px;
            margin-top: -4px;
            max-width: 260px;
        }

        .textbox {
            max-width: 260px;
            text-align: center;
            display: block;
            border: 0;
            border-radius: 4px;
            width: 100%;
            padding: 0 13px;
            height: 42px;
            box-sizing: border-box;
            background-color: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            font-size: 15px;
        }

        .textbox:focus {
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .button {
            user-select: none;
            font-size: 15px;
            display: inline-block;
            border-radius: 4px;
            padding: 0 30px;
            height: 42px;
            box-sizing: border-box;
            background-color: rgba(255, 255, 255, 1);
            color: #111;
            line-height: 42px;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
        }

        .button:hover {
            background-color: rgba(255, 255, 255, 0.96);
        }

        .button:active {
            background-color: rgba(255, 255, 255, 0.92);
        }

        .button-2 {
            background-color: rgba(255, 255, 255, 0.04);
            color: #fff;
        }

        .button-2:hover {
            background-color: rgba(255, 255, 255, 0.08);
        }

        .button-2:active {
            background-color: rgba(255, 255, 255, 0.12);
        }
    </style>

    <script>
        var animationTime = 100;
        var install = () => {
            var formElement = document.getElementById('form');
            var installingElement = document.getElementById('installing');
            var errorElement = document.getElementById('error');
            var errorMessageElement = document.getElementById('error-message');
            var successElement = document.getElementById('success');

            var element = document.getElementById('install-dir');
            var dir = element.value.trim();
            if (dir.length === 0) {
                element.focus();
                alert('You must specify an install directory!');
                return;
            }
            var element = document.getElementById('install-pass');
            var adminPassword = element.value.trim();
            if (adminPassword.length === 0) {
                element.focus();
                alert('The administrator password is required!');
                return;
            }
            if (adminPassword.length < 0) {
                element.focus();
                alert('The administrator password is required!');
                return;
            }

            formElement.style.opacity = 0;
            setTimeout(async () => {
                formElement.style.display = 'none';
                installingElement.style.display = 'flex';
                installingElement.style.opacity = 1;
                setTimeout(async () => {
                    let formData = new FormData();
                    formData.append('dir', dir);
                    formData.append('pass', adminPassword);
                    var response = await fetch(location.href, {
                        method: 'POST',
                        body: formData
                    });
                    var result = JSON.parse(await response.text());
                    installingElement.style.opacity = 0;
                    setTimeout(async () => {
                        installingElement.style.display = 'none';
                        if (result.status !== undefined) {
                            if (result.status === 'success') {
                                successElement.style.display = 'flex';
                                successElement.style.opacity = '1';
                                return;
                            } else if (result.status === 'error') {
                                errorElement.style.display = 'flex';
                                errorElement.style.opacity = '1';
                                errorMessageElement.innerText = result.message;
                                return;
                            }
                        }
                        errorElement.style.display = 'flex';
                        errorElement.style.opacity = '1';
                        errorMessageElement.innerText = 'Unknown error';
                    }, animationTime + 16);
                }, animationTime + 16);
            }, animationTime + 16);
        };
    </script>
</head>

<body>
    <header>
        <div class="logo">DOTS MESH<span>INSTALLER</span></div>
    </header>
    <?php if (is_file(__DIR__ . '/index.php')) { ?>
        <div class="window">
            <div class="title">Already installed?</div>
            <div class="text" style="max-width:360px;">There is a file called "index.php" alongside "dotsmesh-installer.php". This may indicate that your Dots Mesh host is installed.</div>
        </div>
    <?php } elseif (strpos($host, 'dotsmesh.') !== 0) { ?>
        <div class="window">
            <div class="title">A litte fix is needed!</div>
            <div class="text" style="max-width:360px;">There is a strict requirement for the domain name of your Dots Mesh host. Currently it's <strong><?= $host ?></strong>. It must look like this: <strong>dotsmesh.example.com</strong>.<br>It must start with "dotsmesh." and the profiles created later will end with ".example.com".</div>
        </div>
    <?php } elseif ($scheme !== 'https') { ?>
        <div class="window">
            <div class="title">A litte fix is needed!</div>
            <div class="text" style="max-width:360px;">Your connection is not secure!<br>HTTPS is a requirement!</div>
        </div>
    <?php } else { ?>
        <div class="window" id="form" style2="display:none;">
            <div class="title">Ready to install?</div>
            <div class="text" style="max-width:200px;">Let's make <strong><?= substr($host, 9) ?></strong> part of the platform!<br><br></div>
            <label class="label" for="install-dir">Install directory</label>
            <input type="textbox" id="install-dir" class="textbox" value="<?= htmlentities($defaultInstallDir) ?>" />
            <br>

            <label class="label" for="install-pass">Administrator password</label>
            <input type="password" id="install-pass" class="textbox" />
            <br>
            <span class="button" onclick="install()">Install</span>
        </div>
        <div class="window" id="installing" style="display:none;opacity:0;">
            <div class="title">Installing</div>
            <div class="text" style="max-width:260px;">Please wait while downloading and installing the needed files.</div><br>
        </div>
        <div class="window" id="error" style="display:none;opacity:0;">
            <div class="title">Oops!</div>
            <div class="text" style="max-width:260px;">The following error occured while installing your Dots Mesh host:</div>
            <div class="text" style="max-width:260px;" id="error-message"></div>
            <a class="button" onclick="location.reload()">Back</a>
        </div>
        <div class="window" id="success" style="display:none;opacity:0;">
            <div class="title">Success!</div>
            <div class="text" style="max-width:260px;">You're now an owner of a Dots Mesh host. Visit the administrator panel to create your first profile.</div><br>
            <a class="button" href="https://<?= $host ?>?host&admin">Administrator panel</a>
        </div>
    <?php } ?>
</body>

</html>