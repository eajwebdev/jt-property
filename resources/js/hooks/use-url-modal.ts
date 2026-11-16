import { useCallback, useState } from 'react';

/**
 * Open state for a modal that can also be opened by a link: /properties?create=1 opens the create form.
 * Closing removes the query flag so a refresh does not reopen it.
 */
export function useUrlModal(param: string): [boolean, (open: boolean) => void] {
    const [open, setOpen] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get(param) === '1');

    const change = useCallback(
        (next: boolean) => {
            setOpen(next);
            if (!next && typeof window !== 'undefined') {
                const url = new URL(window.location.href);
                if (url.searchParams.has(param)) {
                    url.searchParams.delete(param);
                    window.history.replaceState(window.history.state, '', url.toString());
                }
            }
        },
        [param],
    );

    return [open, change];
}
