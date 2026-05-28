<x-filament-widgets::widget class="fi-wi-open-alerts-by-source">
    <x-filament::section heading="Open Alerts by Source">
        @if(empty($rows))
            <p class="text-sm text-gray-500 dark:text-gray-400">No open alerts.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">Source</th>
                            <th class="py-2 pr-4 text-center">With work item</th>
                            <th class="py-2 pr-4 text-center">Without work item</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-4">
                                    <a href="{{ $row['source_url'] }}"
                                       class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                        {{ $row['source_id'] }}
                                    </a>
                                </td>
                                <td class="py-2 pr-4 text-center">
                                    @if($row['linked'] > 0)
                                        <a href="{{ $row['linked_url'] }}"
                                           class="font-semibold text-success-600 hover:underline dark:text-success-400">
                                            {{ $row['linked'] }}
                                        </a>
                                    @else
                                        <span class="text-gray-400">0</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-center">
                                    @if($row['unlinked'] > 0)
                                        <a href="{{ $row['unlinked_url'] }}"
                                           class="font-semibold text-warning-600 hover:underline dark:text-warning-400">
                                            {{ $row['unlinked'] }}
                                        </a>
                                    @else
                                        <span class="text-gray-400">0</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
