import Echo from 'laravel-echo';
import Pusher from 'pusher-js/react-native';
import { useSyncExternalStore } from 'react';

import { REALTIME_EVENTS, type RealtimeEventName, type RealtimePayload, type RealtimeStatus, type RealtimeTarget } from '@/lib/realtime';

/**
 * The session's private Reverb channel, one connection for the whole app
 * (as the web keeps one per tab). Screens and hooks subscribe to its
 * events by topic; `gap` is the app's own topic, fired once the socket
 * is back after a drop, since events broadcast meanwhile are not replayed.
 */

export type ChannelAuth = { auth: string; channel_data?: string; shared_secret?: string };

/** Signs the socket into the channel: `POST broadcasting/auth` with the bearer token. */
export type ChannelAuthorizer = (socketId: string, channelName: string) => Promise<ChannelAuth>;

/** One live connection to the channel: the Echo wiring, or a test's fake. */
export type RealtimeConnection = {
  listen(event: string, handler: (payload: RealtimePayload) => void): void;
  onStatus(handler: (status: RealtimeStatus) => void): void;
  /** Opens the socket again, after the app was in the background. */
  reconnect(): void;
  disconnect(): void;
};

export type ConnectionFactory = (target: RealtimeTarget, authorize: ChannelAuthorizer) => RealtimeConnection;

export type RealtimeTopic = RealtimeEventName | 'gap';

type Handler = (payload: RealtimePayload) => void;

let connection: RealtimeConnection | null = null;
let status: RealtimeStatus = 'off';
let dropped = false;
const statusListeners = new Set<() => void>();
const handlers = new Map<RealtimeTopic, Set<Handler>>();

function setStatus(next: RealtimeStatus): void {
  if (next === status) {
    return;
  }

  status = next;
  statusListeners.forEach((listener) => listener());
}

function emit(topic: RealtimeTopic, payload: RealtimePayload): void {
  handlers.get(topic)?.forEach((handler) => {
    try {
      handler(payload);
    } catch {
      // One screen's handler must not stop the others.
    }
  });
}

/** Connects to the session's channel, replacing any earlier connection. */
export function connectRealtime(target: RealtimeTarget, authorize: ChannelAuthorizer, factory: ConnectionFactory = echoConnection): void {
  disconnectRealtime();

  const next = factory(target, authorize);
  connection = next;
  dropped = false;
  setStatus('connecting');

  (Object.keys(REALTIME_EVENTS) as RealtimeEventName[]).forEach((topic) => {
    next.listen(REALTIME_EVENTS[topic], (payload) => {
      if (connection === next) {
        emit(topic, payload);
      }
    });
  });

  next.onStatus((reported) => {
    if (connection !== next) {
      return;
    }

    if (reported === 'connected') {
      const wasDropped = dropped;
      dropped = false;
      setStatus('connected');

      if (wasDropped) {
        emit('gap', {});
      }

      return;
    }

    if (status === 'connected' || reported === 'unavailable') {
      dropped = true;
    }

    setStatus(reported);
  });
}

export function disconnectRealtime(): void {
  connection?.disconnect();
  connection = null;
  dropped = false;
  setStatus('off');
}

/** The app is in the foreground again: a socket the system closed meanwhile is opened again. */
export function resumeRealtime(): void {
  if (connection !== null && status !== 'connected') {
    connection.reconnect();
  }
}

/** Calls `handler` with every event of `topic` until the returned function is called. */
export function onRealtime(topic: RealtimeTopic, handler: Handler): () => void {
  let set = handlers.get(topic);

  if (set === undefined) {
    set = new Set();
    handlers.set(topic, set);
  }

  set.add(handler);

  return () => {
    set.delete(handler);
  };
}

export function getRealtimeStatus(): RealtimeStatus {
  return status;
}

function subscribeStatus(listener: () => void): () => void {
  statusListeners.add(listener);

  return () => {
    statusListeners.delete(listener);
  };
}

export function useRealtimeStatus(): RealtimeStatus {
  return useSyncExternalStore(subscribeStatus, getRealtimeStatus);
}

/**
 * Laravel Echo over pusher-js's React Native build. The channel is signed
 * through `authorize` (the API client, so the bearer token and a re-login
 * on 401 come with it); "connected" is only reported once the private
 * channel is joined, as on the web.
 */
function echoConnection(target: RealtimeTarget, authorize: ChannelAuthorizer): RealtimeConnection {
  const { key, host, port, scheme } = target.config;
  const statusHandlers: ((status: RealtimeStatus) => void)[] = [];
  const report = (reported: RealtimeStatus) => statusHandlers.forEach((handler) => handler(reported));

  const echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    Pusher,
    channelAuthorization: {
      transport: 'ajax',
      endpoint: '',
      customHandler: ({ socketId, channelName }: { socketId: string; channelName: string }, callback: (error: Error | null, auth: ChannelAuth | null) => void) => {
        authorize(socketId, channelName).then(
          (auth) => callback(null, auth),
          (error: unknown) => callback(error instanceof Error ? error : new Error(String(error)), null),
        );
      },
    },
  });

  const channel = echo.private(target.channel);
  channel.subscribed(() => report('connected')).error(() => report('unavailable'));

  const pusher = echo.connector.pusher;
  pusher.connection.bind('state_change', ({ current }: { current: string }) => {
    if (current === 'connecting') {
      report('connecting');
    } else if (current === 'unavailable' || current === 'failed') {
      report('unavailable');
    }
  });

  return {
    listen: (event, handler) => {
      channel.listen(event, handler);
    },
    onStatus: (handler) => {
      statusHandlers.push(handler);
    },
    reconnect: () => {
      if (pusher.connection.state !== 'connected') {
        pusher.connect();
      }
    },
    disconnect: () => echo.disconnect(),
  };
}
