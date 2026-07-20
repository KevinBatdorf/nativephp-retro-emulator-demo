<native:column class="flex-1 bg-black">
    <native:row class="px-4 py-2 items-center justify-between bg-gray-950">
        <native:button label="← Back" @navigate.back.none />
        <native:text class="text-white text-lg">Global Settings</native:text>
        <native:button label="Reset" color="#f87171" @press="resetAll" />
    </native:row>

    <native:lazy-grid columns="3" gap="28" class="px-6 py-5">
        <native:column native:key="luminance" class="gap-1">
            <native:text class="text-white text-sm">Luminance — {{ $luminance }}</native:text>
            <native:slider class="w-full" size="sm" min="0" max="100" step="1" :value="$luminance" @change="setLuminance" />
        </native:column>
        <native:column native:key="saturation" class="gap-1">
            <native:text class="text-white text-sm">Saturation — {{ $saturation }}</native:text>
            <native:slider class="w-full" size="sm" min="0" max="100" step="1" :value="$saturation" @change="setSaturation" />
        </native:column>
        <native:column native:key="gamma" class="gap-1">
            <native:text class="text-white text-sm">Gamma — {{ $gamma }}</native:text>
            <native:slider class="w-full" size="sm" min="50" max="200" step="1" :value="$gamma" @change="setGamma" />
        </native:column>

        <native:column native:key="volume" class="gap-1">
            <native:text class="text-white text-sm">Volume — {{ $volume }}</native:text>
            <native:slider class="w-full" size="sm" min="0" max="100" step="1" :value="$volume" @change="setVolume" />
        </native:column>
        <native:column native:key="balance" class="gap-1">
            <native:text class="text-white text-sm">Balance — {{ $balance }}</native:text>
            <native:slider class="w-full" size="sm" min="-100" max="100" step="1" :value="$balance" @change="setBalance" />
        </native:column>
        <native:column native:key="speed" class="gap-1">
            <native:text class="text-white text-sm">Speed — {{ number_format($speed, 2) }}×</native:text>
            <native:slider class="w-full" size="sm" min="0.25" max="4" step="0.25" :value="$speed" @change="setSpeed" />
        </native:column>

        <native:row native:key="t-overscan" class="gap-3 items-center">
            <native:text class="text-white text-sm">Overscan</native:text>
            <native:toggle :value="$overscan" @change="setOverscan" />
        </native:row>
        <native:row native:key="t-crt" class="gap-3 items-center">
            <native:text class="text-white text-sm">Apply CRT filter</native:text>
            <native:toggle :value="$crt" @change="setCrt" />
        </native:row>
        <native:row native:key="t-rumble" class="gap-3 items-center">
            <native:text class="text-white text-sm">Rumble</native:text>
            <native:toggle :value="$rumble" @change="setRumble" />
        </native:row>
    </native:lazy-grid>
</native:column>
