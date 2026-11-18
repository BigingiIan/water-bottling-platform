@props(['size' => 'size-8'])

<span {{ $attributes->class(['flex shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-brand-from to-brand-to text-on-brand', $size]) }} aria-hidden="true">
    <x-brand-droplet class="size-[55%]" />
</span>
