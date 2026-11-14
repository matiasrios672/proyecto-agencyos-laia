export interface ChatUser {
    id: number;
    name: string;
    job_title: string | null;
}

export interface MessageDto {
    id: number;
    conversation_id: number;
    user: { id: number; name: string };
    /** null cuando el mensaje fue eliminado (el servidor no entrega su contenido). */
    body: string | null;
    deleted: boolean;
    mentions: { id: number; name: string }[];
    edited: boolean;
    created_at: string;
}

export interface ConversationDto {
    id: number;
    type: 'direct' | 'thread';
    title: string;
    subject: { type: string; id: number; name: string; url: string } | null;
    other: { id: number; name: string; job_title: string | null; active: boolean; read_id: number | null } | null;
    last: { preview: string; author: string | null; mine: boolean; at: string } | null;
    last_message_at: string | null;
    unread: number;
}

export const MAX_MESSAGE_LENGTH = 4000;
