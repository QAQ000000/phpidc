@props(['id', 'title', 'description' => null, 'showErrors' => false])
<dialog id="{{ $id }}" {{ $attributes->class(['admin-ui-dialog']) }} aria-labelledby="{{ $id }}-title" x-on:click="if ($event.target === $el) $el.close()">
    <header class="admin-ui-dialog-heading"><div><h2 id="{{ $id }}-title">{{ $title }}</h2>@if($description)<p>{{ $description }}</p>@endif</div>
        <button type="button" class="admin-ui-dialog-close" x-on:click="$el.closest('dialog').close()" aria-label="关闭{{ $title }}">×</button>
    </header>
    <div class="admin-ui-dialog-body">
        @if($showErrors && $errors->any())<div role="alert" class="inventory-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        {{ $slot }}
    </div>
</dialog>
