<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\WordPressPostController;
use App\Http\Controllers\API\UsersController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('wp-posts')->group(function () {
    Route::get('/{id}', [WordPressPostController::class, 'user_course_info']);
    Route::get('/', [WordPressPostController::class, 'course_catalog']);
    Route::post('/', [WordPressPostController::class, 'store']);
    Route::put('/{id}', [WordPressPostController::class, 'update']);
    Route::delete('/{id}', [WordPressPostController::class, 'destroy']);
});

Route::prefix('institute-user')->group(function () {
    Route::get('/', [UsersController::class, 'users']);
    Route::get('/{id}', [UsersController::class, 'user_by_id']);
});
