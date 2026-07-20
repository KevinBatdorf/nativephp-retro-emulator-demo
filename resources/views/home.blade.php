<native:column class="flex-1 bg-black">
    <native:text class="text-white text-2xl px-4 pt-4 pb-3">Retro Emulator</native:text>

    <native:scroll-view class="flex-1">
        <native:lazy-grid columns="4" gap="10" class="px-4">
            @forelse ($systems as $system)
                @php
                    $sid = $system['id'];
                    $sname = \App\Support\Catalog::shortName($sid, $system['name']);
                    $sroute = '/play/'.$sid;
                @endphp
                <native:button native:key="{{ $sid }}" label="{{ $sname }}" class="w-full" @navigate.none="$sroute" />
            @empty
                <native:text class="text-yellow-400 py-4">No systems compiled into this build.</native:text>
            @endforelse
        </native:lazy-grid>
    </native:scroll-view>

    <native:row class="px-4 py-2 items-center bg-gray-950">
        <native:button label="Settings" @navigate.none="'/settings'" />
    </native:row>
</native:column>
