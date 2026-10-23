@include('errors.partials.error-layout', [
    'status' => 500,
    'tone' => 'red',
    'icon' => '⚠',
    'title' => 'Something went wrong on our end',
    'description' => 'An unexpected error occurred while processing your request. This has been logged automatically — try again in a moment.',
    'actions' => [
        ['label' => 'Try Again', 'reload' => true, 'style' => 'primary'],
        ['label' => '← Back to Home', 'href' => url('/'), 'style' => 'secondary'],
    ],
    'showMeta' => true,
])
