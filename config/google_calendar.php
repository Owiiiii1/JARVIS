<?php

return [
    'api_base' => env('GOOGLE_CALENDAR_API_BASE', 'https://www.googleapis.com/calendar/v3'),
    'default_calendar' => 'primary',
    'max_calendars' => (int) env('GOOGLE_CALENDAR_MAX_CALENDARS', 20),
    'max_events' => (int) env('GOOGLE_CALENDAR_MAX_EVENTS', 25),
    'max_search_results' => (int) env('GOOGLE_CALENDAR_MAX_SEARCH', 15),
    'max_freebusy_days' => (int) env('GOOGLE_CALENDAR_MAX_FREEBUSY_DAYS', 31),
    'max_list_range_days' => (int) env('GOOGLE_CALENDAR_MAX_LIST_DAYS', 90),
    'max_attendees' => (int) env('GOOGLE_CALENDAR_MAX_ATTENDEES', 20),
    'max_title_chars' => (int) env('GOOGLE_CALENDAR_MAX_TITLE', 200),
    'max_description_chars' => (int) env('GOOGLE_CALENDAR_MAX_DESCRIPTION', 2000),
    'max_location_chars' => (int) env('GOOGLE_CALENDAR_MAX_LOCATION', 500),
    'default_search_past_days' => (int) env('GOOGLE_CALENDAR_SEARCH_PAST_DAYS', 90),
    'default_search_future_days' => (int) env('GOOGLE_CALENDAR_SEARCH_FUTURE_DAYS', 365),
    'default_list_future_days' => (int) env('GOOGLE_CALENDAR_LIST_FUTURE_DAYS', 7),
    'timeout' => (int) env('GOOGLE_CALENDAR_HTTP_TIMEOUT', 10),
    'connect_timeout' => (int) env('GOOGLE_CALENDAR_HTTP_CONNECT_TIMEOUT', 5),
    'get_retries' => (int) env('GOOGLE_CALENDAR_GET_RETRIES', 1),
    'confirmation_ttl_seconds' => (int) env('TOOL_CONFIRMATION_TTL', 600),
];
