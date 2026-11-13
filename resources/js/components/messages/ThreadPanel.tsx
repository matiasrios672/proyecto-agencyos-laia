import axios from 'axios';
import { Loader2, Lock, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConversationView } from './ConversationView';
import { apiError } from './useMessaging';

interface Props {
    /** Tipo de sujeto del hilo (por ahora solo "client"). */
    subjectType: 'client';
    subjectId: number;
    className?: string;
}

/**
 * Hilo de conversación de un cliente, reutilizable en su ficha (pestaña "Conversación").
 * Lo ven y escriben quienes tienen acceso a ese cliente: equipo asignado, Dirección y sysadmin.
 * Las @menciones solo proponen a esas personas y les llegan como notificación.
 */
export function ThreadPanel({ subjectType, subjectId, className }: Props) {
    const [conversationId, setConversationId] = useState<number | null>(null);
    const [error, setError] = useState<{ message: string; forbidden: boolean } | null>(null);
    const [loading, setLoading] = useState(true);

    const open = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const { data } = await axios.post<{ conversation: { id: number } }>('/mensajes/api/hilos', { subject_type: subjectType, subject_id: subjectId });
            setConversationId(data.conversation.id);
        } catch (e) {
            const forbidden = axios.isAxiosError(e) && e.response?.status === 403;
            setError({ forbidden, message: forbidden ? 'No tenés acceso a la conversación de este cliente.' : apiError(e, 'No se pudo abrir la conversación.') });
        } finally {
            setLoading(false);
        }
    }, [subjectType, subjectId]);

    useEffect(() => {
        void open();
    }, [open]);

    return (
        <Card className={className ?? 'flex h-[34rem] flex-col overflow-hidden'}>
            {loading && (
                <div className="grid flex-1 place-items-center text-muted-foreground" aria-busy="true">
                    <Loader2 className="size-5 animate-spin" aria-label="Abriendo conversación" />
                </div>
            )}
            {error && (
                <div role="alert" className="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center">
                    {error.forbidden && <Lock className="size-6 text-muted-foreground" />}
                    <p className="text-sm text-muted-foreground">{error.message}</p>
                    {!error.forbidden && (
                        <Button variant="outline" size="sm" onClick={() => void open()}>
                            <RefreshCw /> Reintentar
                        </Button>
                    )}
                </div>
            )}
            {conversationId !== null && <ConversationView conversationId={conversationId} embedded className="flex-1" />}
        </Card>
    );
}
