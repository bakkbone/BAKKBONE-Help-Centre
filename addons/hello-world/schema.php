<?php
// Names get the add-on's prefix (notes => addon_hello_world_notes); bump version in addon.json to apply changes.
return [
    'notes' => [
        'columns' => [
            'id' => 'pk',
            'ticket_id' => 'int|0',
            'staff_id' => 'int',
            'body' => 'text',
            'created_at' => 'datetime',
        ],
        'index' => [['ticket_id']],
    ],
];
