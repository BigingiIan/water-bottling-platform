@props(['size' => 'size-8'])

<span {{ $attributes->class(['flex shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-blue-500 to-teal-500 text-white', $size]) }} aria-hidden="true">
    <x-brand-droplet class="size-[55%]" />
</span>
