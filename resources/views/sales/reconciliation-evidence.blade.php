@extends('sales.layout')
@section('title', '原记录核验证据')
@section('content')
<h1>案件 #{{ $case->id }} 核验证据</h1>
@foreach($entries as $entry)<section><h2>版本 {{ $entry->sequence }} · {{ $entry->action }}</h2><p>{{ $entry->reason }}</p><pre>{{ json_encode($entry->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></section>@endforeach
{{ $entries->links('sales.pagination') }}
<p><a href="/admin/reconciliations?case={{ $case->id }}">返回原案件</a></p>
@endsection
