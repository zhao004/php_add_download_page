<?php

declare(strict_types=1);

use think\facade\Route;

Route::rule('login$', 'Auth/login', 'GET|POST');
Route::get('captcha$', 'Auth/captcha');
Route::post('logout$', 'Auth/logout');

Route::get('/$', 'Dashboard/index');
Route::rule('site$', 'Site/index', 'GET|POST');
Route::rule('download$', 'Site/download', 'GET|POST');
Route::post('download/upload$', 'Site/uploadApk');
Route::post('media/image$', 'Media/image');
Route::rule('account/password$', 'Site/password', 'GET|POST');
Route::get('logs/visit$', 'Logs/visit');
Route::get('logs/download$', 'Logs/download');
Route::get('logs/:type/detail/:id$', 'Logs/detail');
Route::post('logs/:type/batch-delete$', 'Logs/batchDelete');

Route::get('content/:resource$', 'Content/index');
Route::rule('content/:resource/create$', 'Content/create', 'GET|POST');
Route::rule('content/:resource/edit/:id$', 'Content/edit', 'GET|POST');
Route::post('content/:resource/delete/:id$', 'Content/delete');
Route::post('content/:resource/batch-delete$', 'Content/batchDelete');
Route::post('content/:resource/toggle/:id$', 'Content/toggle');
