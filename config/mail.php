<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Markdown Mail Theme
    |--------------------------------------------------------------------------
    |
    | Every notification this application sends is a Markdown mailable, so the
    | theme below is what a subscriber actually sees. `spectrum` carries the
    | public site's palette (navy #10172D, brass #AA8940) and replaces
    | Laravel's stock indigo-and-grey default.
    |
    */

    'markdown' => [

        'theme' => env('MAIL_THEME', 'spectrum'),

        'paths' => [
            resource_path('views/vendor/mail'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Brand
    |--------------------------------------------------------------------------
    |
    | The masthead and footer of every email. `url` is where the wordmark
    | links. It is left null unless set: APP_URL is this API, which serves no
    | page a reader can open, so the views fall back to the subscriber app
    | (config/frontend.php) rather than to it. Reading that default here would
    | mean copying it, since one config file cannot read another as it loads.
    |
    */

    'brand' => [

        'tagline' => env('MAIL_BRAND_TAGLINE', 'The Epitome of Human Knowledge'),

        /*
         * The logo, relative to public/ (or an absolute path). Used by the
         * email masthead and embedded into the invoice PDF. Blank it, or
         * remove the file, and both fall back to the text wordmark.
         */
        'logo_path' => env('MAIL_BRAND_LOGO_PATH', 'images/spectrum-logo.png'),

        /*
         * Where email should fetch that logo from. Defaults to APP_URL plus
         * the path above. Set it explicitly to serve from a CDN or from the
         * frontend's own domain — worth doing, since some mail clients treat
         * an image on an unfamiliar host with more suspicion than one on the
         * domain the mail claims to come from.
         */
        'logo_url' => env('MAIL_BRAND_LOGO_URL'),

        // Display width in the email masthead, in CSS pixels. The file itself
        // is wider, so it stays sharp on high-density screens.
        'logo_width' => (int) env('MAIL_BRAND_LOGO_WIDTH', 210),

        'url' => env('MAIL_BRAND_URL'),

        'support_email' => env('MAIL_SUPPORT_ADDRESS'),

    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
