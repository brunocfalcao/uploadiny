<?php

declare(strict_types=1);

use App\Http\Middleware\PrivateMcpResponse;
use App\Mcp\Servers\UploadinyServer;
use App\UploadinyTokenAbility;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', UploadinyServer::class)
    ->middleware([PrivateMcpResponse::class, 'auth:sanctum', 'abilities:'.implode(',', UploadinyTokenAbility::agent())])
    ->name('mcp.uploadiny');
