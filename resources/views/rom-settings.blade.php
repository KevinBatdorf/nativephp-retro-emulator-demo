<native:column class="flex-1 bg-gray-950">
    <native:row class="p-3 items-center justify-between bg-black">
        <native:button label="← Back" @navigate.back.none />
        <native:text class="text-white text-lg font-semibold">Settings</native:text>
        <native:button label="Reset" color="#f87171" @press="resetDefaults" />
    </native:row>

    <native:scroll-view class="flex-1">
        <native:column class="p-4 gap-6">

            {{-- Shader — the headline visual feature a dev reaches for first. --}}
            <native:column class="gap-2">
                <native:text class="text-white text-base font-semibold">Shader</native:text>
                <native:toggle label="CRT filter" :value="$crt" @change="setCrt" />
            </native:column>

            {{-- Picture — applies live on the running surface. --}}
            <native:column class="gap-3">
                <native:text class="text-white text-base font-semibold">Picture</native:text>
                <native:column class="gap-1">
                    <native:text class="text-gray-200 text-sm">Luminance — {{ $luminance }}</native:text>
                    <native:slider class="w-full" min="0" max="100" step="5" :value="$luminance" @change="setLuminance" />
                </native:column>
                <native:column class="gap-1">
                    <native:text class="text-gray-200 text-sm">Saturation — {{ $saturation }}</native:text>
                    <native:slider class="w-full" min="0" max="100" step="5" :value="$saturation" @change="setSaturation" />
                </native:column>
                <native:column class="gap-1">
                    <native:text class="text-gray-200 text-sm">Gamma — {{ $gamma }}</native:text>
                    <native:slider class="w-full" min="50" max="200" step="5" :value="$gamma" @change="setGamma" />
                </native:column>
                <native:toggle label="Show overscan borders" :value="$overscan" @change="setOverscan" />
            </native:column>

            {{-- Audio. --}}
            <native:column class="gap-1">
                <native:text class="text-white text-base font-semibold">Audio</native:text>
                <native:text class="text-gray-200 text-sm">Volume — {{ $volume }}</native:text>
                <native:slider class="w-full" min="0" max="100" step="5" :value="$volume" @change="setVolume" />
            </native:column>

            {{-- Per-system options that visibly change the image. --}}
            @if ($toggleLabels !== [])
                <native:column class="gap-2">
                    <native:text class="text-white text-base font-semibold">{{ strtoupper($id) }} options</native:text>
                    @foreach ($toggleLabels as $field => $label)
                        <native:toggle
                            native:key="{{ $field }}"
                            label="{{ $label }}"
                            :value="$toggles[$field] ?? false"
                            @change="setToggle('{{ $field }}')" />
                    @endforeach
                </native:column>
            @endif

            @if ($rebootNeeded)
                <native:button label="Apply &amp; reboot" @press="applyAndReboot" />
                <native:text class="text-gray-300 text-xs">Shader + system options take effect on reboot.</native:text>
            @endif

            {{-- Under the hood: the exact config handed to Emulator::loadSystem.
                 The one dev-facing view — everything above just edits it. --}}
            <native:column class="gap-1 mt-2">
                <native:text class="text-gray-400 text-xs">CONFIG SENT TO loadSystem()</native:text>
                <native:column class="p-3 rounded-lg bg-black">
                    <native:text class="text-green-300 text-xs">{{ $configJson }}</native:text>
                </native:column>
            </native:column>
        </native:column>
    </native:scroll-view>
</native:column>
