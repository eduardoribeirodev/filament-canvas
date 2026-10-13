<style>body{font-family:Arial,sans-serif;color:#222}.page{position:relative;width:{{ $width }}pt;height:{{ $height }}pt;overflow:hidden;background:#fff;break-after:page}.page:last-child{break-after:auto}.block{position:absolute;box-sizing:border-box;transform-origin:center}.text-content{box-sizing:border-box;width:100%;height:100%;white-space:pre-wrap;overflow-wrap:anywhere;overflow:hidden;line-height:1.25}.block-link{position:absolute;inset:0;z-index:1;color:inherit}.image-frame{position:relative;overflow:hidden;width:100%;height:100%}.image{display:block;width:100%;height:100%}.drawing{display:block;width:100%;height:100%;overflow:visible}.missing{display:flex;align-items:center;justify-content:center;height:100%;color:#888;font-size:9pt}.data-table{border-collapse:collapse;width:100%;font-size:inherit}.data-table th,.data-table td{border:1px solid #ccc;padding:3pt;text-align:left}
{!! \EduardoRibeiroDev\FilamentCanvas\Support\RichText::styles() !!}
</style>
@foreach($pageContents ?? [$blocks ?? ''] as $content)
<section class="page">{!! $content !!}</section>
@endforeach
