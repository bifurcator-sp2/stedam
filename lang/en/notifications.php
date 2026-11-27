<?php

return [
    // Notification type by short name (App\Enums\NotificationType).
    // Key is the enum case name (shortName) returned by the API.
    'types' => [
        'RequestedAction' => 'Action confirmation request',
    ],

    // data.type inside a notification (App\Enums\ActionNotificationType).
    'action_types' => [
        'request'  => 'Request',
        'approved' => 'Request approved',
        'rejected' => 'Request rejected',
        'done'     => 'Action completed',
        'error'    => 'Request error',
    ],

    // Requested action types by handler alias (config/requested_actions.php).
    'requested_action_types' => [
        'StudentToTeacher' => 'Enrollment to teacher',
    ],

    // Requested action statuses (enum RequestedActionStatus).
    'statuses' => [
        'created'  => 'Created',
        'pending'  => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'done'     => 'Done',
        'error'    => 'Error',
    ],
];
