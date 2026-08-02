<?php
// 全局中间件定义文件
return [
    // 未安装时统一进入安装向导，避免业务控制器提前访问数据库。
    \app\common\middleware\InstallGuard::class,
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化
    \think\middleware\SessionInit::class,
];
