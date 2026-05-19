{{--
    Model picker combobox — filters by substring in both model ID and display name.
    Requires window.HP_MODELS and hpModelPicker() to be defined (settings-page.blade.php).

    @param string $wireKey  e.g. 'agentModels.writer'
--}}
<div
    x-data="hpModelPicker(@js($wireKey))"
    @click.outside="open = false"
    @keydown.escape="open = false"
    class="relative"
>
    <div class="mb-1.5 flex gap-2">
        <select
            x-model="selectedProvider"
            @change="onProviderChange()"
            class="hp-select flex-1 text-xs"
        >
            <template x-for="p in providers" :key="p.slug">
                <option :value="p.slug" x-text="p.name"></option>
            </template>
        </select>
    </div>

    <input
        type="text"
        x-model="query"
        @input.debounce.120ms="filter()"
        @focus="filter()"
        @keydown.enter.prevent="selectFirst()"
        @blur="commit()"
        class="hp-input w-full"
        placeholder="Busca por nombre o pega el model id"
        autocomplete="off"
        spellcheck="false"
    />

    <div
        x-show="open && filtered.length > 0"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-y-95"
        x-transition:enter-end="opacity-100 scale-y-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-y-100"
        x-transition:leave-end="opacity-0 scale-y-95"
        class="absolute left-0 right-0 z-50 mt-1 max-h-60 origin-top overflow-y-auto rounded-xl border border-stone-200 bg-white shadow-xl dark:border-white/10 dark:bg-slate-900"
        style="display: none"
    >
        <template x-for="m in filtered" :key="(m.provider_slug || '') + ':' + m.id">
            <button
                type="button"
                @mousedown.prevent="select(m)"
                class="flex w-full items-start gap-2 px-3 py-2 text-left transition-colors hover:bg-stone-100 dark:hover:bg-white/5"
            >
                <div class="min-w-0 flex-1">
                    <span
                        class="block truncate text-sm font-medium text-stone-900 dark:text-white"
                        x-text="m.id"
                    ></span>
                    <span
                        class="block truncate text-xs text-stone-500 dark:text-slate-400"
                        x-text="(m.name || '') + (m.price_label ? ' · ' + m.price_label : '')"
                    ></span>
                </div>
                <span
                    x-show="m.source"
                    x-text="m.source === 'direct' ? (m.provider_name || 'directo') : 'OpenRouter'"
                    :class="m.source === 'direct'
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300'
                        : 'bg-stone-100 text-stone-500 dark:bg-white/10 dark:text-slate-400'"
                    class="mt-0.5 shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                ></span>
            </button>
        </template>
    </div>
</div>
