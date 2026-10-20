export type NetworkModel = 'bridged' | 'routed' | 'nat';
export type NodeStatus = 'online' | 'offline' | 'unknown';

export type LocationOption = { uuid: string; name: string };

export type Location = LocationOption & {
    code: string;
    country_code: string | null;
    description: string | null;
    /** Names servers ordered without a hostname (docs/adr/0031). */
    hostname_template: string | null;
    clusters_count: number;
    ip_pools_count: number;
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
    last_seen_at: string | null;
    storages: Storage[];
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
    | 'hostname_changed';

export type GuestScriptSlot = {
    event: GuestScriptEvent;
    content: string | null;
    /** Saved but not published: runs only in trials (docs/adr/0023). */
    draft: string | null;
    default: string | null;
    inputs: string[];
    can_be_tried: boolean;
    critical: boolean;
};

/** One run of a guest script, as the Scripts page lists it. */
export type GuestScriptRun = {
    uuid: string;
    server: string;
    server_uuid: string;
    source: 'default' | 'published' | 'draft';
    trace: boolean;
    /** An administrator's trial; otherwise an operation ran it. */
    trial: boolean;
    status: 'running' | 'succeeded' | 'failed';
    exit_code: number | null;
    message: string | null;
    started_at: string | null;
    finished_at: string | null;
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

export type Package = {
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
    /** Empty: any node; otherwise the groups of the ordered location (docs/adr/0020). */
    placement_group_ids: number[];
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
    | 'change_hostname'
    | 'install_app'
    | 'app_run'
    | 'verify'
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
    | 'unmanaged_vm';

/** A difference between the panel and Proxmox (ADR 0005). */
export type Drift = {
    uuid: string;
    type: DriftType;
    vmid: number;
    expected: Record<string, string | number | null> | null;
    actual: Record<string, string | number | null> | null;
    first_seen_at: string;
    last_seen_at: string;
    cluster: { uuid: string; name: string };
    server: { uuid: string; hostname: string; status: ServerStatus } | null;
};

/** Resources as the ResourcesDialog edits them. */
export type ResourceValues = {
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
export type PackageOption = Option & {
    network_models: NetworkModel[];
    placement_groups: string[];
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
};
