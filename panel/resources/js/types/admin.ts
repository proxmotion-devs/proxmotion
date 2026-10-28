export type NetworkModel = 'bridged' | 'routed' | 'nat';
export type NodeStatus = 'online' | 'offline' | 'unknown';

export type LocationOption = { uuid: string; name: string };

export type Location = LocationOption & {
    code: string;
    country_code: string | null;
    description: string | null;
    /** Names servers ordered without a hostname (docs/adr/0031). */
    hostname_template: string | null;
    /** CIDRs its servers may not open connections to (docs/adr/0034). */
    protected_networks: string[];
    clusters_count: number;
    ip_pools_count: number;
};

/** Panel settings (docs/adr/0038); saved ones override .env. */
export type PanelSettings = {
    cross_cluster_moves: boolean;
    move_deadline_minutes: number;
    move_bwlimit_kib: number | null;
    min_vmid: number;
    provisioning_deadline_minutes: number;
    verify_dns_name: string | null;
    protected_networks: string[];
    drift_stopped_grace_minutes: number;
    storage_warning_percent: number;
    storage_critical_percent: number;
    console_max_minutes: number;
    console_idle_minutes: number;
    docs_url: string | null;
    traffic_reset_day: number;
    traffic_counted: 'both' | 'out' | 'in';
    traffic_action: 'none' | 'suspend';
    usage_cpu_percent: number | null;
    usage_memory_percent: number | null;
    usage_disk_mb_s: number | null;
    usage_network_mbps: number | null;
    usage_window_minutes: number;
    usage_action: 'none' | 'stop' | 'suspend';
    password_length: number;
    password_characters: 'lower' | 'mixed' | 'symbols';
    password_prefix: string | null;
    password_suffix: string | null;
    /** docs/adr/0049 */
    ssh_keys_max: number;
    notifications_enabled: boolean;
    notification_recipients: string[];
    notification_throttle_minutes: number;
};

export type Cluster = {
    uuid: string;
    name: string;
    location: LocationOption;
    type: 'standalone' | 'cluster' | null;
    proxmox_cluster_name: string | null;
    hosts: string[];
    port: number;
    token_id: string;
    verify_tls: boolean;
    resource_pool: string | null;
    is_active: boolean;
    pve_version: string | null;
    nodes_count: number;
    last_synced_at: string | null;
    last_sync_error: string | null;
    /** Why anti-spoofing does not work on this cluster (an English key), or null. */
    firewall_problem: string | null;
    /** The token servers from other clusters are moved in with (docs/adr/0037 §5). */
    incoming_token_id: string | null;
    receives_moves: boolean;
    tls_fingerprint: string | null;
    /** The worst health of its storages, disks and Ceph cluster (docs/adr/0047). */
    storage_health: StorageHealth;
    /** HEALTH_OK, HEALTH_WARN or HEALTH_ERR of the Ceph cluster Proxmox manages; null without one. */
    ceph_health: string | null;
    /** Ceph's health checks, in Ceph's own English. */
    ceph_checks: { severity: string; message: string }[];
};

export type StorageHealth = 'ok' | 'warning' | 'critical' | 'unknown';

/** What is wrong with a storage or disk: an English key with its placeholders. */
export type HealthProblem = {
    severity: 'warning' | 'critical';
    message: string;
    replace: Record<string, string | number>;
};

export type NodeDisk = {
    id: number;
    devpath: string;
    model: string | null;
    serial: string | null;
    /** ssd, hdd, nvme, usb… */
    type: string | null;
    size_bytes: number | null;
    /** What Proxmox says uses it (LVM, ZFS, partitions…). */
    used: string | null;
    /** smartctl's overall result (PASSED, FAILED, OK, UNKNOWN). */
    smart: string | null;
    /** Rated life left in per cent (SSDs and NVMe). */
    wearout: number | null;
    health: StorageHealth;
    health_problems: HealthProblem[];
    /** False once Proxmox no longer reports it. */
    is_present: boolean;
};

export type Storage = {
    id: number;
    name: string;
    type: string;
    content: string[];
    is_shared: boolean;
    is_available: boolean;
    use_for_servers: boolean;
    supports_vm_disks: boolean;
    total_bytes: number | null;
    used_bytes: number | null;
    health: StorageHealth;
    health_problems: HealthProblem[];
    /** The state of its ZFS pool (ONLINE, DEGRADED…) or of the Ceph cluster (HEALTH_OK…). */
    pool_state: string | null;
    /** LVM thin pools: metadata in use. */
    metadata_used_bytes: number | null;
    metadata_total_bytes: number | null;
};

export type Bridge = {
    name: string;
    is_active: boolean;
    is_vlan_aware: boolean;
    cidr: string | null;
    comments: string | null;
};

export type Node = {
    uuid: string;
    name: string;
    status: NodeStatus;
    is_maintenance: boolean;
    cpu_cores: number | null;
    memory_bytes: number | null;
    cpu_overcommit: number;
    memory_overcommit: number;
    disk_overcommit: number;
    max_servers: number | null;
    /** Its address in the cluster, from the sync. */
    address: string | null;
    /** Where its API answers, set by an administrator (docs/adr/0037 §5). */
    api_host: string | null;
    last_seen_at: string | null;
    storages: Storage[];
    /** Its physical disks (docs/adr/0047). */
    disks: NodeDisk[];
    /** Why its disks and pools could not be read at the last sync. */
    disk_health_error: string | null;
    bridges: Bridge[];
};

export type ProxmoxTemplate = {
    id: number;
    vmid: number;
    name: string | null;
    cluster: string;
    node: string;
};

export type IpPool = {
    uuid: string;
    name: string;
    location: LocationOption;
    version: 4 | 6;
    network_model: NetworkModel;
    network: string;
    gateway: string | null;
    bridge: string;
    vlan_tag: number | null;
    nameservers: string[];
    is_active: boolean;
    dns_provider: string | null;
    reverse_zone: string | null;
    rdns_require_forward: boolean;
    isolate_guests: boolean;
    addresses_count?: number;
    reserved_count?: number;
    assigned_count?: number;
};

export type IpAddress = {
    id: number;
    address: string;
    mac_address: string | null;
    is_reserved: boolean;
    notes: string | null;
};

export type OsTemplate = {
    uuid: string;
    name: string;
    /** The version of the administrators' operating systems it is of (docs/adr/0026). */
    operating_system_version_id: number | null;
    /** The system's slug and the version, copied from it. */
    distribution: string;
    version: string;
    platform: OsPlatform;
    is_active: boolean;
    /** How its servers are cloned (docs/adr/0027). */
    clone_mode: CloneMode;
    sort_order: number;
    proxmox_template_ids: number[];
};

export type CloneMode = 'full' | 'linked';

export type OsPlatform = 'linux' | 'windows';

export type GuestScriptEvent =
    | 'provisioned'
    | 'addresses_changed'
    | 'disk_resized'
    | 'password_reset'
    | 'hostname_changed'
    | 'ssh_keys_changed';

export type GuestScriptSlot = {
    event: GuestScriptEvent;
    content: string | null;
    /** Saved but not published: runs only in trials (docs/adr/0023). */
    draft: string | null;
    default: string | null;
    inputs: string[];
    can_be_tried: boolean;
    critical: boolean;
    /** How long one run may take; the task waits for it. */
    timeout_seconds: number;
};

/** One run of a guest script, as the Scripts page lists it. */
export type GuestScriptRun = {
    uuid: string;
    server: string;
    server_uuid: string;
    /** The custom script's name when it ran (docs/adr/0043); null for an event's own script. */
    script_name: string | null;
    source: 'default' | 'published' | 'draft';
    trace: boolean;
    /** Run by an operation, a trial on the template page, or by hand from the server page. */
    kind: 'operation' | 'trial' | 'manual';
    status: 'running' | 'succeeded' | 'failed';
    exit_code: number | null;
    message: string | null;
    started_at: string | null;
    finished_at: string | null;
};

/** An administrator's own script on an OS template (docs/adr/0043). */
export type CustomScript = {
    uuid: string;
    name: string;
    /** Listed under Run script on the server page. */
    manual: boolean;
    /** Runs after the fixed script of these events. */
    events: GuestScriptEvent[];
    /** Null until the first draft is published. */
    content: string | null;
    draft: string | null;
};

export type GuestScriptRunDetail = GuestScriptRun & {
    script: string;
    inputs: Record<string, unknown>;
    stdout: string | null;
    stderr: string | null;
    output_truncated: boolean;
    /** False for password scripts, whose output is never kept. */
    output_kept: boolean;
};

/** CPU and disk limits (docs/adr/0034); null is unlimited. */
export type ResourceLimitValues = {
    cpu_limit: number | null;
    cpu_units: number | null;
    disk_mb_s: number | null;
    disk_iops: number | null;
};

export type Package = ResourceLimitValues & {
    uuid: string;
    name: string;
    cpu_cores: number;
    memory_mib: number;
    disk_mib: number;
    traffic_bytes: number | null;
    port_speed_mbps: number | null;
    ipv4_count: number;
    ipv6_count: number;
    snapshot_slots: number;
    backup_slots: number;
    network_models: NetworkModel[];
    is_active: boolean;
    sort_order: number;
    /** Reinstalls through the billing API within reinstall_period_days; null: unlimited (docs/adr/0045). */
    reinstall_limit: number | null;
    reinstall_period_days: number | null;
    /** Empty: any node; otherwise the groups of the ordered location (docs/adr/0020). */
    placement_group_ids: number[];
    /** Empty: any OS template; otherwise the ones its servers may use (docs/adr/0040). */
    os_template_ids: number[];
    /** New servers start with these rules and its default policy (docs/adr/0035 §3). */
    firewall_ruleset: string | null;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export const NETWORK_MODELS: { value: NetworkModel; label: string }[] = [
    { value: 'bridged', label: 'Bridged public IP' },
    { value: 'routed', label: 'Routed / failover IP' },
    { value: 'nat', label: 'NAT' },
];

export type ServerStatus =
    | 'pending'
    | 'provisioning'
    | 'active'
    | 'suspended'
    | 'reinstalling'
    | 'terminating'
    | 'terminated'
    | 'error';

export type TaskStatus = 'queued' | 'running' | 'succeeded' | 'failed';

export type TaskType =
    | 'create'
    | 'suspend'
    | 'unsuspend'
    | 'terminate'
    | 'adopt'
    | 'reset_password'
    | 'reinstall'
    | 'change_resources'
    | 'guest_script'
    | 'change_primary_address'
    | 'change_addresses'
    | 'enable_anti_spoofing'
    | 'apply_firewall'
    | 'change_hostname'
    | 'change_ssh_keys'
    | 'install_app'
    | 'app_run'
    | 'verify'
    | 'move'
    | PowerAction;

export type PowerAction = 'start' | 'shutdown' | 'stop' | 'reboot';

export type Option<T extends string | number = string> = {
    value: T;
    label: string;
};

export type ServerListItem = {
    uuid: string;
    hostname: string;
    /** The Proxmox VM name (its address) when the hostname was generated (docs/adr/0031). */
    vm_name: string | null;
    status: ServerStatus;
    vmid: number;
    billing_system: string | null;
    external_id: string | null;
    external_client_id: string | null;
    node: string;
    location: string;
    package: string | null;
    addresses: string[];
    /** An open firewall drift: its VM firewall does not stop spoofing (docs/adr/0033). */
    unprotected: boolean;
};

export type Task = {
    uuid: string;
    type: TaskType;
    status: TaskStatus;
    steps: {
        key: string;
        status: string;
        message: string | null;
        finished_at: string | null;
    }[];
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
    can_fail: boolean;
};

export type DriftType =
    | 'missing_vm'
    | 'node_mismatch'
    | 'resource_mismatch'
    | 'power_mismatch'
    | 'unmanaged_vm'
    | 'firewall_mismatch';

/** A difference between the panel and Proxmox (ADR 0005). */
export type Drift = {
    uuid: string;
    type: DriftType;
    vmid: number;
    /** firewall_mismatch: `addresses` the IP set should hold; `problems`, `missing` and `extra` in `actual` (docs/adr/0033). */
    expected: Record<string, string | number | string[] | null> | null;
    actual: Record<string, string | number | string[] | null> | null;
    first_seen_at: string;
    last_seen_at: string;
    cluster: { uuid: string; name: string };
    server: { uuid: string; hostname: string; status: ServerStatus } | null;
};

/** Resources as the ResourcesDialog edits them. */
export type ResourceValues = ResourceLimitValues & {
    cpu_cores: number;
    memory_mib: number;
    disk_gib: number;
    ipv4_count: number;
    ipv6_count: number;
    port_speed_mbps: number | null;
    traffic_gb: number | null;
};

/** Choices for ResourcesDialog: packages for the server's network model, and its current resources. */
export type ResourceOptions = {
    packages: (ResourceValues & Option)[];
    current: ResourceValues & { package: string };
};

/** How far a server moves (docs/adr/0037). */
export type ServerMoveKind = 'node' | 'cluster' | 'location';

export type ServerMoveStatus = 'queued' | 'running' | 'succeeded' | 'failed';

/** One move of a server, newest first. */
export type ServerMove = {
    uuid: string;
    kind: ServerMoveKind;
    status: ServerMoveStatus;
    from: string;
    to: string;
    error: string | null;
    /** Proxmox moved the VM but a later step failed: a new task can finish it. */
    can_finish: boolean;
    created_at: string | null;
    finished_at: string | null;
};

/** A node a server can be moved to. */
export type MoveNodeOption = Option & {
    location: string;
    location_name: string;
    cluster: string;
    online: boolean;
    maintenance: boolean;
    /** Why it cannot take the server (translated), or null. */
    refusal: string | null;
    storages: {
        name: string;
        shared: boolean;
        use_for_servers: boolean;
        free_mib: number;
    }[];
};

/** What the "Move server" dialog offers (docs/adr/0037). */
export type MoveOptions = {
    /** Why the server cannot be moved now (translated), or null. */
    refusal: string | null;
    /** Moves to other clusters are turned on (Panel settings, docs/adr/0038). */
    cross_cluster: boolean;
    /** It has addresses the provider routes to its node. */
    routed: boolean;
    location: string;
    cluster: string;
    nodes: MoveNodeOption[];
    placementGroups: (Option & { location: string; location_name: string })[];
    ipv4Pools: (Option & { location: string; free: number })[];
    moves: ServerMove[];
};

/** One address on the server page. */
export type ServerAddress = {
    address: string;
    version: 4 | 6;
    /** The primary address of its version (listed first). */
    primary: boolean;
    pool: { uuid: string; name: string };
    ptr: string | null;
    ptr_pending: boolean;
};

/** A pool addresses may be added from (same location, network model, bridge and VLAN; docs/adr/0028). */
export type AddressPoolOption = Option & {
    version: 4 | 6;
    /** Free IPv4 addresses; null for IPv6 pools, which make new addresses on demand. */
    free: number | null;
    /** The first free IPv4 addresses, for choosing one. */
    addresses: string[];
};

/** Choices for ReinstallDialog: OS templates of the server's cluster and the apps that can come with them. */
export type ReinstallOptions = {
    osTemplates: Option[];
    apps: OrderedAppOption[];
};

/** A server over its traffic quota or a usage limit (docs/adr/0039). */
export type UsageViolation = {
    id: string;
    kind: 'traffic' | 'cpu' | 'memory' | 'disk' | 'network';
    /** English key. */
    label: string;
    /** Counted bytes (traffic), else the average over the window in `unit`. */
    value: number;
    threshold: number;
    unit: 'bytes' | 'percent' | 'MB/s' | 'Mbps';
    window_minutes: number | null;
    period_start: string | null;
    action: 'none' | 'stop' | 'suspend';
    started_at: string | null;
    acted_at: string | null;
    resolved_at: string | null;
};

/** A server's traffic in the current period and its latest violations (docs/adr/0039). */
export type ServerUsage = {
    traffic: {
        period_start: string;
        period_end: string;
        in_bytes: number;
        out_bytes: number;
        counted_bytes: number;
        /** null: unlimited. */
        quota_bytes: number | null;
    };
    violations: UsageViolation[];
};

/** How often a server's billing system may reinstall it (ReinstallLimits, docs/adr/0045). */
export type ReinstallLimitState = {
    /** server: its own limit; package: its package's (none without a package). */
    source: 'server' | 'package';
    /** The limit that applies; null: unlimited, as are the counts then. */
    limit: number | null;
    period_days: number | null;
    used: number | null;
    remaining: number | null;
    /** When the next reinstall is possible once none remain; null for a limit of 0. */
    available_at: string | null;
    server_limit: number | null;
    server_period_days: number | null;
    package_limit: number | null;
    package_period_days: number | null;
    has_package: boolean;
    reset_at: string | null;
};

/** The latest checks from inside a server (ServerVerifier, docs/adr/0032 §6). */
export type ServerVerification = {
    checked_at: string;
    checks: {
        /** addresses, gateway, dns, hostname or disk. */
        key: string;
        status: 'passed' | 'failed' | 'skipped';
        params: Record<string, string>;
        /** English key with :placeholders from params. */
        detail: string | null;
    }[];
};

/**
 * Live figures of a server (LiveStats, docs/adr/0036). Times in ms since the
 * epoch; network and disk in bytes per second, null without two samples.
 */
export type ServerStats = {
    time: number;
    status: string;
    uptime: number | null;
    cpu_percent: number | null;
    cpus: number | null;
    memory_bytes: number | null;
    memory_total_bytes: number | null;
    net_in: number | null;
    net_out: number | null;
    disk_read: number | null;
    disk_write: number | null;
};

/** How full a running server's file systems are, from its guest agent. */
export type ServerDiskUsage = {
    time: number;
    /** False when the guest agent did not answer. */
    available: boolean;
    /** The root file system (/ or C:) first. */
    file_systems: {
        mountpoint: string;
        type: string;
        used_bytes: number;
        total_bytes: number;
    }[];
};

/** One minute of a server's last hour, or one live sample. */
export type ServerStatsPoint = Pick<
    ServerStats,
    | 'time'
    | 'cpu_percent'
    | 'memory_bytes'
    | 'net_in'
    | 'net_out'
    | 'disk_read'
    | 'disk_write'
>;

/** What TaskList needs. */
export type TaskProgress = {
    uuid: string;
    type: TaskType;
    status: TaskStatus;
    steps: {
        key: string;
        status: string;
        message?: string | null;
        /** Missing on steps recorded before step times were kept. */
        started_at?: string | null;
        finished_at?: string | null;
    }[];
    /** Steps the task is expected to record, in order (TaskPlan); empty once it succeeded. */
    planned_steps?: string[];
    /** How long a running create or reinstall still takes, from earlier runs with its OS template. */
    estimate?: { remaining_seconds: number; based_on: number } | null;
    error?: string | null;
    created_at: string | null;
    /** Stuck (queued, or running past its time limit): an administrator may end it. */
    can_fail?: boolean;
    /** An administrator's script run, queued or running: it may be cancelled. */
    can_cancel?: boolean;
};

export type PermissionCheck = {
    path: string;
    purpose: string;
    missing: string[];
    excess: string[];
}[];

export type WebhookDeliveryStatus = 'pending' | 'succeeded' | 'failed';

export type WebhookEndpoint = {
    uuid: string;
    url: string;
    events: string[];
    active: boolean;
    /** Deliveries that failed in a row after every attempt. */
    failure_count: number;
    last_delivery: {
        status: WebhookDeliveryStatus;
        created_at: string | null;
    } | null;
};

export type WebhookDelivery = {
    uuid: string;
    event_id: string;
    event: string;
    status: WebhookDeliveryStatus;
    attempts: number;
    response_status: number | null;
    error: string | null;
    /** The exact JSON body that was signed and sent. */
    payload: string;
    next_attempt_at: string | null;
    delivered_at: string | null;
    created_at: string | null;
};

export const WEBHOOK_DELIVERY_TONES: Record<
    WebhookDeliveryStatus,
    { tone: 'success' | 'warning' | 'danger'; label: string }
> = {
    pending: { tone: 'warning', label: 'Pending' },
    succeeded: { tone: 'success', label: 'Delivered' },
    failed: { tone: 'danger', label: 'Failed' },
};

/** App Marketplace (docs/adr/0015). */
export type AppInstallationStatus =
    | 'queued'
    | 'installing'
    | 'installed'
    | 'failed'
    | 'uninstalling'
    | 'uninstall_failed'
    | 'upgrading'
    | 'upgrade_failed'
    | 'removed';

export type AppRunStatus = 'queued' | 'running' | 'succeeded' | 'failed';

/** An uninstall, upgrade or action of an installed app (docs/adr/0016). */
export type AppRun = {
    uuid: string;
    kind: 'uninstall' | 'upgrade' | 'action';
    action: AppText | null;
    /** The version an upgrade moves to. */
    target_version: string | null;
    status: AppRunStatus;
    step: string | null;
    error: string | null;
    /** Its log (docs/adr/0021). */
    log: string | null;
    created_at: string | null;
};

/** The log of one install attempt (docs/adr/0021), newest first. */
export type AppLogSummary = {
    uuid: string;
    attempt: number | null;
    created_at: string | null;
};

/** What the log endpoint answers. */
export type AppLogChunk = {
    text: string;
    /** Whether text replaces what was shown instead of continuing it. */
    reset: boolean;
    cursor: number;
    live: boolean;
    status: string;
    progress: number;
    step: string | null;
};

export type AppAction = { key: string; label: AppText; inputs: AppInput[] };

export type AppInstallation = {
    uuid: string;
    app: string;
    version: string;
    /** A trial of a draft on a test server (docs/adr/0018 §7). */
    is_trial: boolean;
    status: AppInstallationStatus;
    progress: number;
    step: string | null;
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
    can_uninstall: boolean;
    actions: AppAction[];
    /** The version it can be upgraded to and the inputs that version adds. */
    upgrade: { version: string; inputs: AppInput[] } | null;
    runs: AppRun[];
    logs: AppLogSummary[];
    /** Whether the app or one of its actions reported something to show. */
    has_outputs: boolean;
};

/** One value an app reported (docs/adr/0015): text, or a file base64-encoded. */
export type AppOutput = {
    key: string;
    type: string;
    label: AppText | null;
    secret: boolean;
    value: string | null;
    filename: string | null;
    content_base64: string | null;
};

/** What Show details revealed for one installation. */
export type RevealedAppOutputs = {
    installation: string;
    outputs: AppOutput[];
    runs: {
        uuid: string;
        action: AppText;
        created_at: string | null;
        outputs: AppOutput[];
    }[];
};

export type AppText = { en: string; tr?: string };

export type AppInput = {
    key: string;
    type:
        | 'string'
        | 'password'
        | 'integer'
        | 'port'
        | 'boolean'
        | 'enum'
        | 'slug'
        | 'email'
        | 'domain'
        | 'url';
    label: AppText | null;
    required: boolean;
    default: string | number | boolean | null;
    options: string[] | null;
    min: number | null;
    max: number | null;
    max_length: number | null;
};

export type AppOption = { slug: string; name: string; inputs: AppInput[] };

/** An active app that does not fit a server, with why (shown to administrators only). */
export type UnavailableApp = { slug: string; name: string; reason: string };

// An app that can come with a new system, and the OS templates it fits (docs/adr/0016 §5).
export type OrderedAppOption = AppOption & { os_templates: string[] };

export type AppEntitlementSource = 'order' | 'api' | 'admin';

/** The right to install a paid app on a server (docs/adr/0019). */
export type AppEntitlement = {
    uuid: string;
    app: string;
    source: AppEntitlementSource;
    reference: string | null;
    active: boolean;
    created_at: string | null;
    revoked_at: string | null;
};

/** A node offered for placing a new server by hand, with its storages for VM disks. */
export type PlacementNode = Option & {
    location: string;
    cluster: string;
    /** The placement groups the node belongs to. */
    placement_groups: string[];
    online: boolean;
    maintenance: boolean;
    /** What is left after over-commit; null where the node sets no limit. */
    capacity: {
        cpu_cores: number | null;
        memory_mib: number | null;
        servers: number | null;
    };
    storages: {
        name: string;
        type: string;
        shared: boolean;
        use_for_servers: boolean;
        /** Free space after over-commit and the disks already booked. */
        free_mib: number;
    }[];
};

/** A placement group offered on the create form. */
export type PlacementGroupOption = Option & {
    location: string;
    /** "<node uuid>/<storage name>" of the storages it lists; empty: those marked "use for servers". */
    storages: string[];
};

/** A package offered on the create form; its values fill the resource fields. */
export type PackageOption = Option &
    ResourceLimitValues & {
        network_models: NetworkModel[];
        placement_groups: string[];
        /** The OS templates its servers may use; empty for any (docs/adr/0040). */
        os_templates: string[];
        cpu_cores: number;
        memory_mib: number;
        disk_gib: number;
        ipv4_count: number;
        ipv6_count: number;
        port_speed_mbps: number | null;
        traffic_gb: number | null;
    };

/** A placement group (docs/adr/0020): nodes, and optionally storages, of one location. */
export type PlacementGroup = {
    uuid: string;
    name: string;
    description: string | null;
    location: { uuid: string; name: string };
    node_ids: number[];
    /** Empty: each node's storages marked "use for servers". */
    storage_ids: number[];
    packages_count: number;
};

/** An active IPv4 pool offered on the create form, with its first free addresses. */
export type Ipv4PoolOption = Option & {
    location: string;
    network_model: NetworkModel;
    /** All free addresses; `addresses` may list only the first of them. */
    free: number;
    addresses: string[];
};

/** The resources entered on the create form ("" port speed or traffic = unlimited). */
export type NewServerResources = {
    cpu_cores: number;
    memory_mib: number;
    disk_gib: number;
    ipv4_count: number;
    ipv6_count: number;
    port_speed_mbps: number | '';
    traffic_gb: number | '';
    cpu_limit: number | '';
    cpu_units: number | '';
    disk_mb_s: number | '';
    disk_iops: number | '';
};

/** One inbound firewall rule (docs/adr/0035); null port, source or comment: none. */
export type FirewallRuleValues = {
    action: 'accept' | 'drop';
    protocol: 'tcp' | 'udp' | 'icmp' | 'any';
    port: string | null;
    source: string | null;
    comment: string | null;
};

/** A port an installed app opens while the server blocks by default. */
export type AppFirewallRule = {
    app: string;
    protocol: 'tcp' | 'udp';
    port: number;
};

/** A public SSH key the panel manages on a server (docs/adr/0049). */
export type SshKey = {
    type: string;
    fingerprint: string;
    comment: string;
    /** The normalized authorized_keys line. */
    public_key: string;
};

/** A key found in the server's authorized_keys (docs/adr/0049 §11). */
export type GuestSshKey = SshKey & {
    /** The file it was found in. */
    file: string;
    /** Its line starts with options (command="…", no-pty, …). */
    options: boolean;
    /** The panel manages it. */
    managed: boolean;
};

/** What reading the keys inside a server found. */
export type GuestSshKeys = {
    keys: GuestSshKey[];
    /** Fingerprints of managed keys found in no file. */
    missing: string[];
    /** Key lines the panel cannot manage (e.g. DSA, short RSA keys). */
    unsupported: number;
};

export type ServerSshKeys = {
    keys: SshKey[];
    /** How many keys a server can have. */
    max: number;
};

/** A server's firewall on its page, with the rule sets that can be applied. */
export type ServerFirewall = {
    /** What happens to incoming traffic no rule matches. */
    default_policy: 'accept' | 'drop';
    rules: FirewallRuleValues[];
    app_rules: AppFirewallRule[];
    rulesets: Option[];
};

/** A firewall rule set (docs/adr/0035 §3). */
export type FirewallRuleset = {
    uuid: string;
    name: string;
    description: string | null;
    default_policy: 'accept' | 'drop';
    is_active: boolean;
    rules: FirewallRuleValues[];
    /** Whether its rules let SSH or RDP in. */
    opens_remote_access: boolean;
    packages_count: number;
};

/** The type of a report column: how the page formats its raw values (docs/adr/0046). */
export type ReportColumnType =
    | 'text'
    | 'number'
    | 'boolean'
    | 'mib'
    | 'traffic'
    | 'percent'
    | 'duration'
    | 'datetime'
    | 'server'
    | 'server_status'
    | 'task_type'
    | 'usage';

export type ReportCell = string | number | boolean | null;

/** One table of a report; `rows` holds at most a page, the CSV has `row_count`. */
export type ReportTable = {
    key: string;
    title: string;
    description: string | null;
    columns: { key: string; label: string; type: ReportColumnType }[];
    rows: Record<string, ReportCell>[];
    row_count: number;
    totals: Record<string, ReportCell> | null;
};

export type ReportFilter = {
    label: string;
    /** Label of the empty choice; null when one must be chosen. */
    all: string | null;
    options: { value: string | number; label: string }[];
};
