<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImageGenerationController;
use App\Http\Controllers\LabAssetFetchController;
use App\Http\Controllers\LabCreationsController;
use App\Http\Controllers\LabMediaUploadController;
use App\Http\Controllers\VideoGenerationController;
use App\Http\Controllers\MusicGenerationController;
use App\Http\Controllers\TrendsController;
use App\Http\Controllers\VoiceGenerationController;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);

    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

// Public browsing pages. Authentication is required only for user-owned data
// and actions that create or mutate content.
Route::get('/', [DashboardController::class, 'index'])->name('home');
Route::get('/lab', [DashboardController::class, 'lab'])->name('lab');
Route::get('/trends', [DashboardController::class, 'trends'])->name('trends');
Route::get('/innovation', [DashboardController::class, 'innovation'])->name('innovation');
Route::get('/post/{id}', [DashboardController::class, 'showPost'])->name('post.show');
Route::get('/tools', [DashboardController::class, 'tools'])->name('tools');
Route::get('/pricing', [DashboardController::class, 'pricing'])->name('pricing');
Route::redirect('/marketplace', '/trends');

// SofizPay return URL is public: SATIM redirects the browser back here and the
// session may be lost. Payment is verified server-to-server, not by this request.
Route::get('/billing/sofizpay/return', [BillingController::class, 'sofizpayReturn'])
    ->middleware('throttle:30,1')
    ->name('billing.sofizpay.return');

// Old studio URLs remain publicly browseable.
Route::redirect('/text-to-video', '/lab?type=text-to-video');
Route::redirect('/image-to-video', '/lab?type=text-to-voice');
Route::redirect('/text-to-image', '/lab?type=text-to-image');
Route::redirect('/text-to-sound', '/lab?type=text-to-music');
Route::redirect('/text-to-music', '/lab?type=text-to-music');
Route::redirect('/text-to-voice', '/lab?type=text-to-voice');
Route::redirect('/face-swap', '/lab?type=text-to-video');
Route::redirect('/lip-sync', '/lab?type=text-to-video');
Route::redirect('/image-to-image', '/lab?type=text-to-image');
Route::redirect('/image-upscaler', '/lab?type=text-to-image');
Route::redirect('/background-remover', '/lab?type=text-to-image');
Route::redirect('/text-to-video/lab', '/lab?type=text-to-video');
Route::redirect('/image-to-video/lab', '/lab?type=text-to-voice');
Route::redirect('/text-to-image/lab', '/lab?type=text-to-image');
Route::redirect('/text-to-sound/lab', '/lab?type=text-to-music');
Route::redirect('/text-to-music/lab', '/lab?type=text-to-music');
Route::redirect('/text-to-voice/lab', '/lab?type=text-to-voice');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/history', [DashboardController::class, 'history'])->name('history');
    Route::get('/billing/history', [BillingController::class, 'history'])->name('billing.history');
    Route::get('/settings', [DashboardController::class, 'settings'])->name('settings');
    // Start a SofizPay (DZD) checkout for a token pack.
    Route::post('/billing/sofizpay/create', [BillingController::class, 'createSofizPay'])
        ->middleware('throttle:20,1')
        ->name('billing.sofizpay.create');

    Route::post('/trends/use', [TrendsController::class, 'useTemplate'])
        ->middleware('throttle:60,1')
        ->name('trends.use');
    Route::post('/trends/visibility', [TrendsController::class, 'setVisibility'])
        ->middleware('throttle:30,1')
        ->name('trends.visibility');

    Route::get('/lab/creations', [LabCreationsController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('lab.creations.index');

    Route::get('/lab/asset-fetch', LabAssetFetchController::class)
        ->middleware('throttle:60,1')
        ->name('lab.asset.fetch');

    // Text-to-image generation (server-side fal queue proxy)
    Route::post('/lab/image/generate', [ImageGenerationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('lab.image.generate');
    Route::get('/lab/image/creations/{creation}/status', [ImageGenerationController::class, 'status'])
        ->middleware('throttle:180,1')
        ->name('lab.image.status');

    // Text-to-video generation (server-side fal queue proxy)
    Route::post('/lab/media/upload', [LabMediaUploadController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('lab.media.upload');
    Route::post('/lab/video/generate', [VideoGenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('lab.video.generate');
    Route::get('/lab/video/creations/{creation}/status', [VideoGenerationController::class, 'status'])
        ->middleware('throttle:180,1')
        ->name('lab.video.status');

    // Text-to-voice generation (server-side fal queue proxy)
    Route::post('/lab/voice/generate', [VoiceGenerationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('lab.voice.generate');
    Route::get('/lab/voice/creations/{creation}/status', [VoiceGenerationController::class, 'status'])
        ->middleware('throttle:180,1')
        ->name('lab.voice.status');

    // Text-to-music generation (server-side fal queue proxy)
    Route::post('/lab/music/generate', [MusicGenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('lab.music.generate');
    Route::get('/lab/music/creations/{creation}/status', [MusicGenerationController::class, 'status'])
        ->middleware('throttle:120,1')
        ->name('lab.music.status');

});

$toolRoutes = [
    'video-upscaler', 'video-enhancer', 'lip-sync', 'face-swap-video',
    'video-background-remover', 'remove-subtitles-from-video', 'ai-video-extender',
    'ai-video-editor', 'video-to-video', 'animate-a-picture', 'ai-sound-effect-generator',
    'denoise-video', 'ai-dance-generator', 'video-to-anime-ai', 'ai-video-filters',
    'anime-video-enhancer', 'motion-control',
];

foreach ($toolRoutes as $slug) {
    Route::get("/tools/{$slug}", [DashboardController::class, 'showTool'])->name("tools.{$slug}");
}
