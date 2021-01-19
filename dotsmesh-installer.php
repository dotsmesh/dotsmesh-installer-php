<?php

/*
 * Dots Mesh Installer
 * https://github.com/dotsmesh/dotsmesh-installer-php
 * Free to use under the GPL-3.0 license.
 * 
 * This is the file that the user should run on their machine.
 */

ini_set('max_execution_time', 300);

$devMode = false;

$scheme = (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') || (isset($_SERVER['HTTP_X_FORWARDED_PROTOCOL']) && $_SERVER['HTTP_X_FORWARDED_PROTOCOL'] === 'https') || (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'unknown';
$defaultInstallDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dotsmesh';

if ($devMode) {
    $host = 'dotsmesh.example.com';
}

$env = null;
if (is_file('dotsmesh-installer-environment.json')) {
    $env = json_decode(file_get_contents('dotsmesh-installer-environment.json'), true);
}
if (!is_array($env)) {
    $env = [];
}

if (!empty($env['dir'])) {
    $_POST['d'] = $env['dir'];
}

if (isset($_POST['d'], $_POST['p'], $_POST['u'])) {
    $dir = rtrim($_POST['d'], '\\/');
    $password = $_POST['p'];
    $autoUpdate = (int) $_POST['u'];
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
        $throwError('There is a file named "index.php" in the current directory!');
    }

    $pack = function (string $name, $value): string {
        return $name . ':' . json_encode($value);
    };

    set_error_handler(function ($errorNumber, $errorMessage, $errorFile, $errorLine) {
        throw new \ErrorException($errorMessage, 0, $errorNumber, $errorFile, $errorLine);
    });

    // Copied from resources/index.php
    $makeDir = function (string $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new \Exception('Cannot create dir (' . $dir . ')!');
            }
        }
    };
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
    $update = function (string $dir, $releaseChannel = 'stable') use ($makeRequest, $makeDir) {
        $latestVersionData = $makeRequest('GET', 'https://downloads.dotsmesh.com/' . $releaseChannel . '-php.json', [], 240);
        $latestVersionData = json_decode($latestVersionData, true);
        if (isset($latestVersionData['version'], $latestVersionData['checksums'], $latestVersionData['urls'])) {
            $version = $latestVersionData['version'];
            //$checksums = $latestVersionData['checksums'];
            $urls = $latestVersionData['urls'];
            $targetDir = $dir . '/' . $version;
            $indexFilename = $dir . '/index.php';
            $indexContent = '<?php' . "\n\n" .  'require __DIR__ . \'/' . $version . '/index.php\';';
            if (!is_file($indexFilename) || file_get_contents($indexFilename) !== $indexContent) {
                $sourceExists = false;
                if (is_dir($targetDir)) {
                    $sourceExists = true;
                } else {
                    foreach ($urls as $url) {
                        $content = $makeRequest('GET', $url, [], 240);
                        if (strlen($content) > 0) {
                            // todo check checksums
                            $tempFilename = tempnam(sys_get_temp_dir(), 'dotsmesh-installer');
                            file_put_contents($tempFilename, $content);
                            $zip = new \ZipArchive();
                            if ($zip->open($tempFilename)) {
                                $tempDir = $targetDir . '_temp_' . uniqid();
                                $makeDir($tempDir);
                                for ($i = 0; $i < $zip->numFiles; $i++) {
                                    $filename = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $zip->getNameIndex($i));
                                    $fullFilename = $tempDir . '/' . $filename;
                                    $makeDir(pathinfo($fullFilename, PATHINFO_DIRNAME));
                                    file_put_contents($fullFilename, $zip->getFromIndex($i));
                                }
                                $zip->close();
                                rename($tempDir, $targetDir);
                                unlink($tempFilename);
                                $sourceExists = true;
                                break;
                            } else {
                                throw new \Exception('Cannot open zip file (' . $tempFilename . ')!');
                            }
                        }
                    }
                }
                if ($sourceExists) {
                    file_put_contents($indexFilename, $indexContent);
                    return true;
                }
            }
        }
        return false;
    };

    try {

        $makeDir($dir);

        if (is_dir($dir) && sizeof(scandir($dir)) > 2) {
            $throwError('The directory "' . $dir . '" is not empty!');
        }

        if ($autoUpdate) {
            $response = $makeRequest('POST', 'https://downloads.dotsmesh.com/register-auto-update', ['host' => substr($host, 9)], 15);
            if ($response !== 'ok') {
                $throwError('Cannot connect to the Dots Mesh auto-update server! Please try again later.');
            }
        }

        $update($dir . '/code');

        $filename = $dir . '/server-data/' . md5(substr($host, 9)) . '/objects/a/pd';
        $makeDir(pathinfo($filename, PATHINFO_DIRNAME));
        file_put_contents($filename, $pack('0', password_hash($password, PASSWORD_DEFAULT)));

        $makeDir($dir . '/server-logs');
        $makeDir($dir . '/web-app-logs');

        $filename = $dir . '/config.php';
        file_put_contents($filename, '<?php

return [
    \'hosts\' => [\'' . substr($host, 9) . '\'],
    \'autoUpdate\' => ' . ($autoUpdate ? 'true' : 'false') . ',
    \'updateSecret\' => \'' . md5(uniqid()) . '\'
];
');

        file_put_contents($publicIndexFilename, '<?php' . "\n\n" . 'require \'' . $dir . '/code/index.php\';');
        if (!$devMode) {
            $installerFilename = __DIR__ . '/dotsmesh-installer.php';
            if (is_file($installerFilename)) {
                try {
                    unlink($installerFilename);
                } catch (\Exception $e) {
                }
            }
        }
        echo json_encode(['status' => 'success']);
        exit;
    } catch (\Exception $e) {
        $throwError($e->getMessage());
    }
}

$logo = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="2192.377" height="400"><g transform="matrix(7.041958 0 0 7.041958 201.20108 -404.37475)" fill="#fff"><use xlink:href="#B"/><use xlink:href="#C"/><path d="M124.9 99.315c-7.472 0-13.49-6.02-13.49-13.49s6.02-13.49 13.49-13.49 13.49 6.02 13.49 13.49-6.02 13.49-13.49 13.49z"/><use xlink:href="#B" y="38.342"/><use xlink:href="#B" x="38.342" y="38.342"/><use xlink:href="#B" x="38.342"/><use xlink:href="#C" x="20.876" y="20.875"/><use xlink:href="#C" x="41.75"/><use xlink:href="#C" x="20.876" y="-20.875"/><path d="M-28.572 100.6V71.07h10.457q15.727 0 15.727 14.4 0 6.896-4.302 11.013-4.282 4.117-11.425 4.117zm6.65-24.106v18.712h3.294q4.323 0 6.773-2.594 2.47-2.594 2.47-7.06 0-4.22-2.45-6.63-2.43-2.43-6.834-2.43zm36.95 24.62q-6.34 0-10.334-4.117Q.7 92.848.7 86.22q0-7 4.055-11.322Q8.8 70.575 15.5 70.575q6.32 0 10.2 4.138 3.9 4.138 3.9 10.9 0 6.958-4.055 11.22-4.035 4.26-10.54 4.26zm.288-24.806q-3.5 0-5.558 2.635Q7.7 81.547 7.7 85.87q0 4.385 2.06 6.937 2.06 2.553 5.393 2.553 3.438 0 5.455-2.47 2.017-2.5 2.017-6.896 0-4.6-1.956-7.143-1.956-2.553-5.352-2.553zm38.27.186h-8.42v24.106h-6.67V76.483h-8.378V71.07h23.468zM56.2 99.456V92.87q1.8 1.503 3.9 2.264 2.1.74 4.24.74 1.256 0 2.182-.226.947-.226 1.565-.618.638-.412.947-.947.3-.556.3-1.194 0-.865-.494-1.544-.494-.68-1.36-1.256-.844-.576-2.017-1.112-1.173-.535-2.532-1.1-3.458-1.44-5.167-3.52-1.688-2.08-1.688-5.023 0-2.306.926-3.952.926-1.667 2.5-2.738 1.606-1.07 3.705-1.565 2.1-.515 4.446-.515 2.306 0 4.076.288 1.8.268 3.294.844v6.155q-.74-.515-1.626-.906-.865-.4-1.8-.638-.926-.268-1.853-.4-.906-.124-1.73-.124-1.132 0-2.06.226-.926.206-1.565.597-.638.4-.988.947-.35.535-.35 1.215 0 .74.4 1.338.4.576 1.112 1.112.72.515 1.75 1.03 1.03.494 2.326 1.03 1.77.74 3.17 1.585 1.42.823 2.43 1.873 1 1.05 1.544 2.4.535 1.338.535 3.13 0 2.47-.947 4.158-.926 1.667-2.532 2.717-1.606 1.03-3.747 1.482-2.12.453-4.488.453-2.43 0-4.632-.412-2.182-.412-3.788-1.235zm148.2.144h-6.567V81.938q0-2.86.247-6.32h-.165q-.515 2.717-.926 3.9l-6.917 20.07h-5.435l-7.04-19.865q-.288-.803-.926-4.117h-.185q.268 4.364.268 7.658V99.6h-6V70.08h9.737l6.032 17.498q.72 2.1 1.05 4.22h.124q.556-2.45 1.173-4.26l6.03-17.458h9.5zm24.404 0H211.1V70.08h17.024v5.414H217.75v6.567h9.655v5.393h-9.655v6.752h11.054zm3.416-1.132V91.88q1.8 1.503 3.9 2.264 2.1.74 4.24.74 1.256 0 2.182-.226.947-.226 1.565-.618.638-.412.947-.947.3-.556.3-1.194 0-.865-.494-1.544-.494-.68-1.36-1.256-.844-.576-2.017-1.112-1.173-.535-2.532-1.1-3.458-1.44-5.167-3.52-1.688-2.08-1.688-5.023 0-2.306.926-3.952.926-1.667 2.5-2.738 1.606-1.07 3.705-1.565 2.1-.515 4.446-.515 2.306 0 4.076.288 1.8.268 3.294.844v6.155q-.74-.515-1.626-.906-.865-.4-1.8-.638-.926-.268-1.853-.4-.906-.124-1.73-.124-1.132 0-2.06.226-.926.206-1.565.597-.638.4-.988.947-.35.535-.35 1.215 0 .74.4 1.338.4.576 1.112 1.112.72.515 1.75 1.03 1.03.494 2.326 1.03 1.77.74 3.17 1.585 1.42.823 2.43 1.873 1 1.05 1.544 2.4.535 1.338.535 3.13 0 2.47-.947 4.158-.926 1.667-2.532 2.717-1.606 1.03-3.747 1.482-2.12.453-4.488.453-2.43 0-4.632-.412-2.182-.412-3.788-1.235zm50.54 1.132h-6.67V87.58h-12.23V99.6h-6.65V70.08h6.65v11.775h12.228V70.08h6.67z"/></g><defs ><path id="B" d="M105.728 75.884c-5.112 0-9.23-4.118-9.23-9.23s4.118-9.23 9.23-9.23 9.23 4.118 9.23 9.23-4.118 9.23-9.23 9.23z"/><path id="C" d="M99.054 85.825c0 2.658 2.196 4.97 4.97 4.97a4.93 4.93 0 0 0 4.97-4.97c0-2.774-2.312-4.97-5.086-4.97s-4.855 2.196-4.855 4.97z"/></defs></svg>';

?><html>

<head>
    <meta charset="utf-8">
    <title>Dots Mesh Installer</title>
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
        }

        .window {
            flex: 1 1 auto;
            transition: opacity 100ms;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            width: 680px;
            margin: 0 auto;
            padding: 0 15px 100px 15px;
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
            word-break: break-word;
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
            margin-top: -4px;
            max-width: 260px;
        }

        .textbox {
            max-width: 260px;
            text-align: center;
            display: block;
            border: 0;
            border-radius: 8px;
            width: 100%;
            padding: 0 13px;
            height: 48px;
            box-sizing: border-box;
            background-color: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            font-size: 15px;
        }

        .textbox:focus {
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .checkbox,
        .checkbox * {
            user-select: none;
        }

        .checkbox {
            position: relative;
            display: inline-block;
            height: 42px;
            text-align: left;
            line-height: 42px;
            padding-left: 60px;
            box-sizing: border-box;
        }

        .checkbox span {
            line-height: 42px;
            display: inline-block;
            font-size: 15px;
        }

        .checkbox>input[type="checkbox"] {
            display: none;
        }

        .checkbox>input[type="checkbox"]+span:before {
            content: "";
            display: block;
            position: absolute;
            width: 42px;
            height: 42px;
            border-radius: 8px;
            background-color: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            line-height: 42px;
            font-size: 20px;
            font-weight: bold;
            text-align: center;
            margin-left: -60px;
            cursor: default;
            color: #fff;
        }

        .checkbox>input[type="checkbox"]:checked+span:before {
            content: "✓";
        }

        .button {
            user-select: none;
            font-size: 15px;
            display: inline-block;
            border-radius: 8px;
            padding: 0 30px;
            min-height: 48px;
            box-sizing: border-box;
            background-color: rgba(255, 255, 255, 1);
            color: #111;
            line-height: 48px;
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

        .hint {
            max-width: 360px;
            font-size: 13px;
            line-height: 24px;
            text-align: center;
            color: #999;
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
            if (element !== null) {
                var dir = element.value.trim();
                if (dir.length === 0) {
                    element.focus();
                    alert('You must specify an install directory!');
                    return;
                }
            } else {
                var dir = null;
            }
            var element = document.getElementById('install-pass');
            var adminPassword = element.value.trim();
            if (adminPassword.length === 0) {
                element.focus();
                alert('The administrator password is required!');
                return;
            }
            if (adminPassword.length < 6) {
                element.focus();
                alert('The administrator password is must contain atleast 6 characters!');
                return;
            }

            var enableAutoUpdate = document.getElementById('install-autoupdate').checked;

            formElement.style.opacity = 0;
            setTimeout(async () => {
                formElement.style.display = 'none';
                installingElement.style.display = 'flex';
                installingElement.style.opacity = 1;
                setTimeout(async () => {
                    let formData = new FormData();
                    formData.append('d', dir);
                    formData.append('p', adminPassword);
                    formData.append('u', enableAutoUpdate ? 1 : 0);
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
        <div style="padding-top:100px;padding-bottom:70px;">
            <span style="display:block;width:160px;margin:0 auto;height:25px;background-size:contain;background-position:center;background-repeat:no-repeat;background-image:url(data:image/svg+xml;base64,<?= base64_encode($logo) ?>)"></span>
        </div>
    </header>
    <?php if (is_file(__DIR__ . '/index.php')) { ?>
        <div class="window">
            <div class="title">Already installed?</div>
            <div class="text" style="max-width:360px;">There is a file named "index.php" alongside "dotsmesh-installer.php". This indicates that your Dots Mesh host may already be installed.</div>
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
            <div class="title">Let's make <?= substr($host, 9) ?><br>part of the platform!</div>

            <?php if (empty($env['dir'])) { ?>
                <label class="label" for="install-dir">Install directory</label>
                <input type="textbox" id="install-dir" class="textbox" style="max-width:360px;" value="<?= htmlentities($defaultInstallDir) ?>" />
                <div class="hint">The source code and the users data will be stored here.</div>
                <br>
            <?php } ?>

            <label class="label" for="install-pass">Administrator password</label>
            <input type="password" id="install-pass" class="textbox" />
            <div class="hint">Will be used to log into the administrator's panel to reserve spaces for profiles and groups.</div>
            <br>

            <label class="checkbox"><input type="checkbox" id="install-autoupdate" /><span>Enable auto updates</span></label>
            <div class="hint">If enabled, your hostname will be send to the Dots Mesh team, so they can ping your server when there is an update.</div>

            <br>
            <span class="button" onclick="install()">Install</span>
            <br>
            <br>
        </div>
        <div class="window" id="installing" style="display:none;opacity:0;">
            <div class="title">Installing</div>
            <div class="text" style="max-width:260px;">Please wait while downloading and installing the needed files.</div><br>
        </div>
        <div class="window" id="error" style="display:none;opacity:0;">
            <div class="title">Oops!</div>
            <div class="text" style="max-width:260px;">The following error occured while installing your Dots Mesh host:</div>
            <div class="text" style="max-width:260px;" id="error-message"></div><br>
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