<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Lobby Expiry Time
    |--------------------------------------------------------------------------
    |
    | The number of minutes before a lobby expires if workout hasn't started.
    | Default: 30 minutes
    |
    */
    'expiry_minutes' => env('LOBBY_EXPIRY_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Invitation Expiry Time
    |--------------------------------------------------------------------------
    |
    | The number of minutes before an invitation expires.
    | Default: 5 minutes
    |
    */
    'invitation_expiry_minutes' => env('INVITATION_EXPIRY_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Max Members Per Lobby
    |--------------------------------------------------------------------------
    |
    | Maximum number of members allowed in a single lobby.
    | Default: 10 members
    |
    */
    'max_members' => env('LOBBY_MAX_MEMBERS', 10),

    /*
    |--------------------------------------------------------------------------
    | Message Length Limits
    |--------------------------------------------------------------------------
    |
    | Maximum character length for chat messages.
    | Default: 500 characters
    |
    */
    'max_message_length' => env('LOBBY_MAX_MESSAGE_LENGTH', 500),
];
