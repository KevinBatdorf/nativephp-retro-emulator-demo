<native:column class="flex-1 bg-black">
    <native:row class="p-3 items-center justify-between bg-gray-950">
        <native:button label="← Back" @navigate.back.none />
        <native:text class="text-white text-xl">ROM Settings</native:text>
        <native:button label="Reset" color="#f87171" @press="resetDefaults" />
    </native:row>

    <native:scroll-view class="flex-1">
        <native:column class="p-4 gap-5">
            <native:text class="text-gray-400 text-sm">{{ strtoupper($id) }} — per-system</native:text>

            @if ($regionOptions !== [])
                <native:column class="gap-1">
                    <native:text class="text-white">Region</native:text>
                    <native:select
                        :options="$regionOptions"
                        :value="$region === '' ? '(auto)' : $region"
                        @change="setRegion" />
                </native:column>
            @else
                <native:text class="text-gray-500 text-sm">This system is region-free.</native:text>
            @endif

            <native:column class="gap-1">
                <native:text class="text-white">Controller / peripheral (port 1)</native:text>
                <native:select :options="$deviceOptions" :value="$device" @change="setDevice" />
                <native:text class="text-gray-500 text-xs">Mouse / Super Scope / Justifier / Multitap are only meaningful with a matching game.</native:text>
            </native:column>

            @if ($toggleLabels !== [])
                <native:divider />
                <native:text class="text-gray-400 text-sm">SYSTEM OPTIONS</native:text>
                @foreach ($toggleLabels as $field => $label)
                    <native:toggle
                        native:key="{{ $field }}"
                        label="{{ $label }}"
                        :value="$toggles[$field] ?? false"
                        @change="setToggle('{{ $field }}')" />
                @endforeach
            @endif

            <native:divider />
            <native:column class="gap-1">
                <native:text class="text-white">CRT filter override</native:text>
                <native:select :options="['inherit', 'on', 'off']" :value="$crt" @change="setCrt" />
                <native:text class="text-gray-500 text-xs">Global CRT toggle unless overridden here.</native:text>
            </native:column>

            <native:button label="Apply &amp; reboot ROM" @press="applyAndReboot" />
            <native:text class="text-gray-500 text-xs">Region and system options take effect on reboot.</native:text>
        </native:column>
    </native:scroll-view>
</native:column>
