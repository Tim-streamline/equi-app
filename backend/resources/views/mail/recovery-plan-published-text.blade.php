Hey {!! $name !!},

Goed nieuws! Het herstelplan voor {!! $horseName !!} staat klaar in {!! config('app.brand_name') !!}!

In het herstelplan vind je per fase concrete adviezen voor voeding, beweging en management, zodat je precies weet waarmee je aan de slag kunt.

@if ($hasSubscription)
👉 Bekijk het herstelplan van {!! $horseName !!}:
@else
Verleng je abonnement in de app om het herstelplan van {!! $horseName !!} te bekijken:
@endif
{!! $appUrl !!}

Veel succes met de eerste fase!

Liefs,
Shelley
De Paardentherapeut | {!! config('app.brand_name') !!}
