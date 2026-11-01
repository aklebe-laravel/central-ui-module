<?php
use Illuminate\Support\Facades\Route;
use Modules\CentralUi\app\Http\Controllers\StatusFailController;
use Modules\WebsiteBase\app\Services\WebsiteService;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::prefix('central-ui')->group(function () {
    Route::get('/', 'CentralUiController@index');
});

/** @var WebsiteService $websiteService */
$websiteService = app(WebsiteService::class);
$defaultMiddleware = $websiteService->getDefaultMiddleware();

/**
 * $defaultMiddleware depends on config setting.
 * Store settings can be public or wanted auth and member are present.
 */
Route::group(['middleware' => $defaultMiddleware], function () {

    Route::get('/desktop', function () {
        return view('content-pages.home');
    })->name('central-ui.desktop');
});

// Target of GlobalStateRedirect, so it must not use $defaultMiddleware (auth etc.)
Route::get('/system-unavailable', [StatusFailController::class, 'index'])->name('status.fail');
