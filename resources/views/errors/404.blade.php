@include('errors.partials.error-layout', [
    'status' => 404,
    'tone' => 'blue',
    'icon' => '🧭',
    'title' => "This page doesn't exist",
    'description' => "The page you're looking for may have been moved, renamed, or never existed. Double-check the link, or head back to a page you know.",
    'actions' => [
        ['label' => '← Back to Dashboard', 'href' => route('dashboard'), 'style' => 'primary'],
        ['label' => 'Go Back', 'history' => true, 'style' => 'secondary'],
    ],
    'pattern' => true,
])
