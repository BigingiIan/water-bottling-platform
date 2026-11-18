@php
    $errorParts = explode(':', $exception->getMessage(), 2);
    $variant = in_array($errorParts[0], ['no_access', 'read_only', 'view_as_read_only'], true) ? $errorParts[0] : 'no_access';
    $moduleName = $errorParts[1] ?? null;
    $moduleSlug = $moduleName === null ? null : \App\Models\Module::query()->where('name', trim($moduleName))->value('slug');
    $homeUrl = url('/');
    $actions = [
        ['label' => 'Back to Home', 'icon' => 'arrow-left', 'href' => $homeUrl, 'style' => 'primary'],
    ];

    if ($variant === 'view_as_read_only') {
        $moduleSlug = null;
        $actions = [
            ['label' => 'Return to my account', 'icon' => 'arrow-left', 'form' => route('impersonation.destroy'), 'fields' => [], 'style' => 'primary'],
        ];
    }

    if ($moduleSlug !== null && auth()->check()) {
        $actions[] = [
            'label' => 'Request Access',
            'icon' => 'paper-airplane',
            'form' => route('access-requests.store'),
            'fields' => ['module' => $moduleSlug, 'page' => '/'.ltrim(request()->path(), '/')],
            'style' => 'secondary',
        ];
    }

    if ($variant === 'view_as_read_only') {
        $title = 'View As is read-only';
        $icon = 'eye';
        $description = 'Changes are disabled while you are viewing the app as another user. Return to your own account to make changes.';
        $chip = 'View As';
    } elseif ($variant === 'read_only') {
        $title = 'This action needs full access';
        $icon = 'pencil-square';
        $description = "You can view this module, but your role doesn't allow making changes here. Editing is restricted to roles with full access.";
        $chip = ($moduleName ?? 'Module').' · your access: View only';
    } else {
        $title = "You don't have access to this";
        $icon = 'lock-closed';
        $description = 'Your current role doesn\'t include permission for this area. If you believe this is a mistake, reach out to your system administrator to review your access.';
        $chip = $moduleName ? $moduleName.' module' : null;
    }
@endphp

@include('errors.partials.error-layout', [
    'status' => 403,
    'tone' => 'amber',
    'icon' => $icon,
    'title' => $title,
    'description' => $description,
    'chip' => $chip,
    'actions' => $actions,
    'showMeta' => true,
])
