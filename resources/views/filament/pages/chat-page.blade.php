<x-filament-panels::page>
    @include('filament.pages.partials.chat-script')
    <script>
        window.filamentHistoriaChatConfig = @js($this->getChatConfig());
    </script>

    @include('filament.pages.partials.chat-shell')
</x-filament-panels::page>
