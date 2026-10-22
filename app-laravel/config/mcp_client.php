<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Docker Compose project
    |--------------------------------------------------------------------------
    |
    | The project this stack runs as. docker-compose.yml passes its own
    | ${COMPOSE_PROJECT_NAME} into the app container, so the MCP client
    | configuration shown in Profile -> Integrations (and printed by
    | `php artisan mcp:client-config`) targets this stack's containers from
    | any directory on the machine that runs it.
    |
    */

    'compose_project' => env('MCP_CLIENT_COMPOSE_PROJECT'),

];
