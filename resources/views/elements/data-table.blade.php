<table class="data-table" style="width:100%;border-collapse:collapse;table-layout:fixed;font-size:inherit"><thead><tr>
    @foreach ($dados['columns'] ?? [] as $column)
        <th scope="col" style="border:1px solid #d1d5db;padding:4pt 6pt;text-align:left;background:#f3f4f6;font-weight:600;overflow-wrap:anywhere">{{ $column['label'] ?? '' }}</th>
    @endforeach
</tr></thead><tbody>
    @foreach ($dados['rows'] ?? [] as $row)
        <tr>
            @foreach ($dados['columns'] ?? [] as $column)
                <td style="border:1px solid #d1d5db;padding:4pt 6pt;text-align:left;vertical-align:top;overflow-wrap:anywhere">{{ $row[$column['field'] ?? ''] ?? '' }}</td>
            @endforeach
        </tr>
    @endforeach
</tbody></table>
