export interface ContentGridItem {
    id: number;
    client: { id: number; name: string };
    month: string;
    month_label: string;
    title: string;
    status: string;
    original_name: string;
    size_label: string;
    notes: string | null;
    uploaded_by: string | null;
    approved_at: string | null;
    shared_at: string | null;
    updated_at: string;
    file_url: string;
    download_url: string;
    /** Enlace firmado público (solo para quien puede compartir). */
    share_url: string | null;
    whatsapp_url: string | null;
    can: { manage: boolean };
}

export interface CurrentMonth {
    value: string;
    label: string;
}
