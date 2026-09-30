import type { ModuleAnswer } from '@/types';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Schickt eine Antwort für den Lernstand. Scheitert das (offline, Limit),
 * läuft die Übung einfach weiter: Der Lernstand ist nett, aber nicht wichtig genug für eine Fehlermeldung.
 */
export function saveAnswer(url: string, answer: ModuleAnswer): void {
    void fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        keepalive: true,
        body: JSON.stringify({
            module: answer.module,
            item_id: answer.itemId,
            answer: answer.answer,
        }),
    }).catch(() => {});
}
