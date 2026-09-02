<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default site metadata (rendered in HTML <head> before JavaScript)
    |--------------------------------------------------------------------------
    |
    | Social bots (Facebook, X, LinkedIn, Slack, ChatGPT link previews) read these
    | tags from the initial HTML response — they do not wait for React/Inertia.
    |
    */

    'site_name' => env('SEO_SITE_NAME', env('APP_NAME', 'RIMBOAI')),

    'title' => env('SEO_TITLE', 'RIMBOAI — AI Video, Image, Voice & Music Studio'),

    'description' => env(
        'SEO_DESCRIPTION',
        'Create cinematic AI videos, images, voiceovers, and music in one studio. Text-to-video, upscaling, lip sync, trends templates, and 50+ models.',
    ),

    /** Path or absolute URL. Use a 1200×630 image for best social previews. */
    'image' => env('SEO_IMAGE', '/storage/ai_icons/music_home.jpg'),

    'twitter_handle' => env('SEO_TWITTER_HANDLE', ''),

    'locale' => env('SEO_LOCALE', 'en_US'),

    /** Public routes included in sitemap.xml */
    'sitemap' => [
        '/',
        '/lab',
        '/lab?type=text-to-video',
        '/lab?type=text-to-image',
        '/lab?type=text-to-voice',
        '/lab?type=text-to-music',
        '/trends',
        '/innovation',
        '/tools',
        '/pricing',
    ],

];
