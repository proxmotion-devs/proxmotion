export type NetworkModel = 'bridged' | 'routed' | 'nat';
export type NodeStatus = 'online' | 'offline' | 'unknown';

export type LocationOption = { uuid: string; name: string };

export type Location = LocationOption & {
    code: string;
    country_code: string | null;
    description: string | null;
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
    family: string;
    version: string;
    platform: OsPlatform;
    is_active: boolean;
    sort_order: number;
    proxmox_template_ids: number[];
};

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
    default: string | null;
    inputs: string[];
    can_be_tried: boolean;
    critical: boolean;
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
    | 'change_hostname'
    | 'install_app'
    | 'app_run'
    | PowerAction;

export type PowerAction = 'start' | 'shutdown' | 'stop' | 'reboot';

export type Option<T extends string | number = string> = {
    value: T;
    label: string;
};

export type ServerListItem = {
    uuid: string;
    hostname: string;
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

/** Choices for ReinstallDialog: OS templates of the server's cluster. */
export type ReinstallOptions = {
    osTemplates: Option[];
};

/** What TaskList needs. */
export type TaskProgress = {
    uuid: string;
    type: TaskType;
    status: TaskStatus;
    steps: { key: string; status: string; message?: string | null }[];
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
    | 'removed';

export type AppRunStatus = 'queued' | 'running' | 'succeeded' | 'failed';

/** An uninstall or action of an installed app (docs/adr/0016). */
export type AppRun = {
    uuid: string;
    kind: 'uninstall' | 'action';
    action: AppText | null;
    status: AppRunStatus;
    step: string | null;
    error: string | null;
    created_at: string | null;
};

export type AppAction = { key: string; label: AppText; inputs: AppInput[] };

export type AppInstallation = {
    uuid: string;
    app: string;
    version: string;
    status: AppInstallationStatus;
    progress: number;
    step: string | null;
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
    can_uninstall: boolean;
    actions: AppAction[];
    runs: AppRun[];
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
        | 'domain';
    label: AppText | null;
    required: boolean;
    default: string | number | boolean | null;
    options: string[] | null;
    min: number | null;
    max: number | null;
    max_length: number | null;
};

export type AppOption = { slug: string; name: string; inputs: AppInput[] };
