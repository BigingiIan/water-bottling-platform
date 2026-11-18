Hello{{ $recipientName ? ' '.$recipientName : '' }},

@foreach ($paragraphs as $paragraph)
{{ $paragraph }}

@endforeach
@if ($actionText && $actionUrl)
{{ $actionText }}: {{ $actionUrl }}

@endif
{{ $organisationName }}
