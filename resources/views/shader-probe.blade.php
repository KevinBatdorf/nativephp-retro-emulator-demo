<native:column class="flex-1 bg-black">
    <native:emulator name="shaderprobe" system="sfc" :config="$config" :rom="$rom" class="flex-1" />
    <native:text class="text-white p-2 text-center">shader probe — grayscale.slangp — {{ basename((string) $rom) }}</native:text>
</native:column>
