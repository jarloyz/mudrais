@props([
    'eyebrow' => '',
    'title' => '',
    'description' => '',
    'tone' => 'amber',
    'aside' => null,
])

@php
    $toneClasses = match ($tone) {
        'sky' => 'hp-hero-sky',
        'emerald' => 'hp-hero-emerald',
        'fuchsia' => 'hp-hero-fuchsia',
        default => 'hp-hero-amber',
    };
@endphp

<div {{ $attributes->class(['space-y-6']) }}>
    <section class="hp-hero {{ $toneClasses }}">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px] xl:items-end">
            <div>
                @if (filled($eyebrow))
                    <span class="hp-kicker">{{ $eyebrow }}</span>
                @endif

                <h1 class="mt-4 text-3xl font-semibold tracking-tight text-stone-950 dark:text-white">
                    {{ $title }}
                </h1>

                @if (filled($description))
                    <p class="mt-3 max-w-3xl text-sm leading-7 text-stone-600 dark:text-slate-300">
                        {{ $description }}
                    </p>
                @endif
            </div>

            @if (filled($aside))
                <div class="grid gap-3">
                    {{ $aside }}
                </div>
            @endif
        </div>
    </section>

    {{ $slot }}
</div>
