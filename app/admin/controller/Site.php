<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\exception\AdminException;
use app\common\service\SiteConfigService;
use app\common\service\ApkUploadService;
use app\common\service\ThemeColorService;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * 站点、下载与账号配置。
 */
class Site extends AdminController
{
    /**
     * @return Response|string 站点配置页
     */
    public function index()
    {
        /** @var SiteConfigService $configService */
        $configService = $this->app->make(SiteConfigService::class);
        $error = '';
        $values = $configService->get();

        if ($this->request->isPost()) {
            try {
                $this->assertCsrf();
                $configService->saveSite($this->request->post());
                if ($this->wantsJson()) {
                    $saved = $configService->get();
                    $siteName = trim((string) ($saved['site_name'] ?? ''));
                    $faviconUrl = (string) ($saved['favicon_url'] ?? SiteConfigService::DEFAULT_FAVICON_PATH);
                    /** @var ThemeColorService $themeColorService */
                    $themeColorService = $this->app->make(ThemeColorService::class);
                    $themeColor = $themeColorService->normalize((string) ($saved['theme_color'] ?? ''));

                    return $this->jsonSuccess('站点配置已保存。', [
                        'site_name' => $siteName !== '' ? $siteName : '管理后台',
                        'site_name_initial' => $this->resolveSiteNameInitial(
                            $siteName !== '' ? $siteName : '管理后台'
                        ),
                        'theme_color' => $themeColor,
                        'theme_css' => $themeColorService->cssOverrides($themeColor),
                        'favicon_url' => $faviconUrl,
                        'csrf_token' => $this->csrf->token('admin'),
                    ]);
                }

                return $this->redirectWithFlash('/admin/site', '站点配置已保存。');
            } catch (AdminException $exception) {
                if ($this->wantsJson()) {
                    return $this->jsonError($exception->getMessage());
                }
                $error = $exception->getMessage();
                $values = array_merge($values, $this->request->post());
            } catch (Throwable $exception) {
                Log::error('保存站点配置异常：' . $exception->getMessage());
                if ($this->wantsJson()) {
                    return $this->jsonError('站点配置保存失败，请检查服务器日志。', 500);
                }
                $error = '站点配置保存失败，请检查服务器日志。';
            }
        }

        /** @var ThemeColorService $themeColorService */
        $themeColorService = $this->app->make(ThemeColorService::class);
        $themeForm = $themeColorService->formData((string) ($values['theme_color'] ?? ''));

        return $this->render('site/index', [
            'pageTitle' => '站点配置',
            'pageDescription' => '管理前台品牌、版本、文案、主题配色与页脚信息',
            'activeNav' => 'site',
            'values' => array_merge($values, ['theme_color' => $themeForm['theme_color']]),
            'themePresets' => $themeForm['theme_presets'],
            'themePalette' => $themeForm['theme_palette'],
            'error' => $error,
        ]);
    }

    /**
     * @return Response|string 下载配置页
     */
    public function download()
    {
        /** @var SiteConfigService $configService */
        $configService = $this->app->make(SiteConfigService::class);
        $error = '';
        $values = $configService->downloadForm();

        if ($this->request->isPost()) {
            try {
                $this->assertCsrf();
                $configService->saveDownload($this->request->post());
                if ($this->wantsJson()) {
                    $saved = $configService->downloadForm();

                    return $this->jsonSuccess('下载配置已保存。', [
                        'download_mode' => (string) $saved['download_mode'],
                        'local_apk_path' => (string) ($saved['local_apk_path'] ?? ''),
                        'other_pwd' => (string) ($saved['other_pwd'] ?? ''),
                        'other_password_configured' => (bool) $saved['other_password_configured'],
                        'csrf_token' => $this->csrf->token('admin'),
                    ]);
                }

                return $this->redirectWithFlash('/admin/download', '下载配置已保存。');
            } catch (AdminException $exception) {
                if ($this->wantsJson()) {
                    return $this->jsonError($exception->getMessage());
                }
                $error = $exception->getMessage();
                $values = array_merge($values, [
                    'download_mode' => (string) $this->request->post('download_mode', 'local'),
                    'other_url' => (string) $this->request->post('other_url', ''),
                    'other_pwd' => (string) $this->request->post('other_pwd', ''),
                ]);
            } catch (Throwable $exception) {
                Log::error('保存下载配置异常：' . $exception->getMessage());
                if ($this->wantsJson()) {
                    return $this->jsonError('下载配置保存失败，请检查服务器日志。', 500);
                }
                $error = '下载配置保存失败，请检查服务器日志。';
            }
        }

        return $this->render('site/download', [
            'pageTitle' => '下载配置',
            'pageDescription' => '选择本地 APK 或网盘下载作为唯一下载来源',
            'activeNav' => 'download',
            'values' => $values,
            'error' => $error,
        ]);
    }

    /**
     * @return Response|string 修改密码页
     */
    public function password()
    {
        $error = '';
        if ($this->request->isPost()) {
            try {
                $this->assertCsrf();
                $admin = $this->auth->currentAdmin();
                $this->auth->changePassword(
                    (int) ($admin['id'] ?? 0),
                    (string) $this->request->post('current_password', ''),
                    (string) $this->request->post('new_password', ''),
                    (string) $this->request->post('new_password_confirmation', '')
                );
                $this->auth->logout();
                $this->flashService()->push('密码已修改，请重新登录。', 'success');

                return redirect('/admin/login');
            } catch (AdminException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                Log::error('修改管理员密码异常：' . $exception->getMessage());
                $error = '密码修改失败，请检查服务器日志。';
            }
        }

        return $this->render('account/password', [
            'pageTitle' => '修改密码',
            'pageDescription' => '更新当前管理员的登录凭据',
            'activeNav' => 'password',
            'error' => $error,
        ]);
    }

    /**
     * @return Response APK 上传结果（JSON 或跳转）
     */
    public function uploadApk(): Response
    {
        try {
            $this->assertCsrf();
            /** @var ApkUploadService $uploadService */
            $uploadService = $this->app->make(ApkUploadService::class);
            $path = $uploadService->replace($this->request->file('apk'));
            $message = 'APK 已上传，本地下载路径已更新。';
            if ($this->wantsJson()) {
                return $this->jsonSuccess($message, [
                    'local_apk_path' => $path,
                    'redirect' => '/admin/download',
                    'csrf_token' => $this->csrf->token('admin'),
                ]);
            }

            return $this->redirectWithFlash('/admin/download', $message);
        } catch (AdminException $exception) {
            if ($this->wantsJson()) {
                return $this->jsonError($exception->getMessage());
            }

            return $this->redirectWithFlash('/admin/download', $exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            Log::error('上传 APK 异常：' . $exception->getMessage());
            if ($this->wantsJson()) {
                return $this->jsonError('APK 上传失败，请检查服务器日志。', 500);
            }

            return $this->redirectWithFlash('/admin/download', 'APK 上传失败，请检查服务器日志。', 'danger');
        }
    }
}
