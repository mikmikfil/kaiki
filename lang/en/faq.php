<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The operator's FAQ screen on /app (#103, CNV-11, I18N-1)
|--------------------------------------------------------------------------
|
| Written for a boat operator, not for an administrator. The help text says
| where an entry will appear, because "tenant-wide" is not a thing anybody
| standing on a quay has ever said.
|
*/

return [

    'nav' => 'FAQ',

    'model' => [
        'singular' => 'Question',
        'plural' => 'Questions',
    ],

    'sections' => [
        'entry' => 'The question and the answer',
        'where' => 'Where it appears',
    ],

    'form' => [
        'question' => [
            'label' => 'Question',
            'help' => 'The way a guest would ask it. "Do I need to know how to swim?" rather than "Swimming ability".',
        ],
        'answer' => [
            'label' => 'Answer',
            'help' => 'Plain text. Leave a blank line between paragraphs.',
        ],
        'product' => [
            'label' => 'Trip',
            'help' => 'Leave this empty for anything about you rather than about one trip — where to meet, what to bring, whether children are welcome.',
            'all' => 'All trips',
        ],
        'is_published' => [
            'label' => 'Published',
            'help' => 'Unpublished questions stay here and appear on no page.',
        ],
    ],

    'table' => [
        'question' => 'Question',
        'product' => 'Trip',
        'is_published' => 'Published',
    ],

    'empty' => [
        'heading' => 'No questions yet',
        'body' => 'Start with the three you answer on the telephone every day. They appear on your page and in search results.',
    ],

];
