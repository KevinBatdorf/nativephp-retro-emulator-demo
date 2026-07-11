<native:column class="flex-1 bg-black">
    <native:emulator name="main" class="flex-1" />
    <native:column class="p-3 gap-1">
        <native:text class="{{ $finished ? ($failed === [] ? 'text-green-400' : 'text-red-400') : 'text-white' }}">
            {{ $headline }}
        </native:text>
        @if ($waitingOn)
            <native:text class="text-yellow-400">waiting: {{ $waitingOn }}</native:text>
        @endif
        @foreach (array_slice($failed, 0, 8) as $row)
            <native:text class="text-red-400">✗ {{ $row['label'] }} — {{ $row['detail'] }}</native:text>
        @endforeach
        @if (count($failed) > 8)
            <native:text class="text-red-400">…and {{ count($failed) - 8 }} more failures (see conformance-results.json)</native:text>
        @endif
    </native:column>
</native:column>
