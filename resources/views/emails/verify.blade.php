@component('mail::message')
    # {{ __('mail.verify.greeting', ['name' => $user->name]) }}

    {{ __('mail.verify.line1') }}

    @component('mail::button', ['url' => $url, 'color' => 'primary'])
        {{ __('mail.verify.action') }}
    @endcomponent

    @component('mail::subcopy')
        {{ __('mail.verify.line2') }}
    @endcomponent

    {{ __('mail.verify.salutation', ['app' => config('app.name')]) }}
@endcomponent
