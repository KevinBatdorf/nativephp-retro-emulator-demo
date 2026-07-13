<native:column class="flex-1 bg-black p-4 gap-2">
    <native:emulator name="audio" class="flex-1" />
    <native:text class="text-white text-lg">{{ $status }}</native:text>
    <native:row class="p-2 gap-2 items-center justify-center">
        <native:button label="FF toggle" @press="toggleFf" />
        <native:button label="Vol 50" @press="vol50" />
        <native:button label="Bal LEFT" @press="balLeft" />
        <native:button label="Reset" @press="reset" />
    </native:row>
</native:column>
