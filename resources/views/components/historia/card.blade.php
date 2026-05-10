@props([
    'title' => null,
    'description' => null,
    'compact' => false,
])

<section {{ $attributes->class(['hp-card', 'hp-card-compact' => $compact]) }}>
    @if (filled($title) || filled($description))
        <header class="mb-4">
            @if (filled($title))
                <h2 class="text-base font-semibold text-stone-950 dark:text-white">{{ $title }}</h2>
            @endif

            @if (filled($description))
                <p class="mt-1 text-sm text-stone-600 dark:text-slate-300">{{ $description }}</p>
            @endif
        </header>
    @endif

    {{ $slot }}
</section>
