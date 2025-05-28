<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\WordPressPostController;
use App\Http\Controllers\API\UsersController;
use App\Http\Controllers\API\ExternalDataController;

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

Route::prefix('wp-posts')->group(callback: function () {

    Route::get('/temporary_external_data', [ExternalDataController::class, 'temporary_external_data']);

    Route::get('/top_completed_courses', [WordPressPostController::class, 'top_completed_courses']);
    Route::get('/sign_up_user_info/{id}', [WordPressPostController::class, 'sign_up_user_info']);
    Route::get('/course_description', [WordPressPostController::class, 'course_description']);
    Route::get('/enrolled_users', [WordPressPostController::class, 'enrolled_users']);
    Route::get('/course_user_info/{id}', [WordPressPostController::class, 'course_user_info']);
    Route::get('/user_progress_per_courses/{id}', [WordPressPostController::class, 'user_progress_per_courses']);
    Route::get('/user_course_info/{id}', [WordPressPostController::class, 'user_course_info']);
    Route::get('/user_reg_info/{id}', [WordPressPostController::class, 'user_reg_info']);
    Route::get('/', [WordPressPostController::class, 'course_catalog']);
    Route::post('/', [WordPressPostController::class, 'store']);
    Route::put('/{id}', [WordPressPostController::class, 'update']);
    Route::delete('/{id}', [WordPressPostController::class, 'destroy']);
});

Route::prefix('institute-user')->group(function () {
    Route::get('/', [UsersController::class, 'users']);
    Route::get('/{id}', [UsersController::class, 'user_by_id']);
});
