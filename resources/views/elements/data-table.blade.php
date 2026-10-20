<table class="data-table" style="width:100%;border-collapse:collapse;table-layout:fixed;font-size:inherit">
    <thead><tr>
        <template x-for="column in data.columns" :key="column.field">
            <th scope="col" x-text="column.label || ''" style="border:1px solid #d1d5db;padding:4pt 6pt;text-align:left;background:#f3f4f6;font-weight:600;overflow-wrap:anywhere"></th>
        </template>
    </tr></thead>
    <tbody>
        <template x-for="(row,index) in data.rows" :key="index">
            <tr><template x-for="column in data.columns" :key="column.field">
                <td x-text="row[column.field] ?? ''" style="border:1px solid #d1d5db;padding:4pt 6pt;text-align:left;vertical-align:top;overflow-wrap:anywhere"></td>
            </template></tr>
        </template>
    </tbody>
</table>
