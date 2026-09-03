<?php

return [
    'max_memories' => (int) env('PROJECT_MAX_MEMORIES', 10),
    'max_topics' => (int) env('PROJECT_MAX_TOPICS', 10),
    'max_summaries' => (int) env('PROJECT_MAX_SUMMARIES', 5),
    'max_projects_search' => (int) env('PROJECT_MAX_SEARCH', 10),
    'description_max' => (int) env('PROJECT_DESCRIPTION_MAX', 5000),
];
