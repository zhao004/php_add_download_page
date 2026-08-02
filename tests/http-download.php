<?php

declare(strict_types=1);

/**
 * 通过已运行的站点验证登录、CSRF、APK 上传、本地 302、其他网盘中转与下载日志。
 *
 * 本测试会恢复全部下载配置，并删除自己上传的临时文件；凭据只从环境变量读取。
 */
final class DownloadHttpTest
{
    private const DEFAULT_BASE_URL = 'http://127.0.0.1:8080';
    private const REQUEST_TIMEOUT_SECONDS = 30;
    private const TEST_OTHER_SHARE_URL = 'https://example.invalid/download-validation';
    private const TEST_OTHER_PASSWORD = 'validation-password';

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $adminPassword;

    /** @var string */
    private $adminUsername;

    /** @var array<string, string> */
    private $cookies = [];

    /** @var PDO */
    private $database;

    /** @var string */
    private $tablePrefix;

    /** @var string */
    private $fixturePath = '';

    /** @var string */
    private $uploadedRelativePath = '';

    /** @var string */
    private $imageFixturePath = '';

    /** @var string */
    private $uploadedImageRelativePath = '';

    /** @var array<string, mixed> */
    private $originalConfig = [];

    public function __construct()
    {
        $this->baseUrl = rtrim((string) (getenv('NOVA_TEST_BASE_URL') ?: self::DEFAULT_BASE_URL), '/');
        $this->adminUsername = trim((string) (getenv('NOVA_TEST_ADMIN_USERNAME') ?: 'admin'));
        $this->adminPassword = (string) getenv('NOVA_TEST_ADMIN_PASSWORD');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $this->adminUsername)) {
            throw new RuntimeException('NOVA_TEST_ADMIN_USERNAME 不是有效的管理员用户名。');
        }
        if ($this->adminPassword === '') {
            throw new RuntimeException('请通过 NOVA_TEST_ADMIN_PASSWORD 提供测试管理员密码。');
        }

        [$this->database, $this->tablePrefix] = $this->databaseConnection();
    }

    /**
     * @return void
     */
    public function run(): void
    {
        try {
            $this->login();
            $csrfToken = $this->downloadPageToken();
            $this->originalConfig = $this->loadDownloadConfig();
            $this->assertImageWidgets();
            $this->uploadImageFixture($csrfToken);
            $this->fixturePath = $this->createFixture();
            $this->uploadFixture($csrfToken);
            $this->switchToLocalMode($this->downloadPageToken());
            $location = $this->assertDownloadRedirect();
            $this->assertLatestLog('local');
            $this->switchToOtherMode($this->downloadPageToken());
            $this->assertOtherDownloadGateway();
            $this->assertLatestLog('other');

            fwrite(STDOUT, "HTTP 下载集成测试通过。\n");
            fwrite(STDOUT, "图片上传：success\n");
            fwrite(STDOUT, '302 地址：' . $location . PHP_EOL);
            fwrite(STDOUT, "下载日志：local|success\n");
            fwrite(STDOUT, "其他网盘中转：success\n");
            fwrite(STDOUT, "下载日志：other|success\n");
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return void
     */
    private function login(): void
    {
        $page = $this->request('GET', '/admin/login');
        $token = $this->csrfToken($page['body']);
        $captchaResponse = $this->request('GET', '/admin/captcha');
        if ($captchaResponse['status'] !== 200) {
            throw new RuntimeException('无法获取后台登录验证码。');
        }
        preg_match_all('/<text\b[^>]*>([A-Za-z0-9])<\/text>/', $captchaResponse['body'], $captchaCharacters);
        $captcha = implode('', $captchaCharacters[1] ?? []);
        if (strlen($captcha) !== 4) {
            throw new RuntimeException('后台登录验证码格式异常。');
        }
        $response = $this->request('POST', '/admin/login', [
            '_token' => $token,
            'username' => $this->adminUsername,
            'password' => $this->adminPassword,
            'captcha' => $captcha,
            'redirect' => '/admin/download',
        ], true);
        if ((string) parse_url($response['url'], PHP_URL_PATH) !== '/admin/download') {
            throw new RuntimeException('后台登录失败，请检查测试账号与密码。');
        }
    }

    /**
     * @return string 下载配置页 CSRF 令牌
     */
    private function downloadPageToken(): string
    {
        $response = $this->request('GET', '/admin/download');
        if ($response['status'] !== 200) {
            throw new RuntimeException('无法打开下载配置页。');
        }
        return $this->csrfToken($response['body']);
    }

    /**
     * @return void
     */
    private function assertImageWidgets(): void
    {
        foreach (['/admin/site', '/admin/content/preview/create', '/admin/content/trust/create'] as $path) {
            $response = $this->request('GET', $path);
            if ($response['status'] !== 200 || strpos($response['body'], 'data-image-upload') === false) {
                throw new RuntimeException('后台图片上传控件未正确渲染：' . $path);
            }
        }
    }

    /**
     * @param string $csrfToken 后台 CSRF 令牌
     * @return void
     */
    private function uploadImageFixture(string $csrfToken): void
    {
        $this->imageFixturePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'nova-http-image-' . bin2hex(random_bytes(8)) . '.png';
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        if (!is_string($png) || file_put_contents($this->imageFixturePath, $png) === false) {
            throw new RuntimeException('无法创建 HTTP 图片测试文件。');
        }

        $response = $this->request('POST', '/admin/media/image', [
            '_token' => $csrfToken,
            'category' => 'icons',
            'image' => new CURLFile($this->imageFixturePath, 'image/png', 'validation.png'),
        ], false, true);
        $payload = json_decode($response['body'], true);
        $path = is_array($payload) ? (string) ($payload['path'] ?? '') : '';
        if ($response['status'] !== 200
            || !is_array($payload)
            || ($payload['success'] ?? false) !== true
            || !preg_match('#^/uploads/images/icons/\d{6}/[a-f0-9]{32}\.png$#', $path)) {
            throw new RuntimeException('图片上传接口未返回有效随机路径。');
        }
        $this->uploadedImageRelativePath = $path;
        $storedPath = dirname(__DIR__) . '/public' . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!is_file($storedPath)) {
            throw new RuntimeException('图片上传接口返回的文件不存在。');
        }
    }

    /**
     * @param string $csrfToken 后台 CSRF 令牌
     * @return void
     */
    private function uploadFixture(string $csrfToken): void
    {
        $response = $this->request('POST', '/admin/download/upload', [
            '_token' => $csrfToken,
            'apk' => new CURLFile($this->fixturePath, 'application/octet-stream', 'validation.apk'),
        ], true, true);
        if ($response['status'] !== 200 || strpos($response['body'], 'APK 已上传') === false) {
            throw new RuntimeException('APK 上传接口未返回成功反馈。');
        }

        $config = $this->loadDownloadConfig();
        $path = (string) ($config['local_apk_path'] ?? '');
        if (!preg_match('#^/uploads/apk/[a-f0-9]{32}\.apk$#', $path)) {
            throw new RuntimeException('上传后保存的 APK 路径不符合随机命名规则。');
        }
        $this->uploadedRelativePath = $path;
    }

    /**
     * @param string $csrfToken 后台 CSRF 令牌
     * @return void
     */
    private function switchToLocalMode(string $csrfToken): void
    {
        $response = $this->request('POST', '/admin/download', [
            '_token' => $csrfToken,
            'download_mode' => 'local',
            'lanzou_url' => (string) ($this->originalConfig['lanzou_url'] ?? ''),
            'lanzou_pwd' => '',
            'other_url' => (string) ($this->originalConfig['other_url'] ?? ''),
            'other_pwd' => '',
        ], true);
        if ($response['status'] !== 200 || strpos($response['body'], '下载配置已保存') === false) {
            throw new RuntimeException('无法切换到本地下载模式。');
        }
    }

    /**
     * @param string $csrfToken 后台 CSRF 令牌
     * @return void
     */
    private function switchToOtherMode(string $csrfToken): void
    {
        $response = $this->request('POST', '/admin/download', [
            '_token' => $csrfToken,
            'download_mode' => 'other',
            'lanzou_url' => (string) ($this->originalConfig['lanzou_url'] ?? ''),
            'lanzou_pwd' => '',
            'other_url' => self::TEST_OTHER_SHARE_URL,
            'other_pwd' => self::TEST_OTHER_PASSWORD,
        ], true);
        if ($response['status'] !== 200 || strpos($response['body'], '下载配置已保存') === false) {
            throw new RuntimeException('无法切换到其他网盘下载模式。');
        }
    }

    /**
     * @return string 302 Location
     */
    private function assertDownloadRedirect(): string
    {
        $response = $this->request('GET', '/download');
        $location = (string) ($response['headers']['location'] ?? '');
        if ($response['status'] !== 302 || $location !== $this->uploadedRelativePath) {
            throw new RuntimeException(sprintf(
                '本地下载跳转异常，状态 %d，地址 %s。',
                $response['status'],
                $location === '' ? '空' : $location
            ));
        }
        return $location;
    }

    /**
     * @return void
     */
    private function assertOtherDownloadGateway(): void
    {
        $response = $this->request('GET', '/download');
        $location = (string) ($response['headers']['location'] ?? '');
        if ($response['status'] !== 200
            || $location !== ''
            || strpos($response['body'], self::TEST_OTHER_SHARE_URL) === false
            || strpos($response['body'], self::TEST_OTHER_PASSWORD) === false
            || strpos($response['body'], 'data-download-other') === false) {
            throw new RuntimeException('其他网盘中转页未正确展示原始链接或访问密码。');
        }
    }

    /**
     * @param string $expectedMode 期望下载模式
     * @return void
     */
    private function assertLatestLog(string $expectedMode): void
    {
        $statement = $this->database->query(sprintf(
            'SELECT download_mode, status FROM `%sdownload_click_log` ORDER BY id DESC LIMIT 1',
            $this->tablePrefix
        ));
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['download_mode'] !== $expectedMode || $row['status'] !== 'success') {
            throw new RuntimeException('下载日志未记录 ' . $expectedMode . '|success。');
        }
    }

    /**
     * @return string 临时 APK 路径
     */
    private function createFixture(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nova-upload-' . bin2hex(random_bytes(8)) . '.apk';
        if (file_put_contents($path, "PK\x03\x04NOVA validation fixture") === false) {
            throw new RuntimeException('无法创建上传测试文件。');
        }
        return $path;
    }

    /**
     * @return array<string, mixed> 当前下载配置
     */
    private function loadDownloadConfig(): array
    {
        $statement = $this->database->query(sprintf(
            'SELECT download_mode, local_apk_path, lanzou_url, lanzou_pwd, other_url, other_pwd, updated_at '
            . 'FROM `%ssite_config` WHERE id = 1',
            $this->tablePrefix
        ));
        $config = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($config)) {
            throw new RuntimeException('测试数据库缺少站点配置。');
        }
        return $config;
    }

    /**
     * @return array{0:PDO,1:string} 数据库连接与表前缀
     */
    private function databaseConnection(): array
    {
        $environment = parse_ini_file(dirname(__DIR__) . '/.env', true, INI_SCANNER_RAW);
        $database = is_array($environment) && isset($environment['DATABASE'])
            ? $environment['DATABASE']
            : [];
        if (!is_array($database)) {
            throw new RuntimeException('无法读取测试数据库配置。');
        }

        $host = (string) ($database['HOSTNAME'] ?? '127.0.0.1');
        $port = filter_var($database['HOSTPORT'] ?? 3306, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        $name = (string) ($database['DATABASE'] ?? '');
        $charset = (string) ($database['CHARSET'] ?? 'utf8mb4');
        if ($port === false || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)
            || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $charset)) {
            throw new RuntimeException('测试数据库配置格式无效。');
        }

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset),
            (string) ($database['USERNAME'] ?? ''),
            (string) ($database['PASSWORD'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $prefix = (string) ($database['PREFIX'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]{0,32}$/', $prefix)) {
            throw new RuntimeException('数据库表前缀格式无效。');
        }
        return [$pdo, $prefix];
    }

    /**
     * @param string $html 后台页面 HTML
     * @return string CSRF 令牌
     */
    private function csrfToken(string $html): string
    {
        if (!preg_match('/name="_token"\s+value="([^"]+)"/', $html, $match)) {
            throw new RuntimeException('页面缺少 CSRF 令牌。');
        }
        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param string $method GET/POST
     * @param string $path 站内路径
     * @param array<string, mixed> $data 表单数据
     * @param bool $followRedirects 是否跟随站内跳转
     * @param bool $multipart 是否为文件表单
     * @return array{status:int,body:string,headers:array<string,string>,url:string}
     */
    private function request(
        string $method,
        string $path,
        array $data = [],
        bool $followRedirects = false,
        bool $multipart = false
    ): array {
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('无法初始化 HTTP 测试请求。');
        }
        $headers = [];
        $responseHeaders = [];
        $cookieHeader = '';
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $cookieHeader = implode('; ', $pairs);
        }

        $options = [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers,
            // 内存 Cookie 引擎会在登录轮换 Session ID 后立即用于下一跳。
            CURLOPT_COOKIEFILE => '',
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                if (stripos($line, 'HTTP/') === 0) {
                    $responseHeaders = [];
                }
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $value = trim(substr($line, $separator + 1));
                    $responseHeaders[$name] = $value;
                    if ($name === 'set-cookie') {
                        $cookie = explode(';', $value, 2)[0];
                        $cookieSeparator = strpos($cookie, '=');
                        if ($cookieSeparator !== false) {
                            $cookieName = trim(substr($cookie, 0, $cookieSeparator));
                            $cookieValue = trim(substr($cookie, $cookieSeparator + 1));
                            if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $cookieName)) {
                                $this->cookies[$cookieName] = $cookieValue;
                            }
                        }
                    }
                }
                return strlen($line);
            },
        ];
        if ($cookieHeader !== '') {
            $options[CURLOPT_COOKIE] = $cookieHeader;
        }
        if (strtoupper($method) === 'POST') {
            $options[CURLOPT_POSTFIELDS] = $multipart
                ? $data
                : http_build_query($data, '', '&', PHP_QUERY_RFC3986);
            if (!$multipart) {
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false) {
            throw new RuntimeException('HTTP 测试请求失败：' . mb_substr($error, 0, 180));
        }

        return [
            'status' => $status,
            'body' => $body,
            'headers' => $responseHeaders,
            'url' => $effectiveUrl,
        ];
    }

    /**
     * @return void
     */
    private function cleanup(): void
    {
        if ($this->originalConfig !== []) {
            try {
                $statement = $this->database->prepare(sprintf(
                    'UPDATE `%ssite_config` SET download_mode = :download_mode, local_apk_path = :local_apk_path, '
                    . 'lanzou_url = :lanzou_url, lanzou_pwd = :lanzou_pwd, other_url = :other_url, '
                    . 'other_pwd = :other_pwd, updated_at = :updated_at WHERE id = 1',
                    $this->tablePrefix
                ));
                $statement->execute([
                    'download_mode' => (string) $this->originalConfig['download_mode'],
                    'local_apk_path' => (string) $this->originalConfig['local_apk_path'],
                    'lanzou_url' => (string) $this->originalConfig['lanzou_url'],
                    'lanzou_pwd' => (string) $this->originalConfig['lanzou_pwd'],
                    'other_url' => (string) $this->originalConfig['other_url'],
                    'other_pwd' => (string) $this->originalConfig['other_pwd'],
                    'updated_at' => (string) $this->originalConfig['updated_at'],
                ]);
            } catch (Throwable $exception) {
                fwrite(STDERR, '警告：无法恢复下载配置。' . PHP_EOL);
            }
        }

        if (preg_match('#^/uploads/apk/[a-f0-9]{32}\.apk$#', $this->uploadedRelativePath)) {
            $publicDirectory = realpath(dirname(__DIR__) . '/public');
            $candidate = realpath(dirname(__DIR__) . '/public' . str_replace('/', DIRECTORY_SEPARATOR, $this->uploadedRelativePath));
            if ($publicDirectory !== false && $candidate !== false
                && strpos($candidate, $publicDirectory . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'apk' . DIRECTORY_SEPARATOR) === 0
                && is_file($candidate)) {
                @unlink($candidate);
            }
        }
        if ($this->fixturePath !== '' && is_file($this->fixturePath)) {
            @unlink($this->fixturePath);
        }
        if ($this->imageFixturePath !== '' && is_file($this->imageFixturePath)) {
            @unlink($this->imageFixturePath);
        }
        if (preg_match('#^/uploads/images/icons/\d{6}/[a-f0-9]{32}\.png$#', $this->uploadedImageRelativePath)) {
            $candidate = realpath(
                dirname(__DIR__) . '/public' . str_replace('/', DIRECTORY_SEPARATOR, $this->uploadedImageRelativePath)
            );
            $allowedDirectory = realpath(dirname(__DIR__) . '/public/uploads/images/icons');
            if ($candidate !== false && $allowedDirectory !== false
                && strpos($candidate, $allowedDirectory . DIRECTORY_SEPARATOR) === 0
                && is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }
}

try {
    (new DownloadHttpTest())->run();
} catch (Throwable $exception) {
    fwrite(STDERR, 'HTTP 下载集成测试失败：' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
