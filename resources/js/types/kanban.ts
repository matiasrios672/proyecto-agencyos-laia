export type Priority = 'baja' | 'media' | 'alta';

export interface KanbanCardData {
    id: number;
    column_id: number;
    title: string;
    description: string | null;
    assignee: { id: number; name: string } | null;
    due_date: string | null;
    priority: Priority;
    position: number;
    completed_at: string | null;
    overdue: boolean;
}

export interface KanbanColumnData {
    id: number;
    key: string;
    name: string;
    cards: KanbanCardData[];
}

export interface TeamMember {
    id: number;
    name: string;
    job_title: string | null;
}

export interface MoveResponse {
    cards: { id: number; column_id: number; position: number; completed_at: string | null; overdue: boolean }[];
}
