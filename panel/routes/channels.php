<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Live statistics of one server (docs/adr/0036). Every panel user is an administrator (docs/adr/0007);
// the auth route runs behind the admin middleware (bootstrap/app.php).
Broadcast::channel('admin.servers.{server}', fn (User $user, Server $server): bool => true);
