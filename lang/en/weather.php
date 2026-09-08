<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The wind outlook (ADR-0027, OPS-6)
|--------------------------------------------------------------------------
|
| Every line is written for somebody deciding whether to cancel a charter. The
| numbers are Beaufort because that is the scale a Greek skipper uses, and the
| panel says which of wind or gust produced the figure — being told "7" without
| that is being told half of it.
|
| Nothing here says the product decided anything, because it did not.
*/

return [

    'heading' => 'Weather ahead',
    'subheading' => 'Days over a boat’s own limit, and what is booked on them. Nothing is cancelled automatically.',

    'limit' => 'Wind limit: :bft Bft',

    'wind_short' => 'wind',
    'gust_short' => 'gust',
    'by_wind' => 'Highest sustained wind that day.',
    'by_gust' => 'Driven by the gust, which is higher than the sustained wind.',

    'affected' => ':departures departures · :passengers passengers booked',
    'nothing_booked' => 'Rough, but nothing booked.',

    'source' => 'Forecast by :name',

    'open_calendar' => 'Open the calendar',

];
