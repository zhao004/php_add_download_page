<?php

declare(strict_types=1);

use think\facade\Route;

Route::get('/$', 'Index/index');
Route::get('environment$', 'Index/environment');
Route::rule('database$', 'Index/database', 'GET|POST');
Route::post('database/test$', 'Index/testDatabase');
Route::rule('administrator$', 'Index/administrator', 'GET|POST');
Route::get('complete$', 'Index/complete');
