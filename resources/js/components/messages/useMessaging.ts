import { usePage } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useEchoChannel, useRealtimeStatus } from '@/lib/echo';
import type { SharedProps } from '@/types';
import type { ChatUser, ConversationDto, MessageDto } from './types';

const API = '/mensajes/api';

/** Mensaje de error legible para mostrar al usuario a partir de una respuesta de la API. */
export function apiError(e: unknown, fallback = 'Algo salió mal. Probá de nuevo.'): string {
    if (!isAxiosError(e)) return fallback;
    if (e.response?.status === 429) return 'Estás enviando muy rápido. Esperá un momento y probá de nuevo.';
    if (e.response?.status === 403) return 'Ya no tenés acceso a esta conversación.';
    if (e.response?.status === 404) return 'Esto ya no existe.';
    const errors = e.response?.data?.errors as Record<string, string[]> | undefined;
    return errors ? (Object.values(errors)[0]?.[0] ?? fallback) : (e.response?.data?.message ?? fallback);
}

function upsert(list: MessageDto[], message: MessageDto): MessageDto[] {
    const i = list.findIndex((m) => m.id === message.id);
    if (i >= 0) {
        const next = [...list];
        next[i] = message;
        return next;
    }
    return [...list, message].sort((a, b) => a.id - b.id);
}

/* ─────────────────────────── Bandeja ─────────────────────────── */

/**
 * Lista de conversaciones con no leídos. Se actualiza al instante con el tiempo real (pista `inbox.updated`)
 * y, como respaldo, por consulta periódica: cada 30 s si no hay WebSocket y cada 2 min si lo hay.
 */
export function useConversations(enabled = true) {
    const me = usePage<SharedProps>().props.auth!.user.id;
    const status = useRealtimeStatus();
    const [conversations, setConversations] = useState<ConversationDto[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    const refresh = useCallback(async () => {
        try {
            const { data } = await axios.get<{ conversations: ConversationDto[] }>(`${API}/conversaciones`);
            setConversations(data.conversations);
            setError(null);
        } catch (e) {
            setError(apiError(e, 'No se pudieron cargar las conversaciones.'));
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (enabled) void refresh();
    }, [enabled, refresh]);

    // Varias pistas seguidas (ráfaga de mensajes) se agrupan en una sola consulta.
    useEchoChannel(enabled ? `App.Models.User.${me}` : null, {
        '.inbox.updated': () => {
            clearTimeout(timer.current);
            timer.current = setTimeout(() => void refresh(), 250);
        },
    });

    useEffect(() => {
        if (!enabled) return;
        const every = status === 'connected' ? 120_000 : 30_000;
        const interval = setInterval(() => document.visibilityState === 'visible' && void refresh(), every);
        const onFocus = () => void refresh();
        window.addEventListener('focus', onFocus);
        return () => {
            clearInterval(interval);
            window.removeEventListener('focus', onFocus);
            clearTimeout(timer.current);
        };
    }, [enabled, status, refresh]);

    const unread = conversations.reduce((sum, c) => sum + c.unread, 0);

    return { conversations, unread, loading, error, refresh, setConversations };
}

/* ─────────────────────────── Conversación ─────────────────────────── */

interface ConversationState {
    loading: boolean;
    error: string | null;
    conversation: ConversationDto | null;
    messages: MessageDto[];
    hasMore: boolean;
    mentionables: ChatUser[];
}

const EMPTY: ConversationState = { loading: true, error: null, conversation: null, messages: [], hasMore: false, mentionables: [] };

interface ShowResponse {
    conversation: ConversationDto;
    messages: MessageDto[];
    has_more: boolean;
    mentionables: ChatUser[];
}

/**
 * Mensajes de una conversación con tiempo real (canal privado `conversations.{id}`).
 * Sin WebSocket, consulta los últimos mensajes cada 10 s mientras la pestaña está visible.
 */
export function useConversation(id: number | null, onRead?: () => void) {
    const status = useRealtimeStatus();
    const [state, setState] = useState<ConversationState>(EMPTY);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const readTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const onReadRef = useRef(onRead);
    onReadRef.current = onRead;

    const load = useCallback(async () => {
        if (id === null) return;
        setState((s) => ({ ...s, loading: true, error: null }));
        try {
            const { data } = await axios.get<ShowResponse>(`${API}/conversaciones/${id}`);
            setState({ loading: false, error: null, conversation: data.conversation, messages: data.messages, hasMore: data.has_more, mentionables: data.mentionables });
        } catch (e) {
            setState({ ...EMPTY, loading: false, error: apiError(e, 'No se pudo cargar la conversación.') });
        }
    }, [id]);

    useEffect(() => {
        setState(EMPTY);
        void load();
    }, [load]);

    useEchoChannel(id !== null ? `conversations.${id}` : null, {
        '.message.sent': (d) => setState((s) => ({ ...s, messages: upsert(s.messages, (d as { message: MessageDto }).message) })),
        '.message.updated': (d) => setState((s) => ({ ...s, messages: upsert(s.messages, (d as { message: MessageDto }).message) })),
        '.message.deleted': (d) =>
            setState((s) => ({
                ...s,
                messages: s.messages.map((m) => (m.id === (d as { id: number }).id ? { ...m, body: null, deleted: true, mentions: [], edited: false } : m)),
            })),
    });

    // Respaldo sin tiempo real: se traen los últimos mensajes cada 10 s (solo con la pestaña visible).
    useEffect(() => {
        if (id === null || status === 'connected') return;
        const interval = setInterval(async () => {
            if (document.visibilityState !== 'visible') return;
            try {
                const { data } = await axios.get<ShowResponse>(`${API}/conversaciones/${id}`);
                setState((s) => ({ ...s, messages: data.messages.reduce(upsert, s.messages) }));
            } catch {
                /* se reintenta en el próximo ciclo */
            }
        }, 10_000);
        return () => clearInterval(interval);
    }, [id, status]);

    const loadOlder = useCallback(async () => {
        if (id === null || loadingOlder || !state.hasMore || state.messages.length === 0) return;
        setLoadingOlder(true);
        try {
            const { data } = await axios.get<ShowResponse>(`${API}/conversaciones/${id}`, { params: { before: state.messages[0].id } });
            setState((s) => ({ ...s, messages: data.messages.reduce(upsert, s.messages), hasMore: data.has_more }));
        } finally {
            setLoadingOlder(false);
        }
    }, [id, loadingOlder, state.hasMore, state.messages]);

    const send = useCallback(
        async (body: string, mentions: number[]) => {
            const { data } = await axios.post<{ message: MessageDto }>(`${API}/conversaciones/${id}/mensajes`, { body, mentions });
            setState((s) => ({ ...s, messages: upsert(s.messages, data.message) }));
        },
        [id],
    );

    const edit = useCallback(async (messageId: number, body: string, mentions: number[]) => {
        const { data } = await axios.put<{ message: MessageDto }>(`${API}/mensajes/${messageId}`, { body, mentions });
        setState((s) => ({ ...s, messages: upsert(s.messages, data.message) }));
    }, []);

    const remove = useCallback(async (messageId: number) => {
        await axios.delete(`${API}/mensajes/${messageId}`);
        setState((s) => ({ ...s, messages: s.messages.map((m) => (m.id === messageId ? { ...m, body: null, deleted: true, mentions: [], edited: false } : m)) }));
    }, []);

    /** Marca como leída (agrupa llamadas seguidas). Solo tiene sentido si la conversación está a la vista. */
    const markRead = useCallback(() => {
        if (id === null) return;
        clearTimeout(readTimer.current);
        readTimer.current = setTimeout(async () => {
            if (document.visibilityState !== 'visible') return;
            try {
                await axios.post(`${API}/conversaciones/${id}/leer`);
                onReadRef.current?.();
            } catch {
                /* no es crítico: se reintenta con el próximo mensaje o al volver a la pestaña */
            }
        }, 400);
    }, [id]);

    useEffect(() => () => clearTimeout(readTimer.current), []);

    return { ...state, loadingOlder, reload: load, loadOlder, send, edit, remove, markRead };
}
