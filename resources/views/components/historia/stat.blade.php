@props([
    'label' => '',
    'value' => '',
])

<div {{ $attributes->class(['hp-stat']) }}>
    <div class="text-xs uppercase tracking-[0.24em] text-stone-500 dark:text-slate-400">{{ $label }}</div>
    <div class="mt-2 text-lg font-semibold text-stone-950 dark:text-white">{{ $value }}</div>
</div>
