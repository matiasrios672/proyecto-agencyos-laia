import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { useEffect, useRef, useSyncExternalStore } from 'react';

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

/**
 * Tiempo real (Laravel Reverb). La app NUNCA depende de esto: si no está configurado o el servidor no responde,
 * el estado pasa a "unavailable" y cada pantalla sigue funcionando con consultas periódicas (polling).
 *
 *  - disabled:    faltan las claves VITE_REVERB_* (no se intenta conectar)
 *  - connecting:  conectando / reintentando
 *  - connected:   WebSocket activo
 *  - unavailable: tras varios intentos fallidos se deja de reintentar hasta recargar la página
 *    (así tampoco se llena la consola del navegador de errores de conexión)
 */
export type RealtimeStatus = 'disabled' | 'connecting' | 'connected' | 'unavailable';

const MAX_FAILURES = 3;

let echo: Echo<'reverb'> | null = null;
let status: RealtimeStatus = 'disabled';
let failures = 0;
const listeners = new Set<() => void>();

function setStatus(next: RealtimeStatus) {
    if (next === status) return;
    status = next;
    listeners.forEach((l) => l());
}

export function realtimeConfigured(): boolean {
    return Boolean(import.meta.env.VITE_REVERB_APP_KEY && import.meta.env.VITE_REVERB_HOST);
}

/** Instancia única de Echo (se crea la primera vez que alguien la pide). Devuelve null si no hay tiempo real. */
export function getEcho(): Echo<'reverb'> | null {
    if (echo) return echo;
    if (status === 'unavailable' || !realtimeConfigured() || typeof window === 'undefined') return null;

    window.Pusher = Pusher;
    const secure = (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (secure ? 443 : 80));

    const options = {
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        // La autorización de canales privados usa la sesión y el token XSRF (axios los envía solo).
        authorizer: (channel: { name: string }) => ({
            authorize: (socketId: string, callback: (error: Error | null, data: unknown) => void) => {
                axios
                    .post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
                    .then((r) => callback(null, r.data))
                    .catch((e: Error) => callback(e, null));
            },
        }),
    } as unknown as ConstructorParameters<typeof Echo<'reverb'>>[0];

    setStatus('connecting');
    echo = new Echo<'reverb'>(options);

    const connection = (echo.connector as unknown as { pusher: { connection: { bind: (e: string, cb: (s: { current: string }) => void) => void } } }).pusher.connection;
    connection.bind('state_change', ({ current }) => {
        if (current === 'connected') {
            failures = 0;
            setStatus('connected');
        } else if (current === 'unavailable' || current === 'failed') {
            failures += 1;
            if (failures >= MAX_FAILURES) {
                echo?.disconnect();
                echo = null;
                setStatus('unavailable');
            } else {
                setStatus('connecting');
            }
        } else if (current === 'connecting') {
            setStatus('connecting');
        }
    });

    return echo;
}

export function useRealtimeStatus(): RealtimeStatus {
    return useSyncExternalStore(
        (cb) => {
            listeners.add(cb);
            return () => listeners.delete(cb);
        },
        () => status,
        () => 'disabled' as RealtimeStatus,
    );
}

/** Cuántos componentes escuchan cada canal: solo se abandona cuando ya no queda ninguno. */
const subscribers = new Map<string, number>();

type Handler = (payload: unknown) => void;

/**
 * Escucha eventos de un canal privado mientras el componente esté montado.
 * `name` null = no escuchar. Los eventos propios van con punto (".message.sent"); las notificaciones de Laravel
 * con ".Illuminate\\Notifications\\Events\\BroadcastNotificationCreated".
 */
export function useEchoChannel(name: string | null, handlers: Record<string, Handler>) {
    const latest = useRef(handlers);
    latest.current = handlers;
    const events = Object.keys(handlers).join('|');

    useEffect(() => {
        if (!name) return;
        const instance = getEcho();
        if (!instance) return;

        const channel = instance.private(name);
        subscribers.set(name, (subscribers.get(name) ?? 0) + 1);
        const wrappers = events.split('|').filter(Boolean).map((event) => {
            const fn: Handler = (payload) => latest.current[event]?.(payload);
            channel.listen(event, fn);
            return [event, fn] as const;
        });

        return () => {
            wrappers.forEach(([event, fn]) => channel.stopListening(event, fn));
            const left = (subscribers.get(name) ?? 1) - 1;
            if (left <= 0) {
                subscribers.delete(name);
                instance.leave(name);
            } else {
                subscribers.set(name, left);
            }
        };
    }, [name, events]);
}

export const NOTIFICATION_EVENT = '.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated';
