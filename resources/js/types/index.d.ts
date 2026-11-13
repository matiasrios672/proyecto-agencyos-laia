export type Role = 'sysadmin' | 'dueno' | 'empleado';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    job_title: string | null;
    role: Role | null;
    role_label: string | null;
    is_sysadmin: boolean;
}

export interface SharedProps {
    app: { name: string };
    auth: { user: AuthUser; permissions: string[] } | null;
    unread_notifications: number;
    flash: { success: string | null; error: string | null };
    [key: string]: unknown;
}

export type Options = Record<string, string>;

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface ClientRow {
    id: number;
    name: string;
    business_name: string;
    tax_id: string | null;
    email: string | null;
    phone: string | null;
    account_status: string;
    is_active: boolean;
    team_count: number;
}

export interface ClientDetail {
    id: number;
    business_name: string;
    trade_name: string | null;
    name: string;
    tax_id: string | null;
    tax_condition: string | null;
    tax_condition_label: string | null;
    contact_name: string | null;
    email: string | null;
    phone: string | null;
    whatsapp: string | null;
    website: string | null;
    address: string | null;
    city: string | null;
    notes: string | null;
    account_status: string;
    laiatime_instance_id: string | null;
    is_active: boolean;
}

export interface VaultRow {
    id: number;
    category: string;
    category_label: string;
    label: string;
    url: string | null;
    username: string | null;
    has_secret: boolean;
    has_notes: boolean;
}

export interface PersonRef {
    id: number;
    name: string;
    job_title: string | null;
}
