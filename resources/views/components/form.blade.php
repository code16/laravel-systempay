@php use Code16\Systempay\SystemPay; @endphp
@props([
    /** @var SystemPay $config */
    'config',
    'button' => null
])
<form method="post" action="{{$config->url}}" accept-charset="UTF-8">
@foreach($config->prepareFormParams() as $key => $value)
<input type="hidden" name="{{ $key }}" value="{{ $value }}">
@endforeach
{{ $slot ?? '' }}
@if(!$button && (isset($button) && $button->isEmpty() || isset($slot) && $slot->isEmpty()))
<button type="submit">Pay</button>
@elseif(isset($slot) && $slot->isEmpty())
{{ $button }}
@endif
</form>