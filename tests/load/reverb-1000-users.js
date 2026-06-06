import ws from 'k6/ws';
import { check, sleep } from 'k6';

export const options = {
    vus: 1000,
    duration: '2m',
    thresholds: {
        checks: ['rate>0.99'],
        ws_connecting: ['p(95)<2000'],
        ws_session_duration: ['p(95)>30000'],
    },
};

export default function () {
    const url = `${__ENV.REVERB_WS_URL}/app/${__ENV.REVERB_APP_KEY}?protocol=7&client=js&version=8.4.0&flash=false`;

    const response = ws.connect(url, {}, function (socket) {
        socket.on('open', () => {
            check(true, { 'connected to reverb': (value) => value === true });
        });

        socket.on('error', () => {
            check(false, { 'no websocket error': (value) => value === true });
        });

        socket.setTimeout(() => socket.close(), 30000);
    });

    check(response, {
        'websocket status is 101': (r) => r && r.status === 101,
    });

    sleep(1);
}
