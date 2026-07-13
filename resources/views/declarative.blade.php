<native:column class="flex-1 bg-black">
    <native:emulator name="declarative" system="sfc" :config="$config" :rom="$rom" class="flex-1" />
    <native:text class="text-white p-2 text-center">declarative boot — sfc — {{ basename((string) $rom) }}</native:text>
</native:column>
