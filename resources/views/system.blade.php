<native:column class="flex-1 bg-black">
    <native:row class="p-3 items-center justify-between bg-gray-950">
        <native:button label="← Back" @navigate.back.none />
        <native:text class="text-white text-xl">{{ $name }}</native:text>
        <native:text class="text-gray-500 text-sm">{{ $id }}</native:text>
    </native:row>

    <native:scroll-view class="flex-1">
        <native:column class="p-4 gap-4">
            <native:column class="gap-1">
                <native:text class="text-gray-400 text-sm">ROM FOLDER</native:text>
                <native:text class="text-gray-500 text-xs">No folder picker exists in NativePHP Mobile — type a device path and rescan.</native:text>
                <native:outlined-text-input
                    label="Folder path"
                    :value="$folder"
                    @submit="setFolder" />
                <native:row class="gap-2 items-center">
                    <native:button label="Rescan" @press="rescan" />
                    <native:text class="text-gray-500 text-xs">Extensions: {{ implode(', ', $extensions) ?: '—' }}</native:text>
                </native:row>
            </native:column>

            @if ($biosRequired)
                <native:divider />
                <native:column class="gap-1">
                    <native:text class="text-gray-400 text-sm">BIOS (required)</native:text>
                    <native:outlined-text-input
                        label="BIOS path"
                        :value="$bios ?? ''"
                        @submit="setBios" />
                    <native:text class="{{ $bios ? 'text-green-400' : 'text-yellow-400' }} text-xs">
                        {{ $bios ? '✓ '.basename($bios) : 'No BIOS set — boot will fail until one is provided.' }}
                    </native:text>
                </native:column>
            @endif

            <native:divider />
            <native:text class="text-gray-400 text-sm">ROMS</native:text>

            @forelse ($roms as $rom)
                <native:list-item
                    native:key="{{ $rom['path'] }}"
                    headline="{{ $rom['name'] }}"
                    supporting="{{ $rom['bundled'] ? 'bundled' : $rom['path'] }}"
                    trailingIcon="play.fill"
                    class="bg-gray-900 rounded-lg"
                    @press="boot('{{ $rom['path'] }}')" />
            @empty
                <native:column class="gap-2 p-4 bg-gray-900 rounded-lg items-center">
                    <native:text class="text-yellow-400">No ROMs found in this folder.</native:text>
                    <native:text class="text-gray-500 text-xs text-center">Push ROMs to {{ $folder }} (adb push) or set another folder above, then Rescan.</native:text>
                </native:column>
            @endforelse
        </native:column>
    </native:scroll-view>
</native:column>
