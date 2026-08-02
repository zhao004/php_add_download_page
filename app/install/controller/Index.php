<?php

declare(strict_types=1);

namespace app\install\controller;

use app\BaseController;
use app\common\exception\InstallException;
use app\common\service\DatabaseInstaller;
use app\common\service\EnvironmentService;
use app\common\service\InstallationService;
use app\common\service\InstallInputValidator;
use app\common\service\InstallStateService;
use think\App;
use think\facade\Log;
use think\facade\Session;
use think\facade\View;
use think\Response;
use Throwable;

/**
 * 提供首次部署所需的五步安装流程。
 */
class Index extends BaseController
{
    private const COMPLETION_ACCESS_SECONDS = 600;

    /** @var InstallStateService */
    private $installState;

    /** @var EnvironmentService */
    private $environmentService;

    /** @var InstallInputValidator */
    private $inputValidator;

    /** @var DatabaseInstaller */
    private $databaseInstaller;

    /** @var InstallationService */
    private $installationService;

    /**
     * @param App $app ThinkPHP 应用实例
     * @param InstallStateService $installState 安装状态服务
     * @param EnvironmentService $environmentService 环境检测服务
     * @param InstallInputValidator $inputValidator 输入校验服务
     * @param DatabaseInstaller $databaseInstaller 数据库安装服务
     * @param InstallationService $installationService 安装编排服务
     */
    public function __construct(
        App $app,
        InstallStateService $installState,
        EnvironmentService $environmentService,
        InstallInputValidator $inputValidator,
        DatabaseInstaller $databaseInstaller,
        InstallationService $installationService
    ) {
        parent::__construct($app);
        $this->installState = $installState;
        $this->environmentService = $environmentService;
        $this->inputValidator = $inputValidator;
        $this->databaseInstaller = $databaseInstaller;
        $this->installationService = $installationService;
    }

    /**
     * @return Response|string 欢迎页或已安装提示
     */
    public function index()
    {
        if ($response = $this->denyInstalledAccess()) {
            return $response;
        }

        return View::fetch('index', [
            'pageTitle' => '开始安装',
            'currentStep' => 1,
            'csrfToken' => $this->csrfToken(),
        ]);
    }

    /**
     * @return Response|string 环境检测页或已安装提示
     */
    public function environment()
    {
        if ($response = $this->denyInstalledAccess()) {
            return $response;
        }

        $checks = $this->environmentService->checks();

        return View::fetch('environment', [
            'pageTitle' => '环境检测',
            'currentStep' => 2,
            'checks' => $checks,
            'canInstall' => $this->environmentService->canInstall(),
        ]);
    }

    /**
     * @return Response|string 数据库配置页或重定向
     */
    public function database()
    {
        if ($response = $this->denyInstalledAccess()) {
            return $response;
        }
        if (!$this->environmentService->canInstall()) {
            return redirect('/install/environment');
        }

        $values = $this->databaseFormValues(Session::get('install_database', []));
        $error = '';

        if ($this->request->isPost()) {
            try {
                $this->verifyCsrfToken();
                $database = $this->inputValidator->database($this->request->post());
                $this->databaseInstaller->testConnection($database);
                Session::set('install_database', $database);

                return redirect('/install/administrator');
            } catch (InstallException $exception) {
                $error = $exception->getMessage();
                $values = $this->databaseFormValues($this->request->post());
            } catch (Throwable $exception) {
                Log::error('安装数据库配置异常：' . $exception->getMessage());
                $error = '数据库配置处理失败，请检查服务器日志后重试。';
            }
        }

        return View::fetch('database', [
            'pageTitle' => '数据库配置',
            'currentStep' => 3,
            'csrfToken' => $this->csrfToken(),
            'values' => $values,
            'error' => $error,
        ]);
    }

    /**
     * @return Response 数据库 AJAX 检测结果
     */
    public function testDatabase(): Response
    {
        if ($this->installState->isInstalled()) {
            return json(['success' => false, 'message' => '系统已经安装。'], 403);
        }
        if (!$this->environmentService->canInstall()) {
            return json(['success' => false, 'message' => '环境检测存在未通过的阻断项。'], 422);
        }

        try {
            $this->verifyCsrfToken();
            $database = $this->inputValidator->database($this->request->post());
            $message = $this->databaseInstaller->testConnection($database);

            return json(['success' => true, 'message' => $message]);
        } catch (InstallException $exception) {
            $statusCode = $exception->getMessage() === '页面已过期，请刷新后重试。' ? 419 : 422;

            return json(['success' => false, 'message' => $exception->getMessage()], $statusCode);
        } catch (Throwable $exception) {
            Log::error('数据库连接检测异常：' . $exception->getMessage());

            return json(['success' => false, 'message' => '连接检测失败，请检查服务器日志。'], 500);
        }
    }

    /**
     * @return Response|string 管理员配置页或重定向
     */
    public function administrator()
    {
        if ($response = $this->denyInstalledAccess()) {
            return $response;
        }
        if (!$this->environmentService->canInstall()) {
            return redirect('/install/environment');
        }

        $database = Session::get('install_database');
        if (!is_array($database) || $database === []) {
            return redirect('/install/database');
        }

        $error = '';
        $username = '';
        if ($this->request->isPost()) {
            try {
                $this->verifyCsrfToken();
                $administrator = $this->inputValidator->administrator($this->request->post());
                $username = $administrator['username'];
                $this->installationService->install($database, $administrator);

                Session::delete('install_database');
                Session::set('install_finished_at', time());

                return redirect('/install/complete');
            } catch (InstallException $exception) {
                $error = $exception->getMessage();
                $username = trim((string) $this->request->post('admin_username', ''));
            } catch (Throwable $exception) {
                Log::error('执行安装异常：' . $exception->getMessage());
                $error = '安装执行失败，请检查服务器日志后重试。';
            }
        }

        return View::fetch('administrator', [
            'pageTitle' => '管理员账号',
            'currentStep' => 4,
            'csrfToken' => $this->csrfToken(),
            'error' => $error,
            'username' => $username,
            'databaseName' => (string) ($database['database'] ?? ''),
        ]);
    }

    /**
     * @return Response|string 安装完成页或已安装提示
     */
    public function complete()
    {
        if (!$this->installState->isInstalled()) {
            return redirect('/install');
        }

        $finishedAt = (int) Session::get('install_finished_at', 0);
        $recentlyFinished = $finishedAt > 0 && time() - $finishedAt <= self::COMPLETION_ACCESS_SECONDS;
        if (!$recentlyFinished) {
            return $this->installedResponse();
        }

        return View::fetch('complete', [
            'pageTitle' => '安装完成',
            'currentStep' => 5,
        ]);
    }

    /**
     * @return Response|null 已安装时返回 403，否则继续流程
     */
    private function denyInstalledAccess(): ?Response
    {
        return $this->installState->isInstalled() ? $this->installedResponse() : null;
    }

    /**
     * @return Response 已安装提示响应
     */
    private function installedResponse(): Response
    {
        $content = View::fetch('installed', [
            'pageTitle' => '系统已安装',
            'currentStep' => 0,
        ]);

        return response($content, 403);
    }

    /**
     * @return string 当前会话 CSRF 令牌
     */
    private function csrfToken(): string
    {
        $token = (string) Session::get('install_csrf_token', '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set('install_csrf_token', $token);
        }

        return $token;
    }

    /**
     * @return void
     * @throws InstallException 令牌缺失或不匹配时抛出
     */
    private function verifyCsrfToken(): void
    {
        $expected = (string) Session::get('install_csrf_token', '');
        $actual = (string) $this->request->post('_token', '');
        if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
            throw new InstallException('页面已过期，请刷新后重试。');
        }
    }

    /**
     * @param array<string, mixed> $source 原始数据库表单
     * @return array<string, mixed> 不回填密码的安全表单值
     */
    private function databaseFormValues(array $source): array
    {
        return [
            'hostname' => (string) ($source['hostname'] ?? '127.0.0.1'),
            'hostport' => (string) ($source['hostport'] ?? '3306'),
            'database' => (string) ($source['database'] ?? 'nova_download'),
            'username' => (string) ($source['username'] ?? 'root'),
            'password' => '',
            'charset' => (string) ($source['charset'] ?? 'utf8mb4'),
            'prefix' => (string) ($source['prefix'] ?? 'nova_'),
            'create_database' => filter_var(
                $source['create_database'] ?? true,
                FILTER_VALIDATE_BOOLEAN
            ),
        ];
    }
}
