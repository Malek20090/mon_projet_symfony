<?php

namespace App\Service;

class GoogleCalendarService
{
    public function generateEventUrl(
        string $title,
        string $details,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?string $recur = null
    ): string
    {
        $dates = $start->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z')
            . '/'
            . $end->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z');

        $params = [
            'action' => 'TEMPLATE',
            'text' => $title,
            'details' => $details,
            'dates' => $dates,
        ];
        if ($recur !== null && trim($recur) !== '') {
            $params['recur'] = $recur;
        }

        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }
}
