<p>Hello{{ $recipientName ? ' '.$recipientName : '' }},</p>

@foreach ($paragraphs as $paragraph)
<p>{!! nl2br(e($paragraph)) !!}</p>
@endforeach

@if ($actionText && $actionUrl)
<p><a href="{{ $actionUrl }}">{{ $actionText }}</a></p>
@endif

<p>{{ $organisationName }}</p>
