import type { ModuleAnswer } from '@/types';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Sends an answer for the progress. If that fails (offline, limit),
 * the exercise simply goes on: the progress is nice, but not important enough for an error message.
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
